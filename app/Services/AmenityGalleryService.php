<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AmenityGalleryService
{
    private const PREFIX = '/api/v1/admin/amenity-images/';

    /** @param array<string, mixed> $data @param array<int, \Illuminate\Http\UploadedFile> $files @return array<string, mixed> */
    public function persist(array $data, array $files, ?string $amenityId, Closure $save): array
    {
        validator(['gallery_files' => $files], ['gallery_files' => 'array|max:5', 'gallery_files.*' => 'file|image|mimetypes:image/jpeg,image/png,image/webp|max:2048'])->validate();
        $old = $amenityId ? json_decode(DB::table('amenities')->where('id', $amenityId)->value('gallery_images') ?? '[]', true) : [];
        $old = is_array($old) ? $old : [];
        $kept = $data['gallery_images'] ?? $old;
        foreach ($kept as $url) {
            if (str_starts_with($url, self::PREFIX) && ! in_array($url, $old, true)) {
                throw ValidationException::withMessages(['gallery_images' => 'Không thể sử dụng ảnh riêng tư của tiện ích khác.']);
            }
        }
        if ($files && (count($kept) + count($files) > 5 || array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 4 * 1024 * 1024)) {
            throw ValidationException::withMessages(['gallery_files' => 'Tối đa 5 ảnh, 2 MB/ảnh và tổng tệp mới không quá 4 MB.']);
        }
        $stored = [];
        try {
            if ($files) {
                $disk = app(TicketAttachmentService::class)->disk();
                foreach ($files as $file) {
                    if (@getimagesize($file->getRealPath()) === false) {
                        throw ValidationException::withMessages(['gallery_files' => 'Nội dung tệp không phải ảnh hợp lệ.']);
                    }
                    $extension = match ($file->getMimeType()) {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
                    };
                    $name = Str::uuid().'.'.$extension;
                    $stored[] = route('amenities.gallery-image', ['disk' => $disk, 'file' => $name], false);
                    if (! $file->storeAs('amenity-gallery', $name, ['disk' => $disk, 'visibility' => 'private'])) {
                        throw new RuntimeException('Không thể lưu ảnh tiện ích.');
                    }
                }
                app()->terminating(fn () => $this->deleteUnreferenced($stored));
            }
            if (array_key_exists('gallery_images', $data) || $files) {
                $data['gallery_images'] = [...$kept, ...$stored];
            }
            $result = $save($data);
            DB::afterCommit(fn () => $this->deleteUnreferenced(array_diff($old, $kept)));

            return $result;
        } catch (Throwable $exception) {
            $this->deleteUnreferenced($stored);
            throw $exception;
        }
    }

    /** @param array<int, string> $urls */
    public function deleteUnreferenced(array $urls): void
    {
        foreach ($urls as $url) {
            if (! preg_match('~^'.preg_quote(self::PREFIX, '~').'(local|s3|supabase)/([a-f0-9-]{36}\.(?:jpg|png|webp))$~D', $url, $parts)) {
                continue;
            }
            try {
                if (! DB::table('amenities')->whereJsonContains('gallery_images', $url)->exists()) {
                    Storage::disk($parts[1])->delete('amenity-gallery/'.$parts[2]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function image(Request $request, string $disk, string $file): StreamedResponse
    {
        abort_unless(in_array($disk, ['local', 's3', 'supabase'], true) && preg_match('~^[a-f0-9-]{36}\.(jpg|png|webp)$~D', $file, $parts), 404);
        $url = self::PREFIX.$disk.'/'.$file;
        $id = DB::table('amenities')->whereNull('deleted_at')->whereJsonContains('gallery_images', $url)->value('id');
        abort_unless($id, 404);
        app(AmenityService::class)->assertCanViewAmenity($request, $id);
        abort_unless(Storage::disk($disk)->exists('amenity-gallery/'.$file), 404);
        $mime = match ($parts[1]) {
            'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'
        };

        return Storage::disk($disk)->response('amenity-gallery/'.$file, null, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
