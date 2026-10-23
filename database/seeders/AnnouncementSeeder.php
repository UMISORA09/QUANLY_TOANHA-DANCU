<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $author = User::where('status', 'ACTIVE')->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['SUPER_ADMIN', 'SUPER_ADMI']))->orderBy('id')->first();
        if (! $author) {
            $this->command?->warn('Chưa có Admin hoạt động để tạo bảng tin mẫu.');

            return;
        }
        foreach (['DRAFT', 'PUBLISHED', 'SCHEDULED', 'EXPIRED', 'UNPUBLISHED'] as $index => $status) {
            $id = 'a1010000-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            if (DB::table('announcements')->where('id', $id)->exists()) {
                continue;
            }
            app(AnnouncementService::class)->save($author, null, ['id' => $id, 'title' => '[Mẫu] Bảng tin '.($index + 1), 'content_html' => '<h2>Thông báo bảo trì</h2><p>Kính gửi cư dân, vui lòng theo dõi lịch bảo trì tại tòa nhà.</p>', 'category' => 'MAINTENANCE', 'target_scope' => 'ALL_BLOCKS', 'is_pinned' => false, 'is_urgent' => false, 'publish_at' => $status === 'SCHEDULED' ? now()->addDay()->toIso8601String() : now()->subDays(2)->toIso8601String(), 'expires_at' => $status === 'EXPIRED' ? now()->subDay()->toIso8601String() : null]);
            if (in_array($status, ['PUBLISHED', 'SCHEDULED', 'EXPIRED', 'UNPUBLISHED'], true)) {
                app(AnnouncementService::class)->action($author, $id, 'publish', ['version' => 1, 'publish_at' => $status === 'SCHEDULED' ? now()->addDay()->toIso8601String() : now()->subDays(2)->toIso8601String()]);
            }
            if ($status === 'UNPUBLISHED') {
                app(AnnouncementService::class)->action($author, $id, 'unpublish', ['version' => 2, 'reason' => 'Dữ liệu mẫu bài đã gỡ đăng.']);
            }
        }
    }
}
