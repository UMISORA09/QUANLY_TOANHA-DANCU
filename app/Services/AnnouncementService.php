<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

class AnnouncementService
{
    public const CATEGORIES = ['GENERAL' => 'Thông báo chung', 'EMERGENCY' => 'Khẩn cấp', 'MAINTENANCE' => 'Bảo trì', 'FEES' => 'Phí và thanh toán', 'COMMUNITY_EVENT' => 'Sự kiện cộng đồng'];

    public function block(User $user, string $permission = 'VIEW'): ?string
    {
        abort_unless($user->status === 'ACTIVE' && ($user->isSuperAdmin() || $user->hasRole('BUILDING_MANAGER')) && $user->hasPermission('ANNOUNCEMENT:VIEW') && $user->hasPermission('ANNOUNCEMENT:'.$permission), 403);
        if ($user->isSuperAdmin()) {
            return null;
        }
        $profile = DB::table('staff_profiles')->where('user_id', $user->id)->first();
        abort_unless($profile, 403, 'Chưa có hồ sơ nhân viên và phạm vi tòa nhà. Vui lòng liên hệ Admin.');

        return $profile->assigned_block_id;
    }

    public function query(User $user): Builder
    {
        $block = $this->block($user);

        return DB::table('announcements')->whereNull('announcements.deleted_at')->when($block, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('target_block_id', $block)->orWhere('target_scope', 'ALL_BLOCKS')));
    }

    public function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(250000);
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title'])->allowLinkSchemes(['http', 'https'])->forceAttribute('a', 'rel', 'noopener noreferrer')->forceAttribute('a', 'target', '_blank');
        foreach (['script', 'style', 'iframe', 'object', 'svg', 'math', 'template'] as $tag) {
            $config = $config->dropElement($tag);
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    public function displayStatus(object $row): string
    {
        if ($row->status !== 'PUBLISHED') {
            return $row->status;
        }
        if ($row->expires_at && Carbon::parse($row->expires_at)->lte(now())) {
            return 'EXPIRED';
        }

        return Carbon::parse($row->publish_at)->gt(now()) ? 'SCHEDULED' : 'PUBLISHED';
    }

    /** @return array<string, mixed> */
    public function serialize(User $user, object $row, bool $detail = true): array
    {
        $block = $this->block($user);
        $editable = ! $block || ($row->target_scope !== 'ALL_BLOCKS' && $row->target_block_id === $block);
        $data = (array) $row;
        foreach (['is_pinned', 'is_urgent', 'requires_acknowledgement'] as $key) {
            $data[$key] = (bool) $data[$key];
        }
        $data['version'] = (int) $row->version;
        $data['display_status'] = $this->displayStatus($row);
        $data['actions'] = ['edit' => $editable && $user->hasPermission('ANNOUNCEMENT:UPDATE'), 'publish' => $editable && $user->hasPermission('ANNOUNCEMENT:PUBLISH'), 'archive' => $editable && $user->hasPermission('ANNOUNCEMENT:DELETE')];
        foreach (['publish_at', 'expires_at', 'created_at', 'updated_at'] as $date) {
            $data[$date] = $row->{$date} ? Carbon::parse($row->{$date})->toIso8601String() : null;
        }
        if ($detail) {
            $data['content_html'] = $this->clean($row->content_html);
            $data['attachments'] = DB::table('announcement_attachments')->where('announcement_id', $row->id)->orderBy('created_at')->orderBy('id')->get(['id', 'file_name', 'mime_type', 'file_size_bytes'])->all();
        } else {
            unset($data['content_html']);
        }

        return $data;
    }

    public function find(User $user, string $id): object
    {
        return $this->query($user)->where('id', $id)->firstOrFail();
    }

    private function mutate(User $user, object $row, string $permission, int $version): void
    {
        $block = $this->block($user, $permission);
        abort_if($block && ($row->target_scope === 'ALL_BLOCKS' || $row->target_block_id !== $block), 403, 'Không được thay đổi thông báo ngoài phạm vi tòa.');
        abort_unless((int) $row->version === $version, 409, 'Bài viết đã thay đổi. Bản nhập được giữ lại; hãy xem bản mới nhất trước khi lưu.');
    }

    /** @param array<string, mixed> $data */
    private function content(User $user, array $data): array
    {
        $block = $this->block($user);
        $all = $data['target_scope'] === 'ALL_BLOCKS';
        if ($block && ($all || ($data['target_block_id'] ?? null) !== $block)) {
            abort(403, 'Chỉ được đăng trong tòa nhà được phân công.');
        }
        if (! $all && ! DB::table('blocks')->where('id', $data['target_block_id'])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['target_block_id' => 'Tòa nhà không hợp lệ.']);
        }
        if ($data['target_scope'] === 'FLOOR' && ! DB::table('floors')->where('id', $data['target_floor_id'])->where('block_id', $data['target_block_id'])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['target_floor_id' => 'Tầng không thuộc tòa nhà đã chọn.']);
        }
        $html = $this->clean($data['content_html']);
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! preg_match('/[^\s\x{00A0}]/u', $plain) || strlen($html) > 60 * 1024) {
            throw ValidationException::withMessages(['content_html' => 'Nội dung phải có văn bản và không vượt quá 60 KB sau khi lọc.']);
        }
        $publish = isset($data['publish_at']) ? Carbon::parse($data['publish_at'])->setTimezone(config('app.timezone')) : now();
        $expires = isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->setTimezone(config('app.timezone')) : null;
        if ($expires && $expires->lte($publish)) {
            throw ValidationException::withMessages(['expires_at' => 'Hạn hiển thị phải sau thời điểm đăng.']);
        }

        return ['title' => $data['title'], 'summary_excerpt' => $data['summary_excerpt'] ?? Str::limit($plain, 500, ''), 'content_html' => $html, 'category' => $data['category'], 'target_scope' => $data['target_scope'], 'target_block_id' => $all ? null : $data['target_block_id'], 'target_floor_id' => $data['target_scope'] === 'FLOOR' ? $data['target_floor_id'] : null, 'is_pinned' => (bool) $data['is_pinned'], 'is_urgent' => (bool) $data['is_urgent'], 'publish_at' => $publish, 'expires_at' => $expires, 'updated_at' => now()];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, ?string $id, array $data): array
    {
        $created = [];
        try {
            $id = DB::transaction(function () use ($actor, $id, $data, &$created): string {
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $this->block($user, $id ? 'UPDATE' : 'CREATE');
                $values = $this->content($user, $data);
                if ($id) {
                    $row = $this->query($user)->where('id', $id)->lockForUpdate()->firstOrFail();
                    $this->mutate($user, $row, 'UPDATE', (int) $data['version']);
                    $version = (int) $row->version + 1;
                    DB::table('announcements')->where('id', $id)->update([...$values, 'version' => $version]);
                } else {
                    $id = $data['id'];
                    abort_if(DB::table('announcements')->where('id', $id)->exists(), 409, 'Mã bài viết đã tồn tại. Hãy tải lại danh sách để mở bài đã lưu.');
                    DB::table('announcements')->insert([...$values, 'id' => $id, 'author_user_id' => $user->id, 'status' => 'DRAFT', 'version' => 1, 'created_at' => now()]);
                }
                $this->files($id, $data, $created, $user);
                $this->audit($user, $id, $id === ($data['id'] ?? null) ? 'INSERT' : 'UPDATE', 'Lưu nội dung bảng tin');

                return $id;
            });
        } catch (Throwable $error) {
            foreach ($created as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            if ($error instanceof UniqueConstraintViolationException) {
                abort(409, 'Mã bài viết đã được sử dụng. Vui lòng tải lại danh sách.');
            }
            throw $error;
        }

        return $this->serialize($actor, $this->find($actor, $id));
    }

    /** @param array<string, mixed> $data
     * @param  array<int, array{string, string}>  $created
     */
    private function files(string $id, array $data, array &$created, User $user): void
    {
        $remove = $data['remove_attachment_ids'] ?? [];
        $query = DB::table('announcement_attachments')->where('announcement_id', $id);
        $removed = (clone $query)->whereIn('id', $remove)->get();
        if ($removed->count() !== count($remove)) {
            throw ValidationException::withMessages(['remove_attachment_ids' => 'Tệp không thuộc bài viết hoặc đã bị bỏ.']);
        }
        $files = $data['attachments'] ?? [];
        $tokens = $data['uploaded_attachment_tokens'] ?? [];
        $handles = [];
        $staged = [];
        try {
            foreach ($tokens as $token) {
                [$file, $handle, $ticket] = app(AnnouncementUploadService::class)->materialize($user, $id, $token);
                $files[] = $file;
                $handles[] = $handle;
                $staged[] = $ticket;
            }
            if ($query->count() - count($remove) + count($files) > 5) {
                throw ValidationException::withMessages(['attachments' => 'Mỗi bài chỉ có tối đa 5 tệp.']);
            }
            if ($files) {
                $disk = $this->disk();
                foreach ($files as $file) {
                    $mime = $file->getMimeType();
                    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
                    $valid = $file->isValid() && $file->getSize() <= 5 * 1024 * 1024 && $extension;
                    $valid = $valid && ($mime === 'application/pdf' ? file_get_contents($file->getRealPath(), false, null, 0, 5) === '%PDF-' : @getimagesize($file->getRealPath()) !== false);
                    if (! $valid) {
                        throw ValidationException::withMessages(['attachments' => 'Chỉ nhận ảnh JPG/PNG/WebP hợp lệ hoặc PDF tối đa 5 MB.']);
                    }
                    $path = 'announcement-attachments/'.$id.'/'.Str::uuid().'.'.$extension;
                    $created[] = [$disk, $path];
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private'])) {
                            throw new \RuntimeException('Không thể lưu tệp bảng tin.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    DB::table('announcement_attachments')->insert(['id' => (string) Str::uuid(), 'announcement_id' => $id, 'file_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255), 'file_url' => $path, 'storage_disk' => $disk, 'mime_type' => $mime, 'file_size_bytes' => $file->getSize(), 'created_at' => now()]);
                }
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
        if ($staged) {
            DB::afterCommit(function () use ($staged): void {
                foreach ($staged as $ticket) {
                    try {
                        Storage::disk($ticket['disk'])->delete($ticket['path']);
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
            });
        }
        if ($remove) {
            (clone $query)->whereIn('id', $remove)->delete();
            DB::afterCommit(function () use ($removed, $id): void {
                foreach ($removed as $file) {
                    if ($this->safeFile($file, $id)) {
                        try {
                            Storage::disk($file->storage_disk)->delete($file->file_url);
                        } catch (Throwable $error) {
                            report($error);
                        }
                    }
                }
            });
        }
    }

    public function disk(): string
    {
        $disk = config('announcements.attachment_disk');
        abort_unless(in_array($disk, ['local', 's3', 'supabase'], true), 503, 'Chưa cấu hình kho tệp riêng tư.');
        abort_if(app()->environment('production') && $disk === 'local', 503, 'Production cần kho tệp S3 riêng tư.');
        if ($disk !== 'local') {
            foreach (['key', 'secret', 'region', 'bucket'] as $key) {
                abort_unless(filled(config('filesystems.disks.'.$disk.'.'.$key)), 503, 'Cấu hình kho tệp chưa đầy đủ.');
            }
            abort_if($disk === 'supabase' && ! config('filesystems.disks.supabase.endpoint'), 503, 'Thiếu endpoint kho tệp.');
        }

        return $disk;
    }

    public function safeFile(object $file, string $id): bool
    {
        return in_array($file->storage_disk, ['local', 's3', 'supabase'], true) && preg_match('~^announcement-attachments/'.preg_quote($id, '~').'/[a-f0-9-]{36}\.(jpg|png|webp|pdf)$~D', $file->file_url) === 1;
    }

    public function action(User $actor, string $id, string $action, array $data): void
    {
        DB::transaction(function () use ($actor, $id, $action, $data): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = $this->query($user)->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->mutate($user, $row, $action === 'archive' ? 'DELETE' : 'PUBLISH', (int) $data['version']);
            $values = ['version' => (int) $row->version + 1, 'updated_at' => now()];
            if ($action === 'publish') {
                abort_unless(in_array($row->status, ['DRAFT', 'UNPUBLISHED'], true), 409, 'Bài đã được đăng hoặc hẹn đăng.');
                $publish = isset($data['publish_at']) ? Carbon::parse($data['publish_at'])->setTimezone(config('app.timezone')) : now();
                if ($row->expires_at && Carbon::parse($row->expires_at)->lte($publish)) {
                    throw ValidationException::withMessages(['expires_at' => 'Hạn hiển thị phải sau thời điểm đăng.']);
                }
                $values += ['status' => 'PUBLISHED', 'publish_at' => $publish];
            } elseif ($action === 'unpublish') {
                abort_unless($row->status === 'PUBLISHED', 409, 'Bài hiện không được đăng.');
                $values['status'] = 'UNPUBLISHED';
            } else {
                $values['deleted_at'] = now();
            }
            DB::table('announcements')->where('id', $id)->update($values);
            $this->audit($user, $id, 'UPDATE', $action.': '.($data['reason'] ?? 'Đăng bảng tin'));
        });
    }

    private function audit(User $user, string $id, string $action, string $note): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'table_name' => 'announcements', 'record_id' => $id, 'action' => $action, 'performed_by_user_id' => $user->id, 'new_data' => json_encode(['note' => $note, 'version' => DB::table('announcements')->where('id', $id)->value('version')], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
