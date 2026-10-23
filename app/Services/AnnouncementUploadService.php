<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnnouncementUploadService
{
    public function prepare(User $user, array $data): array
    {
        $announcements = app(AnnouncementService::class);
        $exists = DB::table('announcements')->where('id', $data['announcement_id'])->exists();
        $block = $announcements->block($user, $exists ? 'UPDATE' : 'CREATE');
        if ($exists) {
            $row = $announcements->find($user, $data['announcement_id']);
            abort_if($block && ($row->target_scope === 'ALL_BLOCKS' || $row->target_block_id !== $block), 403);
        }
        $disk = $announcements->disk();
        $expires = now()->addMinutes(10)->timestamp;
        $path = 'announcement-staging/'.$expires.'/'.$user->id.'/'.Str::uuid();
        $ticket = ['user_id' => $user->id, 'announcement_id' => $data['announcement_id'], 'disk' => $disk, 'path' => $path, 'expires' => $expires, 'file_name' => $data['file_name'], 'size' => $data['size'], 'mime_type' => $data['mime_type']];
        $token = strtr(Crypt::encryptString(json_encode($ticket, JSON_THROW_ON_ERROR)), '+/', '-_');
        if ($disk === 'local') {
            return ['token' => $token, 'transport' => 'local', 'url' => '/api/v1/admin/announcements/uploads/'.rawurlencode($token), 'headers' => ['Content-Type' => 'application/octet-stream'], 'expires_at' => $expires];
        }
        $signed = Storage::disk($disk)->temporaryUploadUrl($path, now()->addMinutes(10), ['ContentType' => $data['mime_type'], 'ContentLength' => $data['size']]);
        abort_unless(str_starts_with($signed['url'], 'https://'), 503, 'Kho tệp phải sử dụng HTTPS.');
        $headers = [];
        foreach ($signed['headers'] as $key => $value) {
            if (! in_array(strtolower($key), ['host', 'content-length'], true)) {
                $headers[$key] = implode(', ', (array) $value);
            }
        }

        return ['token' => $token, 'transport' => 's3', 'url' => $signed['url'], 'headers' => $headers, 'expires_at' => $expires];
    }

    public function decode(User $user, string $token, ?string $announcementId = null): array
    {
        try {
            $data = json_decode(Crypt::decryptString(strtr($token, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['user_id'] ?? null) !== $user->id || ($announcementId && ($data['announcement_id'] ?? null) !== $announcementId) || ($data['expires'] ?? 0) <= now()->timestamp || ! in_array($data['disk'] ?? null, ['local', 's3', 'supabase'], true) || ! preg_match('~^announcement-staging/[0-9]{10}/'.preg_quote($user->id, '~').'/[a-f0-9-]{36}$~D', $data['path'] ?? '')) {
                throw new \RuntimeException;
            }

            return $data;
        } catch (Throwable) {
            throw ValidationException::withMessages(['uploaded_attachment_tokens' => 'Phiên upload không hợp lệ hoặc đã hết hạn. Hãy chọn lại tệp.']);
        }
    }

    /** @param resource $input */
    public function local(User $user, string $token, mixed $input): void
    {
        $data = $this->decode($user, $token);
        app(AnnouncementService::class)->block($user);
        abort_unless($data['disk'] === 'local' && app(AnnouncementService::class)->disk() === 'local', 403);
        $temp = tmpfile();
        try {
            $size = stream_copy_to_stream($input, $temp, 5 * 1024 * 1024 + 1);
            if ($size !== (int) $data['size'] || $size > 5 * 1024 * 1024) {
                throw ValidationException::withMessages(['attachments' => 'Dung lượng upload không khớp hoặc vượt 5 MB.']);
            }
            rewind($temp);
            if (! Storage::disk('local')->put($data['path'], $temp, ['visibility' => 'private'])) {
                throw new \RuntimeException('Không thể lưu upload tạm.');
            }
        } catch (Throwable $error) {
            try {
                Storage::disk('local')->delete($data['path']);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }
            throw $error;
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }
    }

    /** @return array{0: UploadedFile, 1: resource, 2: array} */
    public function materialize(User $user, string $id, string $token): array
    {
        $data = $this->decode($user, $token, $id);
        abort_unless($data['disk'] === app(AnnouncementService::class)->disk(), 409, 'Kho tệp đã thay đổi. Vui lòng upload lại.');
        $disk = Storage::disk($data['disk']);
        if (! $disk->exists($data['path']) || $disk->size($data['path']) !== (int) $data['size']) {
            throw ValidationException::withMessages(['attachments' => 'Tệp upload chưa hoàn tất hoặc sai dung lượng.']);
        }
        $input = $disk->readStream($data['path']);
        $temp = tmpfile();
        try {
            $size = stream_copy_to_stream($input, $temp, 5 * 1024 * 1024 + 1);
            if ($size !== (int) $data['size'] || $size > 5 * 1024 * 1024) {
                throw ValidationException::withMessages(['attachments' => 'Tệp upload vượt giới hạn hoặc không khớp dung lượng.']);
            }
            $file = new UploadedFile(stream_get_meta_data($temp)['uri'], $data['file_name'], null, null, true);
            if ($file->getMimeType() !== $data['mime_type']) {
                throw ValidationException::withMessages(['attachments' => 'Nội dung MIME không khớp tệp đã đăng ký.']);
            }

            return [$file, $temp, $data];
        } catch (Throwable $error) {
            fclose($temp);
            throw $error;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    public function discard(User $user, string $token): void
    {
        $data = $this->decode($user, $token);
        Storage::disk($data['disk'])->delete($data['path']);
    }
}
