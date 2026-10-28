<?php

namespace App\Services;

use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuggestionAttachmentService
{
    public function disk(): string
    {
        $disk = config('tickets.attachment_disk', config('filesystems.default', 'local'));
        if (app()->environment('production') && $disk === 'local') {
            // Fall back to s3 or local according to config
            return config('filesystems.disks.s3.bucket') ? 's3' : 'local';
        }

        return in_array($disk, ['local', 's3', 'supabase', 'public'], true) ? $disk : 'local';
    }

    /**
     * Store uploaded attachments for a suggestion.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function storeAttachments(string $suggestionId, array $files): array
    {
        if (count($files) > 5) {
            throw ValidationException::withMessages([
                'attached_files' => ['Chỉ được đính kèm tối đa 5 hình ảnh.'],
            ]);
        }

        $disk = $this->disk();
        $stored = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! $file->isValid()) {
                throw ValidationException::withMessages([
                    'attached_files' => ['Tệp tải lên không hợp lệ hoặc bị lỗi.'],
                ]);
            }

            $mime = $file->getMimeType();
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (! isset($allowedMimes[$mime])) {
                throw ValidationException::withMessages([
                    'attached_files' => ['Chỉ chấp nhận định dạng ảnh JPG, PNG hoặc WebP.'],
                ]);
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'attached_files' => ['Kích thước mỗi ảnh không được vượt quá 5MB.'],
                ]);
            }

            // Verify genuine image content
            if (@getimagesize($file->getRealPath()) === false) {
                throw ValidationException::withMessages([
                    'attached_files' => ['Nội dung tệp không phải là hình ảnh hợp lệ.'],
                ]);
            }

            $fileId = (string) Str::uuid();
            $extension = $allowedMimes[$mime];
            // Safe path isolation: no user input in path
            $storagePath = 'suggestions/'.$suggestionId.'/'.$fileId.'.'.$extension;

            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if (! Storage::disk($disk)->put($storagePath, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Không thể lưu trữ tệp tin đính kèm.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $stored[] = [
                'id' => $fileId,
                'file_name' => pathinfo($file->getClientOriginalName(), PATHINFO_BASENAME),
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'mime_type' => $mime,
                'file_size_bytes' => $file->getSize(),
                'url' => '/api/v1/suggestions/'.$suggestionId.'/attachments/'.$fileId,
            ];
        }

        return $stored;
    }

    /**
     * Authorize and download/stream attachment.
     */
    public function downloadAttachment(
        Suggestion $suggestion,
        string $fileId,
        ?User $user = null,
        ?string $anonToken = null
    ): StreamedResponse {
        $attachments = $suggestion->attached_files ?? [];
        $target = null;
        foreach ($attachments as $att) {
            if (($att['id'] ?? '') === $fileId) {
                $target = $att;
                break;
            }
        }

        abort_unless($target, 404, 'Không tìm thấy tệp đính kèm.');

        // Authorization check:
        $isAuthorized = false;

        // 1. Check anonymous token
        if ($suggestion->is_anonymous && filled($anonToken)) {
            $tokenHash = hash('sha256', $anonToken);
            if (hash_equals((string) $suggestion->anonymous_access_token_hash, $tokenHash)) {
                $isAuthorized = true;
            }
        }

        // 2. Check logged in user
        if (! $isAuthorized && $user) {
            // Identified resident
            if (! $suggestion->is_anonymous && $suggestion->resident_user_id === $user->id) {
                $isAuthorized = true;
            }

            // Manager with permission
            if (! $isAuthorized && app(SuggestionAccessService::class)->isManager($user) && $user->hasPermission('SUGGESTION:VIEW')) {
                $blockId = app(SuggestionAccessService::class)->assignedBlock($user);
                $suggestion->loadMissing('box');
                if (! $blockId || $suggestion->box->block_id === null || $suggestion->box->block_id === $blockId) {
                    $isAuthorized = true;
                }
            }
        }

        abort_unless($isAuthorized, 403, 'Bạn không có quyền truy cập tệp đính kèm này.');

        $disk = $target['storage_disk'] ?? $this->disk();
        $path = $target['storage_path'] ?? '';

        // Prevent path traversal
        if (str_contains($path, '..') || ! str_starts_with($path, 'suggestions/')) {
            abort(400, 'Đường dẫn tệp không hợp lệ.');
        }

        abort_unless(Storage::disk($disk)->exists($path), 404, 'Tệp tin không còn tồn tại trên kho lưu trữ.');

        return Storage::disk($disk)->response(
            $path,
            $target['file_name'] ?? 'attachment',
            [
                'Content-Type' => $target['mime_type'] ?? 'image/jpeg',
                'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            ]
        );
    }
}
