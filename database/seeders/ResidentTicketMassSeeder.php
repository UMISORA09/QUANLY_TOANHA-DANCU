<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidentTicketMassSeeder extends Seeder
{
    /**
     * Seed approximately 500 tickets with histories, comments, and ratings (F5 - F9).
     */
    public function run(): void
    {
        $targetCount = 500;
        $currentCount = DB::table('tickets')->count();

        if ($currentCount >= $targetCount) {
            $this->command?->info("tickets đã có {$currentCount} bản ghi (>= {$targetCount}), bỏ qua tạo thêm.");

            return;
        }

        $needed = $targetCount - $currentCount;
        $this->command?->info("Đang tạo thêm {$needed} bản ghi phiếu sự cố & sửa chữa (tickets)...");

        $categories = DB::table('ticket_categories')->get();
        if ($categories->isEmpty()) {
            $this->command?->error('Không tìm thấy danh mục sự cố nào trong ticket_categories.');

            return;
        }

        $apartments = DB::table('apartments')->get();
        if ($apartments->isEmpty()) {
            $this->command?->error('Không tìm thấy căn hộ nào trong apartments.');

            return;
        }

        // Lấy danh sách cư dân gắn với căn hộ
        $residentRows = DB::table('residents')->select('user_id', 'apartment_id')->get();

        // Kỹ thuật viên & Quản lý
        $techUserIds = DB::table('users')
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.role_code', 'TECHNICIAN')
            ->pluck('users.id')
            ->all();

        $adminUserIds = DB::table('users')
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->all();

        if (empty($techUserIds)) {
            $techUserIds = $adminUserIds;
        }

        $defaultTechId = $techUserIds[0] ?? null;
        $defaultAdminId = $adminUserIds[0] ?? null;

        // Mẫu dữ liệu sự cố phong phú theo danh mục
        $issueTemplates = [
            'PLUMBING' => [
                ['title' => 'Rò rỉ nước tại van khóa bồn rửa chén', 'loc' => 'Khu vực bếp', 'desc' => 'Dây cấp nước mềm bị nứt nhẹ gây rỉ nước ra gầm tủ bếp làm ẩm mốc, cần thay dây cấp inox mới.'],
                ['title' => 'Tắc phễu thoát sàn phòng tắm master', 'loc' => 'Phòng tắm Master', 'desc' => 'Nước thoát rất chậm khi tắm, đã mở nắp chắn rác vệ sinh nhưng nghi dị vật kẹt sâu trong siphon.'],
                ['title' => 'Vòi sen tắm bị lỏng chân ốc và rỉ nước nóng', 'loc' => 'Phòng tắm chung', 'desc' => 'Củ sen tắm bị rỉ nước ở chân giắc co, tay gạt điều chỉnh nhiệt độ hơi kẹt.'],
                ['title' => 'Phao bồn cầu không ngắt nước tự động', 'loc' => 'Nhà vệ sinh', 'desc' => 'Nước xả bồn cầu chảy tràn liên tục vào lòng két nước làm lãng phí nước sạch.'],
                ['title' => 'Áp lực nước vòi rửa ban công rất yếu', 'loc' => 'Ban công / Logia', 'desc' => 'Vòi nước rửa ban công chảy rỉ rả không đủ tưới cây, nghi van khóa nhánh bị nghẽn cặn.'],
                ['title' => 'Mùi hôi bốc lên từ ống thoát sàn nhà vệ sinh', 'loc' => 'Phòng tắm', 'desc' => 'Đã xả nước ngập bẫy mùi nhưng vẫn có luồng khí hôi từ hộp kỹ thuật bốc lên.'],
            ],
            'ELECTRICAL' => [
                ['title' => 'Aptomat nhánh phòng ngủ bị nhảy khi cắm máy sấy', 'loc' => 'Phòng ngủ lớn', 'desc' => 'Aptomat 16A bị ngắt đột ngột khi cắm thêm thiết bị sinh hoạt, cần đo tải đường dây điện ngầm.'],
                ['title' => 'Bóng đèn led âm trần phòng khách bị chớp nháy', 'loc' => 'Phòng khách', 'desc' => 'Cụm 3 đèn downlight 9W ở trần thạch cao chớp giật liên tục, nghi hỏng chấn lưu (driver).'],
                ['title' => 'Ổ cắm điện góc sofa bị lỏng và phát tia lửa nhỏ', 'loc' => 'Phòng khách', 'desc' => 'Chân cắm vào lỏng lẻo và có tiếng xẹt lửa lách tách khi lay nhẹ phích cắm, rất nguy hiểm.'],
                ['title' => 'Công tắc bình nóng lạnh bị kẹt cứng nút bấm', 'loc' => 'Cửa phòng tắm', 'desc' => 'Không thể gạt công tắc đôi bình nước nóng, đèn tín hiệu đỏ không sáng.'],
                ['title' => 'Mất điện toàn bộ khu vực bếp nấu', 'loc' => 'Khu vực bếp', 'desc' => 'Aptomat nhánh bếp bị sập, gạt lên lại sập xuống ngay lập tức, nghi chạm chập dây âm tường.'],
                ['title' => 'Đèn led hắt trần thạch cao không sáng', 'loc' => 'Phòng ăn', 'desc' => 'Dây led hắt khe trần dài 5m không hoạt động, cần thang chuyên dụng kiểm tra nguồn trên trần.'],
            ],
            'HVAC' => [
                ['title' => 'Điều hòa phòng khách chảy nước xuống sàn gỗ', 'loc' => 'Phòng khách', 'desc' => 'Máng nước ngưng bị nghẹt rong rêu, nước tràn qua miệng vỏ máy nhỏ giọt liên tục.'],
                ['title' => 'Điều hòa phòng ngủ mát yếu và có mùi ẩm', 'loc' => 'Phòng ngủ master', 'desc' => 'Bật 20 độ chế độ Cool nhưng quạt chỉ thổi gió thường, nghi xì gas hoặc dàn tản nhiệt quá bẩn.'],
                ['title' => 'Cục nóng điều hòa ngoài ban công kêu to rung tường', 'loc' => 'Ban công ngoài trời', 'desc' => 'Chân đế cao su bị lão hóa khiến máy nén rung lắc mạnh truyền tiếng ồn vào phòng ngủ.'],
                ['title' => 'Quạt hút mùi thông gió nhà vệ sinh kêu rè rè', 'loc' => 'Nhà vệ sinh', 'desc' => 'Động cơ quạt hút gió âm trần bị khô dầu hoặc cánh quạt bám bụi dày quay lệch tâm.'],
            ],
            'DOORS_WINDOWS' => [
                ['title' => 'Khóa vân tay cửa chính kêu báo lỗi liên tục', 'loc' => 'Cửa chính căn hộ', 'desc' => 'Khóa thông minh báo đèn đỏ và kêu tít tít sau khi đóng cửa, chốt an toàn không phóng ra.'],
                ['title' => 'Cửa kính lùa ban công bị kẹt bánh xe trượt', 'loc' => 'Cửa ra ban công', 'desc' => 'Cánh cửa kéo rất nặng tay, có tiếng cọ xát kim loại ở ray dưới sàn, nguy cơ trật ray.'],
                ['title' => 'Tay nắm cửa phòng ngủ phụ bị lỏng ốc vít', 'loc' => 'Phòng ngủ nhỏ', 'desc' => 'Bộ tay nắm cửa gỗ bị rơ lắc, khóa chốt bên trong thỉnh thoảng khó mở.'],
                ['title' => 'Gioăng cao su mép cửa sổ bị rách gây tạt nước mưa', 'loc' => 'Cửa sổ phòng khách', 'desc' => 'Trời mưa bão có nước rỉ men theo mép kính vào bệ cửa, cần thay ron cao su làm kín.'],
            ],
            'FIRE_SAFETY' => [
                ['title' => 'Đầu báo khói nhấp nháy đèn vàng báo lỗi kết nối', 'loc' => 'Trần hành lang căn hộ', 'desc' => 'Đầu cảm biến báo cháy trần nhấp nháy đèn vàng ngắt quãng, nhờ kiểm tra tín hiệu về tủ trung tâm.'],
                ['title' => 'Chuông báo cháy nhánh hành lang phát tiếng kêu nhỏ', 'loc' => 'Hành lang ngoài cửa', 'desc' => 'Còi chuông phát âm thanh rè rè ngắt quãng, nghi chập dây tín hiệu vùng.'],
            ],
            'WATERPROOFING' => [
                ['title' => 'Vết ố vàng góc trần thạch cao nhà vệ sinh', 'loc' => 'Trần nhà vệ sinh', 'desc' => 'Xuất hiện mảng ố vàng lan rộng ở mép hộp gen kỹ thuật, nghi căn hộ tầng trên bị rò nước sàn.'],
                ['title' => 'Thấm ẩm chân tường giáp ranh ban công', 'loc' => 'Góc ban công', 'desc' => 'Sơn chân tường bị phồng rộp và bong vảy do ẩm chân tường sau đợt mưa kéo dài.'],
            ],
            'SMART_HOME' => [
                ['title' => 'Màn hình chuông cửa Video Intercom bị mất hình', 'loc' => 'Sảnh đón cửa', 'desc' => 'Khách bấm chuông dưới sảnh vẫn có tiếng reo nhưng màn hình trong nhà tối đen không xem được camera.'],
                ['title' => 'Thẻ từ thang máy không quẹt được lên tầng căn hộ', 'loc' => 'Cửa thang máy', 'desc' => 'Thẻ cư dân quẹt vào đầu đọc báo đèn đỏ không nhận tầng, phải nhờ bảo vệ hỗ trợ quẹt tầng.'],
            ],
            'OTHER' => [
                ['title' => 'Cửa thông gió hành lang tầng đóng sầm gây ồn', 'loc' => 'Cửa thoát hiểm tầng', 'desc' => 'Tay co thủy lực bị chảy dầu mất độ hãm, gió lùa làm cửa đóng sầm rất mạnh vào ban đêm.'],
                ['title' => 'Đèn chiếu sáng khu vực sảnh thang máy bị cháy', 'loc' => 'Sảnh thang máy tầng', 'desc' => '1 bóng đèn led sảnh chờ thang máy đã tắt hẳn 3 ngày nay, khu vực khá tối vào buổi tối.'],
            ],
        ];

        $reviewTagSets = [
            ['Đúng giờ', 'Nhiệt tình', 'Kỹ thuật tốt', 'Dọn dẹp sạch sẽ'],
            ['Xử lý nhanh', 'Chuyên nghiệp', 'Thái độ lịch sự', 'Hài lòng'],
            ['Đến đúng hẹn', 'Giải thích rõ ràng', 'Tay nghề vững'],
            ['Sửa dứt điểm', 'Cẩn thận', 'Tận tâm'],
            ['Đến hơi muộn', 'Nhưng xử lý tốt', 'Nhiệt tình'],
        ];

        $reviewComments = [
            'Kỹ thuật viên đến rất đúng giờ, thao tác nhanh nhẹn và dọn dẹp hiện trường sạch sẽ sau khi sửa xong.',
            'Rất hài lòng với tác phong phục vụ chuyên nghiệp của bạn kỹ thuật. Cảm ơn Ban quản lý!',
            'Xử lý dứt điểm sự cố rò rỉ, hướng dẫn gia đình cách sử dụng van an toàn rất chu đáo.',
            'Kỹ thuật viên nhiệt tình, lịch sự, hỗ trợ kiểm tra thêm cả ổ cắm điện phụ giúp gia đình.',
            'Thời gian tiếp nhận và xử lý nhanh vượt mong đợi của gia đình.',
            'Thợ sửa có kinh nghiệm, bắt đúng bệnh và thay thế phụ tùng chuẩn chỉ.',
            'Dịch vụ tốt, thái độ lễ phép, 5 sao cho đội ngũ kỹ thuật tòa nhà!',
            'Khắc phục tốt, tuy nhiên hẹn 14h mà 14h20 mới đến, mong lần sau báo trước nếu đến muộn.',
        ];

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $ticketsBatch = [];
        $historiesBatch = [];
        $commentsBatch = [];
        $ratingsBatch = [];
        $batchSize = 100;

        for ($i = 0; $i < $needed; $i++) {
            $ticketId = (string) Str::uuid();

            // Chọn cư dân & căn hộ
            if (! $residentRows->isEmpty() && rand(0, 10) > 2) {
                $residentPair = $residentRows->random();
                $userId = $residentPair->user_id;
                $apartmentId = $residentPair->apartment_id;
            } else {
                $apartment = $apartments->random();
                $apartmentId = $apartment->id;
                $userId = $adminUserIds[array_rand($adminUserIds)];
            }

            // Chọn danh mục
            $catRecord = $categories->random();
            $catCode = $catRecord->category_code;
            $catId = $catRecord->id;

            $templates = $issueTemplates[$catCode] ?? $issueTemplates['OTHER'];
            $template = $templates[array_rand($templates)];

            // Thời gian tạo: trải dài từ 90 ngày trước tới hôm nay
            $createdAt = $now->copy()->subDays(rand(1, 90))->setTime(rand(7, 21), rand(0, 59));
            $daysOld = $createdAt->diffInDays($now);

            // Phân bổ trạng thái theo độ cũ của ticket
            if ($daysOld > 7) {
                $randStatus = rand(1, 100);
                if ($randStatus <= 65) {
                    $status = 'CLOSED';
                } elseif ($randStatus <= 90) {
                    $status = 'RESOLVED';
                } else {
                    $status = 'CANCELLED';
                }
            } elseif ($daysOld > 2) {
                $randStatus = rand(1, 100);
                if ($randStatus <= 50) {
                    $status = 'RESOLVED';
                } elseif ($randStatus <= 80) {
                    $status = 'IN_PROGRESS';
                } else {
                    $status = 'ASSIGNED';
                }
            } else {
                $randStatus = rand(1, 100);
                if ($randStatus <= 35) {
                    $status = 'NEW';
                } elseif ($randStatus <= 65) {
                    $status = 'ASSIGNED';
                } else {
                    $status = 'IN_PROGRESS';
                }
            }

            $priorityPool = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
            $priorityWeights = [15, 50, 25, 10];
            $priorityRand = rand(1, 100);
            $priority = $priorityRand <= 15 ? 'LOW' : ($priorityRand <= 65 ? 'MEDIUM' : ($priorityRand <= 90 ? 'HIGH' : 'URGENT'));

            $coordinatorId = in_array($status, ['ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED']) ? $defaultAdminId : null;
            $technicianId = in_array($status, ['ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED']) ? $techUserIds[array_rand($techUserIds)] : null;

            $slaDeadline = $createdAt->copy()->addHours((int) ($catRecord->sla_resolution_time_hours ?? 24));
            $resolvedAt = in_array($status, ['RESOLVED', 'CLOSED']) ? $createdAt->copy()->addHours(rand(2, 20)) : null;
            $closedAt = ($status === 'CLOSED' && $resolvedAt) ? $resolvedAt->copy()->addHours(rand(1, 12)) : null;

            $isBillable = (rand(1, 100) <= 20) ? 1 : 0;
            $repairCost = $isBillable ? rand(100, 600) * 1000 : 0;

            $ticketNumber = 'TCK-'.$createdAt->format('Ym').'-'.strtoupper(Str::random(6));

            $ticketsBatch[] = [
                'id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'category_id' => $catId,
                'apartment_id' => $apartmentId,
                'creator_user_id' => $userId,
                'title' => $template['title'],
                'description' => $template['desc'],
                'location' => $template['loc'],
                'priority' => $priority,
                'status' => $status,
                'current_technician_user_id' => $technicianId,
                'coordinator_user_id' => $coordinatorId,
                'preferred_service_time' => $createdAt->copy()->addHours(rand(2, 24)),
                'sla_deadline' => $slaDeadline,
                'resolved_at' => $resolvedAt,
                'closed_at' => $closedAt,
                'version' => 1,
                'is_billable' => $isBillable,
                'estimated_repair_cost' => $repairCost,
                'actual_repair_cost' => $status === 'RESOLVED' || $status === 'CLOSED' ? $repairCost : 0,
                'invoice_id' => null,
                'created_at' => $createdAt,
                'updated_at' => $closedAt ?? $resolvedAt ?? $createdAt,
                'deleted_at' => null,
                'idempotency_key' => null,
            ];

            // 1. Tạo ticket_history
            $historiesBatch[] = [
                'id' => (string) Str::uuid(),
                'ticket_id' => $ticketId,
                'action_type' => 'CREATED',
                'old_status' => null,
                'new_status' => 'NEW',
                'performed_by_user_id' => $userId,
                'notes' => 'Cư dân đã gửi yêu cầu xử lý sự cố qua ứng dụng cư dân.',
                'created_at' => $createdAt,
            ];

            if (in_array($status, ['ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'])) {
                $historiesBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'action_type' => 'ASSIGNED',
                    'old_status' => 'NEW',
                    'new_status' => 'ASSIGNED',
                    'performed_by_user_id' => $coordinatorId,
                    'notes' => 'Điều phối viên Ban quản lý đã phân công kỹ thuật viên phụ trách.',
                    'created_at' => $createdAt->copy()->addMinutes(rand(10, 30)),
                ];
            }

            if (in_array($status, ['IN_PROGRESS', 'RESOLVED', 'CLOSED'])) {
                $historiesBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'action_type' => 'STATUS_CHANGED',
                    'old_status' => 'ASSIGNED',
                    'new_status' => 'IN_PROGRESS',
                    'performed_by_user_id' => $technicianId,
                    'notes' => 'Kỹ thuật viên đã tiếp nhận tại căn hộ và đang tiến hành sửa chữa.',
                    'created_at' => $createdAt->copy()->addHours(rand(1, 3)),
                ];
            }

            if (in_array($status, ['RESOLVED', 'CLOSED']) && $resolvedAt) {
                $historiesBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'action_type' => 'RESOLVED',
                    'old_status' => 'IN_PROGRESS',
                    'new_status' => 'RESOLVED',
                    'performed_by_user_id' => $technicianId,
                    'notes' => 'Kỹ thuật viên hoàn thành khắc phục sự cố, đã bàn giao căn hộ kiểm tra.',
                    'created_at' => $resolvedAt,
                ];
            }

            if ($status === 'CLOSED' && $closedAt) {
                $historiesBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'action_type' => 'STATUS_CHANGED',
                    'old_status' => 'RESOLVED',
                    'new_status' => 'CLOSED',
                    'performed_by_user_id' => $userId,
                    'notes' => 'Cư dân đã nghiệm thu đạt yêu cầu và đóng phiếu sự cố.',
                    'created_at' => $closedAt,
                ];
            }

            if ($status === 'CANCELLED') {
                $historiesBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'action_type' => 'STATUS_CHANGED',
                    'old_status' => 'NEW',
                    'new_status' => 'CANCELLED',
                    'performed_by_user_id' => $userId,
                    'notes' => 'Cư dân chủ động hủy yêu cầu do đã tự xử lý được.',
                    'created_at' => $createdAt->copy()->addMinutes(rand(15, 60)),
                ];
            }

            // 2. Tạo ticket_comments (cho ~40% ticket)
            if (rand(1, 100) <= 40 && $technicianId) {
                $commentsBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'sender_user_id' => $technicianId,
                    'comment_text' => 'Chào anh/chị, em là kỹ thuật viên phụ trách. Em dự kiến qua căn hộ kiểm tra trong khoảng 30 phút nữa ạ.',
                    'is_internal_staff_note' => 0,
                    'created_at' => $createdAt->copy()->addMinutes(rand(30, 60)),
                ];
                $commentsBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'sender_user_id' => $userId,
                    'comment_text' => 'Dạ vâng có người ở nhà, bạn bấm chuông giúp mình nhé.',
                    'is_internal_staff_note' => 0,
                    'created_at' => $createdAt->copy()->addMinutes(rand(65, 80)),
                ];
            }

            // 3. Tạo ticket_ratings (cho các ticket RESOLVED & CLOSED)
            if (in_array($status, ['RESOLVED', 'CLOSED']) && $resolvedAt) {
                $starRand = rand(1, 100);
                $stars = $starRand <= 60 ? 5 : ($starRand <= 85 ? 4 : ($starRand <= 95 ? 3 : rand(1, 2)));
                $tags = $reviewTagSets[array_rand($reviewTagSets)];

                $ratingsBatch[] = [
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'resident_user_id' => $userId,
                    'rating_stars' => $stars,
                    'feedback_tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                    'review_comment' => $reviewComments[array_rand($reviewComments)],
                    'created_at' => $closedAt ?? $resolvedAt->copy()->addHours(1),
                ];
            }

            // Bulk insert batch
            if (count($ticketsBatch) >= $batchSize) {
                DB::table('tickets')->insert($ticketsBatch);
                DB::table('ticket_history')->insert($historiesBatch);
                if (! empty($commentsBatch)) {
                    DB::table('ticket_comments')->insert($commentsBatch);
                }
                if (! empty($ratingsBatch)) {
                    DB::table('ticket_ratings')->insert($ratingsBatch);
                }
                $ticketsBatch = [];
                $historiesBatch = [];
                $commentsBatch = [];
                $ratingsBatch = [];
            }
        }

        if (! empty($ticketsBatch)) {
            DB::table('tickets')->insert($ticketsBatch);
            DB::table('ticket_history')->insert($historiesBatch);
            if (! empty($commentsBatch)) {
                DB::table('ticket_comments')->insert($commentsBatch);
            }
            if (! empty($ratingsBatch)) {
                DB::table('ticket_ratings')->insert($ratingsBatch);
            }
        }

        $finalCount = DB::table('tickets')->count();
        $ratingCount = DB::table('ticket_ratings')->count();
        $historyCount = DB::table('ticket_history')->count();
        $this->command?->info("Đã seed thành công! Tổng tickets: {$finalCount}, ratings: {$ratingCount}, history logs: {$historyCount}");
    }
}
