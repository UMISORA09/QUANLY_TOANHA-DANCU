<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnouncementMassSeeder extends Seeder
{
    /**
     * Seed approximately 500 announcements with read receipts (F10 - F11).
     */
    public function run(): void
    {
        $targetCount = 500;
        $currentCount = DB::table('announcements')->count();

        if ($currentCount >= $targetCount) {
            $this->command?->info("announcements đã có {$currentCount} bản ghi (>= {$targetCount}), bỏ qua tạo thêm.");

            return;
        }

        $needed = $targetCount - $currentCount;
        $this->command?->info("Đang tạo thêm {$needed} bản ghi bảng tin & thông báo (announcements)...");

        $adminUsers = DB::table('users')
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->all();

        if (empty($adminUsers)) {
            $this->command?->error('Không tìm thấy tài khoản admin hoạt động trong bảng users.');

            return;
        }

        $blocks = DB::table('blocks')->get();
        $floors = DB::table('floors')->get();

        $residents = array_values(array_unique(DB::table('residents')->pluck('user_id')->all()));
        if (empty($residents)) {
            $residents = array_values(array_unique($adminUsers));
        }

        $topicsByCategory = [
            'MAINTENANCE' => [
                'Bảo dưỡng và kiểm tra định kỳ hệ thống thang máy',
                'Tạm ngưng cấp nước súc xả khử trùng bể ngầm sinh hoạt',
                'Vệ sinh bề mặt kính và mặt dựng bên ngoài tòa nhà',
                'Bảo trì định kỳ hệ thống trạm biến áp và máy phát điện',
                'Kiểm tra định kỳ áp lực hệ thống chữa cháy tự động Sprinkler',
                'Sơn lại vạch kẻ đường và bảo dưỡng sơn sàn tầng hầm B1, B2',
                'Bảo dưỡng hệ thống thông gió tăng áp buồng thang thoát hiểm',
                'Nâng cấp và bảo trì hệ thống hạ tầng cáp quang internet',
                'Xử lý chống thấm và nạo vét rãnh thoát nước mái sân thượng',
                'Kiểm tra và thay thế đèn chiếu sáng hành lang các tầng',
            ],
            'FEES' => [
                'Thông báo phát hành bảng kê phí quản lý vận hành kỳ mới',
                'Nhắc lịch quyết toán kinh phí bảo trì phần sở hữu chung',
                'Hướng dẫn thanh toán phí dịch vụ tự động qua cổng VietQR',
                'Thông báo mức phí trông giữ phương tiện ô tô và xe máy mới',
                'Công khai báo cáo tài chính thu chi vận hành tòa nhà Quý này',
                'Hạn cuối nộp tiền điện chiếu sáng chung và nước sinh hoạt',
                'Thông báo chính sách ưu đãi khi đóng trước phí quản lý cả năm',
            ],
            'COMMUNITY_EVENT' => [
                'Ngày hội Chủ nhật Xanh - Toàn dân vệ sinh môi trường nội khu',
                'Giải giao lưu Pickleball & Tennis mở rộng hè 2026',
                'Đêm hội Trăng Rằm - Trung Thu sum vầy cho thiếu nhi',
                'Ngày hội Đổi rác tái chế lấy sen đá và cây cảnh mini',
                'Tập huấn diễn tập kỹ năng thoát hiểm và PCCC cho cư dân',
                'Hội thảo Sống xanh - Tiết kiệm năng lượng trong căn hộ',
                'Lễ hội Giáng sinh và Chào năm mới tại quảng trường trung tâm',
                'Giao lưu cờ vua và hoạt động văn hóa cho người cao tuổi',
            ],
            'EMERGENCY' => [
                'Cảnh báo bão giông lốc giật mạnh và hướng dẫn chằng chống cửa sổ',
                'Cảnh báo nguy cơ ngập cục bộ tại khu vực dốc hầm B2 khi mưa lớn',
                'Khắc phục khẩn cấp sự cố bục mối nối đường ống nước trục kỹ thuật',
                'Cảnh giác thủ đoạn đối tượng lạ mạo danh nhân viên bưu điện lừa đảo',
                'Nghiêm cấm sạc xe điện bằng nguồn điện tự chế không qua kiểm định',
            ],
            'GENERAL' => [
                'Phổ biến Quy chế quản lý và sử dụng Nhà chung cư sửa đổi',
                'Quy định về việc nuôi, giữ và dắt thú cưng tại khuôn viên chung',
                'Hướng dẫn đăng ký cấp mới và cấp đổi thẻ từ cư dân thông minh',
                'Lịch hoạt động của các tiện ích nội khu trong dịp nghỉ lễ',
                'Thông báo về việc tuân thủ tốc độ và phân luồng giao thông nội bộ',
                'Lấy ý kiến đóng góp về phương án lắp thêm trạm sạc xe điện',
                'Lịch tiếp cư dân định kỳ hàng tuần của Ban Quản lý tòa nhà',
                'Thông báo đăng ký sử dụng phòng sinh hoạt cộng đồng họp ban đại diện',
            ],
        ];

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $announcementsBatch = [];
        $readsBatch = [];
        $batchSize = 100;

        $categories = ['MAINTENANCE', 'FEES', 'COMMUNITY_EVENT', 'EMERGENCY', 'GENERAL'];
        $categoriesWeights = [30, 20, 20, 10, 20]; // Tỉ lệ %

        for ($i = 0; $i < $needed; $i++) {
            $announcementId = (string) Str::uuid();
            $authorId = $adminUsers[array_rand($adminUsers)];

            // Chọn category theo tỉ lệ
            $catRand = rand(1, 100);
            if ($catRand <= 30) {
                $category = 'MAINTENANCE';
            } elseif ($catRand <= 50) {
                $category = 'FEES';
            } elseif ($catRand <= 70) {
                $category = 'COMMUNITY_EVENT';
            } elseif ($catRand <= 80) {
                $category = 'EMERGENCY';
            } else {
                $category = 'GENERAL';
            }

            $topicList = $topicsByCategory[$category];
            $baseTopic = $topicList[array_rand($topicList)];

            // Phạm vi gửi thông báo
            $scopeRand = rand(1, 100);
            if ($scopeRand <= 65 || $blocks->isEmpty()) {
                $targetScope = 'ALL_BLOCKS';
                $targetBlockId = null;
                $targetFloorId = null;
                $scopeSuffix = 'Toàn Cư Dân';
            } elseif ($scopeRand <= 90) {
                $targetScope = 'BLOCK_SPECIFIC';
                $block = $blocks->random();
                $targetBlockId = $block->id;
                $targetFloorId = null;
                $scopeSuffix = 'Tháp '.$block->block_name;
            } else {
                $targetScope = 'FLOOR_SPECIFIC';
                $floor = $floors->random();
                $targetBlockId = $floor->block_id;
                $targetFloorId = $floor->id;
                $scopeSuffix = 'Tầng '.$floor->floor_number;
            }

            // Tiêu đề & trích dẫn
            $title = "[Thông báo] {$baseTopic} ({$scopeSuffix}) #".($currentCount + $i + 1);
            $excerpt = "Ban Quản lý xin thông báo tới Quý cư dân về kế hoạch {$baseTopic} tại {$scopeSuffix}. Vui lòng xem chi tiết.";

            // Nội dung HTML phong phú
            $contentHtml = '<h3>THÔNG BÁO TỪ BAN QUẢN LÝ TÒA NHÀ</h3>'
                ."<p><strong>Kính gửi:</strong> Quý Cư dân sinh sống tại {$scopeSuffix},</p>"
                .'<p>Nhằm đảm bảo an toàn kỹ thuật, duy trì chất lượng tiện ích và nâng cao chất lượng môi trường sống, Ban Quản lý xin trân trọng thông báo:</p>'
                ."<div style='background: #f8fafc; padding: 12px; border-left: 4px solid #3b82f6; margin: 12px 0;'>"
                ."<p><strong>Nội dung:</strong> {$baseTopic}</p>"
                ."<p><strong>Thời gian thực hiện:</strong> Bắt đầu từ 08:00 ngày {$now->copy()->addDays(rand(1, 10))->format('d/m/Y')}</p>"
                ."<p><strong>Phạm vi ảnh hưởng:</strong> {$scopeSuffix}</p>"
                .'</div>'
                .'<p>Trong quá trình triển khai, Ban Quản lý sẽ cố gắng hạn chế tối đa việc gây phiền hà hoặc gián đoạn sinh hoạt của Quý cư dân. Mọi thắc mắc hoặc cần hỗ trợ khẩn cấp, Quý cư dân vui lòng liên hệ Hotline Lễ tân 24/7 qua hotline nội bộ hoặc ứng dụng cư dân.</p>'
                .'<p>Trân trọng cảm ơn sự phối hợp của Quý cư dân!</p>';

            // Thời gian đăng: 90 ngày trước tới 10 ngày sau
            $createdAt = $now->copy()->subDays(rand(1, 90))->setTime(rand(8, 20), rand(0, 59));
            $daysDiff = $createdAt->diffInDays($now);

            // Phân bổ trạng thái
            if ($category === 'EMERGENCY') {
                $status = 'PUBLISHED';
                $isUrgent = 1;
                $isPinned = (rand(1, 100) <= 50) ? 1 : 0;
                $reqAck = 1;
            } else {
                $isUrgent = (rand(1, 100) <= 8) ? 1 : 0;
                $isPinned = (rand(1, 100) <= 12) ? 1 : 0;
                $reqAck = (rand(1, 100) <= 15) ? 1 : 0;

                $statusRand = rand(1, 100);
                if ($statusRand <= 75) {
                    $status = 'PUBLISHED';
                } elseif ($statusRand <= 88) {
                    $status = 'DRAFT';
                } elseif ($statusRand <= 94) {
                    $status = 'SCHEDULED';
                } else {
                    $status = 'EXPIRED';
                }
            }

            $publishAt = $status === 'SCHEDULED' ? $now->copy()->addDays(rand(1, 5)) : $createdAt;
            $expiresAt = $status === 'EXPIRED' ? $now->copy()->subDays(rand(1, 5)) : ($status === 'PUBLISHED' ? $now->copy()->addDays(rand(30, 90)) : null);

            $announcementsBatch[] = [
                'id' => $announcementId,
                'title' => $title,
                'summary_excerpt' => $excerpt,
                'content_html' => $contentHtml,
                'category' => $category,
                'target_scope' => $targetScope,
                'target_block_id' => $targetBlockId,
                'target_floor_id' => $targetFloorId,
                'is_pinned' => $isPinned,
                'is_urgent' => $isUrgent,
                'requires_acknowledgement' => $reqAck,
                'publish_at' => $publishAt,
                'expires_at' => $expiresAt,
                'author_user_id' => $authorId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => null,
                'status' => $status,
                'version' => 1,
            ];

            // Tạo lượt đọc cho thông báo đã đăng
            if ($status === 'PUBLISHED' && ! empty($residents)) {
                $readerCount = rand(1, min(5, count($residents)));
                $shuffledReaders = $residents;
                shuffle($shuffledReaders);
                for ($r = 0; $r < $readerCount; $r++) {
                    $readerId = $shuffledReaders[$r];
                    $readTime = $publishAt->copy()->addMinutes(rand(15, 2880));
                    $readsBatch[] = [
                        'id' => (string) Str::uuid(),
                        'announcement_id' => $announcementId,
                        'user_id' => $readerId,
                        'read_at' => $readTime,
                        'acknowledged_at' => $reqAck ? $readTime->copy()->addMinutes(rand(1, 10)) : null,
                    ];
                }
            }

            if (count($announcementsBatch) >= $batchSize) {
                DB::table('announcements')->insert($announcementsBatch);
                if (! empty($readsBatch)) {
                    DB::table('announcement_reads')->insertOrIgnore($readsBatch);
                }
                $announcementsBatch = [];
                $readsBatch = [];
            }
        }

        if (! empty($announcementsBatch)) {
            DB::table('announcements')->insert($announcementsBatch);
            if (! empty($readsBatch)) {
                DB::table('announcement_reads')->insertOrIgnore($readsBatch);
            }
        }

        $finalCount = DB::table('announcements')->count();
        $readCount = DB::table('announcement_reads')->count();
        $this->command?->info("Đã seed thành công! Tổng số announcements: {$finalCount}, announcement_reads: {$readCount}");
    }
}
