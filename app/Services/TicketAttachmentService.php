<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketAttachmentService
{
    public function disk(): string
    {
        $disk = config('tickets.attachment_disk');
        abort_unless(in_array($disk, ['local', 's3'], true), 503, 'Chưa cấu hình kho ảnh riêng tư.');
        abort_if(app()->environment('production') && $disk !== 's3', 503, 'Production cần kho ảnh riêng tư S3.');
        abort_if($disk === 's3' && ! config('filesystems.disks.s3.bucket'), 503, 'Chưa cấu hình bucket ảnh sự cố.');

        return $disk;
    }

    /** @param array<int, UploadedFile> $files */
    public function store(Ticket $ticket, string $userId, array $files, array &$created, bool $proof = false): void
    {
        if (count($files) + $ticket->attachments()->count() > 5) {
            throw ValidationException::withMessages(['attachments' => 'Mỗi yêu cầu chỉ được có tối đa 5 ảnh.']);
        }
        $disk = $this->disk();
        foreach ($files as $file) {
            $mime = $file->getMimeType();
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (! $file->isValid() || $file->getSize() > 5 * 1024 * 1024 || ! isset($extensions[$mime]) || @getimagesize($file->getRealPath()) === false) {
                throw ValidationException::withMessages(['attachments' => 'Ảnh không hợp lệ; chỉ nhận JPG, PNG, WebP tối đa 5MB.']);
            }
            $path = 'tickets/'.$ticket->id.'/'.Str::uuid().'.'.$extensions[$mime];
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Không thể lưu ảnh sự cố.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $created[] = ['disk' => $disk, 'path' => $path];
            TicketAttachment::create([
                'ticket_id' => $ticket->id, 'file_name' => $file->getClientOriginalName(),
                'file_url' => '', 'storage_path' => $path, 'storage_disk' => $disk,
                'file_type' => 'IMAGE', 'mime_type' => $mime, 'file_size_bytes' => $file->getSize(),
                'is_resolution_proof' => $proof, 'uploaded_by_user_id' => $userId,
            ]);
        }
    }

    /** @param array<int, array{disk: string, path: string}> $created */
    public function cleanup(array $created): void
    {
        foreach ($created as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
