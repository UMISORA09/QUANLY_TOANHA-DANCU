<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SuggestionMassSeeder extends Seeder
{
    /**
     * Seed approximately 500 suggestions (F12).
     */
    public function run(): void
    {
        $targetCount = 500;
        $currentCount = DB::table('suggestions')->count();

        if ($currentCount >= $targetCount) {
            $this->command?->info("suggestions đã có {$currentCount} bản ghi (>= {$targetCount}), bỏ qua tạo thêm.");

            return;
        }

        $needed = $targetCount - $currentCount;
        $this->command?->info("Đang tạo thêm {$needed} bản ghi hòm thư góp ý (suggestions)...");

        $boxes = DB::table('suggestion_boxes')->where('is_open', 1)->get();
        if ($boxes->isEmpty()) {
            $boxes = DB::table('suggestion_boxes')->get();
        }

        if ($boxes->isEmpty()) {
            $this->command?->error('Không tìm thấy hòm thư nào trong suggestion_boxes.');

            return;
        }

        $apartments = DB::table('apartments')->get();
        $residentRows = DB::table('residents')->select('user_id', 'apartment_id')->get();

        $staffUsers = DB::table('users')
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->all();

        $defaultStaffId = $staffUsers[0] ?? null;

        $suggestionDataBank = [
            'AN_NINH' => [
                ['title' => 'Khen ngợi tổ bảo vệ trực ca đêm hỗ trợ cư dân', 'text' => 'Bác bảo vệ ca đêm ngày 15/10 đã rất tận tâm đưa cụ già đi lạc về tận cửa căn hộ an toàn. Rất hoan nghênh tinh thần trách nhiệm của đội ngũ.', 'sentiment' => 'POSITIVE', 'score' => 0.88],
                ['title' => 'Đề xuất tăng cường tuần tra hành lang sau 23h', 'text' => 'Đề nghị Ban Quản lý bố trí bảo vệ tuần tra kiểm tra đèn hành lang và cửa thoát hiểm sau 23h để đảm bảo an ninh trật tự.', 'sentiment' => 'NEUTRAL', 'score' => 0.15],
                ['title' => 'Người lạ vào sảnh phát tờ rơi không qua kiểm soát', 'text' => 'Buổi chiều thường xuyên có người ngoài tự ý đi thang máy lên các tầng nhét tờ rơi quảng cáo vào khe cửa, đề nghị rà soát kiểm soát thẻ từ.', 'sentiment' => 'NEGATIVE', 'score' => -0.55],
                ['title' => 'Tình trạng xe máy bị quẹt trầy xước dưới hầm B2', 'text' => 'Xe máy của tôi để đúng vị trí quy định nhưng liên tục bị các xe bên cạnh chen lấn va quẹt trầy xước, đề nghị bảo vệ trích xuất camera nhắc nhở.', 'sentiment' => 'VERY_ANGRY', 'score' => -0.88],
            ],
            'VE_SINH' => [
                ['title' => 'Sảnh hành lang và thang máy luôn sạch sẽ, thơm tho', 'text' => 'Đội ngũ lao công làm việc rất chăm chỉ, sảnh thang máy lau dọn liên tục và có mùi tinh dầu dễ chịu. Cảm ơn Ban Quản lý.', 'sentiment' => 'POSITIVE', 'score' => 0.92],
                ['title' => 'Đề xuất bổ sung thêm thùng rác phân loại tại khu vui chơi', 'text' => 'Khu sân chơi trẻ em vào buổi chiều có nhiều rác vỏ bánh kẹo, đề xuất đặt thêm 2 thùng rác phân loại hữu cơ và vô cơ.', 'sentiment' => 'NEUTRAL', 'score' => 0.20],
                ['title' => 'Mùi hôi phòng rác tầng thoát ra hành lang', 'text' => 'Cửa phòng thu gom rác tầng đóng không khít, quạt hút mùi hoạt động yếu dẫn đến mùi hôi rỉ ra hành lang vào giờ cao điểm.', 'sentiment' => 'NEGATIVE', 'score' => -0.62],
                ['title' => 'Thùng rác công cộng bốc mùi hôi thối 2 ngày cuối tuần', 'text' => 'Rác thải sinh hoạt ứ đọng cuối tuần không được chuyển đi kịp thời, bốc mùi nồng nặc và ruồi muỗi bu quanh khu vực sảnh phụ.', 'sentiment' => 'VERY_ANGRY', 'score' => -0.94],
            ],
            'TIEN_ICH' => [
                ['title' => 'Hồ bơi sau bảo trì rất sạch và an toàn', 'text' => 'Nước hồ bơi trong vắt, nhân viên cứu hộ túc trực nghiêm túc, tạo sự an tâm lớn cho các phụ huynh khi cho con tắm.', 'sentiment' => 'POSITIVE', 'score' => 0.90],
                ['title' => 'Kiến nghị mở cửa phòng Gym sớm hơn từ 5h30', 'text' => 'Nhiều cư dân có nhu cầu tập luyện thể thao trước giờ đi làm, đề xuất điều chỉnh giờ mở cửa phòng Gym từ 5h30 thay vì 6h00.', 'sentiment' => 'NEUTRAL', 'score' => 0.25],
                ['title' => 'Máy chạy bộ số 2 phòng Gym bị rung lắc mạnh', 'text' => 'Băng tải máy chạy số 2 bị chùng và kêu cọt kẹt khi chạy ở tốc độ trên 8km/h, đề nghị bảo trì sớm tránh gây chấn thương cho cư dân.', 'sentiment' => 'NEGATIVE', 'score' => -0.48],
                ['title' => 'Bếp nướng BBQ bị người ngoài chiếm dụng dù đã đặt lịch', 'text' => 'Gia đình tôi đã đặt trước và đóng phí nhưng đến nơi lại có nhóm người chiếm dùng và bảo vệ từ chối can thiệp giải quyết!', 'sentiment' => 'VERY_ANGRY', 'score' => -0.90],
            ],
            'THAI_DO_NHAN_VIEN' => [
                ['title' => 'Khen ngợi bạn lễ tân luôn niềm nở chào hỏi cư dân', 'text' => 'Bạn lễ tân trực sảnh sáng rất lễ phép, chu đáo hỗ trợ người già và nhận giúp bưu phẩm shipper giao. Rất có thiện cảm!', 'sentiment' => 'POSITIVE', 'score' => 0.95],
                ['title' => 'Đề nghị nhân viên bãi xe hướng dẫn rõ ràng hơn cho khách', 'text' => 'Khách đến thăm tòa nhà nhiều khi không biết lối gửi xe tầng B1 hay B2, mong nhân viên trực barie chỉ dẫn nhiệt tình hơn.', 'sentiment' => 'NEUTRAL', 'score' => 0.05],
                ['title' => 'Nhân viên lễ tân trực ca sáng có thái độ cáu gắt', 'text' => 'Khi cư dân xuống hỏi về thủ tục đăng ký thi công nội thất, nhân viên trả lời cộc lốc và không hướng dẫn biểu mẫu cụ thể.', 'sentiment' => 'NEGATIVE', 'score' => -0.65],
                ['title' => 'Bảo vệ to tiếng quát nạt cư dân khi thẻ từ bị lỗi', 'text' => 'Thẻ cư dân quẹt chưa nhận ngay, bảo vệ bấm còi inh ỏi và có lời lẽ thiếu tôn trọng với người lớn tuổi. Cần xử lý kỷ luật nghiêm.', 'sentiment' => 'VERY_ANGRY', 'score' => -0.92],
            ],
            'BAI_XE' => [
                ['title' => 'Hệ thống đèn led và sơn vạch hầm xe rất sáng đẹp', 'text' => 'Tầng hầm B1 sau khi sơn mới lại lối đi và đánh số vị trí đỗ xe trông rất khang trang, sạch sẽ, lái xe dễ quan sát.', 'sentiment' => 'POSITIVE', 'score' => 0.85],
                ['title' => 'Cần phân làn phản quang rõ ràng ở lối dốc cua tầng hầm', 'text' => 'Khúc cua giữa dốc hầm B1 xuống B2 hơi khuất tầm nhìn, đề xuất gắn thêm gương cầu lồi lớn và gờ giảm tốc cao su.', 'sentiment' => 'NEUTRAL', 'score' => 0.15],
                ['title' => 'Xe máy đỗ lấn chiếm lối đi của người khuyết tật', 'text' => 'Nhiều xe máy để chắn ngay đường dốc dẫn lên sảnh thang máy tầng hầm, khiến việc đẩy xe nôi cho trẻ nhỏ gặp nhiều khó khăn.', 'sentiment' => 'NEGATIVE', 'score' => -0.52],
                ['title' => 'Ô tô đỗ chắn cửa thoát hiểm hầm B2 nhiều ngày liền', 'text' => 'Chiếc xe bán tải đỗ chắn ngang lối thang bộ thoát hiểm suốt 3 ngày nay mà không bị dán giấy cảnh báo hay khóa bánh!', 'sentiment' => 'VERY_ANGRY', 'score' => -0.93],
            ],
            'KHAC' => [
                ['title' => 'Khuôn viên cây xanh dạo bộ rất thoáng mát và an lành', 'text' => 'Cảm ơn Ban Quản lý đã chăm sóc thảm cỏ và bồn hoa rất tỉ mỉ, tạo không gian sống xanh lý tưởng cho cộng đồng cư dân.', 'sentiment' => 'POSITIVE', 'score' => 0.88],
                ['title' => 'Đề xuất tổ chức phiên chợ đồ cũ trao đổi sách cho trẻ em', 'text' => 'Vào dịp cuối tuần các bé có nhiều sách truyện cũ, đề xuất BQL tạo không gian tại sảnh sinh hoạt để các gia đình trao đổi.', 'sentiment' => 'NEUTRAL', 'score' => 0.30],
                ['title' => 'Tiếng ồn khoan đục sửa nhà vào giờ nghỉ trưa', 'text' => 'Căn hộ tầng trên liên tục khoan đục tường từ 12h30 đến 13h30 trưa vi phạm nội quy thi công, làm ảnh hưởng giấc ngủ của trẻ nhỏ.', 'sentiment' => 'NEGATIVE', 'score' => -0.58],
                ['title' => 'Nuôi chó thả rông không rọ mõm đi vệ sinh bừa bãi', 'text' => 'Nhiều chủ nuôi dẫn chó to đi thang máy không rọ mõm, phóng uế ra thảm sảnh mà không dọn, gây mất an toàn và bức xúc tột cùng!', 'sentiment' => 'VERY_ANGRY', 'score' => -0.95],
            ],
        ];

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $suggestionsBatch = [];
        $batchSize = 100;

        $categories = ['AN_NINH', 'VE_SINH', 'TIEN_ICH', 'THAI_DO_NHAN_VIEN', 'BAI_XE', 'KHAC'];

        for ($i = 0; $i < $needed; $i++) {
            $suggestionId = (string) Str::uuid();
            $box = $boxes->random();

            $isAnonymous = (rand(1, 100) <= 30);
            if ($isAnonymous) {
                $residentUserId = null;
                $apartmentId = null;
                $anonHash = hash('sha256', Str::random(32));
            } else {
                $anonHash = null;
                if (! $residentRows->isEmpty() && rand(0, 10) > 2) {
                    $pair = $residentRows->random();
                    $residentUserId = $pair->user_id;
                    $apartmentId = $pair->apartment_id;
                } else {
                    $residentUserId = $staffUsers[array_rand($staffUsers)];
                    $apartmentId = $apartments->random()->id;
                }
            }

            $category = $categories[array_rand($categories)];
            $templateList = $suggestionDataBank[$category];
            $item = $templateList[array_rand($templateList)];

            $title = $item['title'].' #'.($currentCount + $i + 1);
            $content = $item['text'];
            $sentiment = $item['sentiment'];
            $sentimentScore = (float) $item['score'];

            // Thời gian tạo
            $createdAt = $now->copy()->subDays(rand(1, 90))->setTime(rand(7, 22), rand(0, 59));
            $daysDiff = $createdAt->diffInDays($now);

            // Phân bổ trạng thái
            if ($daysDiff > 14) {
                $statusRand = rand(1, 100);
                if ($statusRand <= 45) {
                    $status = 'RESOLVED';
                } elseif ($statusRand <= 75) {
                    $status = 'ACCEPTED';
                } elseif ($statusRand <= 90) {
                    $status = 'REJECTED';
                } else {
                    $status = 'REVIEWING';
                }
            } elseif ($daysDiff > 3) {
                $statusRand = rand(1, 100);
                if ($statusRand <= 40) {
                    $status = 'REVIEWING';
                } elseif ($statusRand <= 70) {
                    $status = 'ACCEPTED';
                } else {
                    $status = 'RECEIVED';
                }
            } else {
                $statusRand = rand(1, 100);
                if ($statusRand <= 60) {
                    $status = 'RECEIVED';
                } else {
                    $status = 'REVIEWING';
                }
            }

            // Phản hồi từ BQL
            $officialResponse = null;
            $rejectionReason = null;
            $respondedBy = null;
            $respondedAt = null;

            if ($status === 'RESOLVED') {
                $officialResponse = 'Ban Quản lý đã tiếp nhận, kiểm tra thực tế và hoàn tất khắc phục vấn đề. Xin chân thành cảm ơn ý kiến đóng góp tâm huyết của Quý cư dân!';
                $respondedBy = $defaultStaffId;
                $respondedAt = $createdAt->copy()->addDays(rand(1, 4));
            } elseif ($status === 'ACCEPTED') {
                $officialResponse = 'Ban Quản lý xin ghi nhận đề xuất hợp lý này và đã đưa vào kế hoạch công tác của tháng. Dự kiến sẽ hoàn thiện trong kỳ tới.';
                $respondedBy = $defaultStaffId;
                $respondedAt = $createdAt->copy()->addDays(rand(1, 2));
            } elseif ($status === 'REJECTED') {
                $rejectionReason = 'Sau khi rà soát tiêu chuẩn an toàn PCCC và Quy chế vận hành chung cư, đề xuất hiện tại chưa thể áp dụng do quy định pháp luật hiện hành.';
                $officialResponse = 'Rất tiếc đề xuất chưa phù hợp với điều kiện thực tế của tòa nhà. Chi tiết đã được gửi trong phần lý do từ chối.';
                $respondedBy = $defaultStaffId;
                $respondedAt = $createdAt->copy()->addDays(rand(1, 2));
            }

            $suggestionsBatch[] = [
                'id' => $suggestionId,
                'box_id' => $box->id,
                'idempotency_key' => null,
                'resident_user_id' => $residentUserId,
                'apartment_id' => $apartmentId,
                'is_anonymous' => $isAnonymous ? 1 : 0,
                'anonymous_access_token_hash' => $anonHash,
                'topic_category' => $category,
                'title' => $title,
                'content_text' => $content,
                'attached_files' => json_encode([]),
                'status' => $status,
                'version' => 1,
                'official_response_text' => $officialResponse,
                'rejection_reason' => $rejectionReason,
                'responded_by_staff_id' => $respondedBy,
                'responded_at' => $respondedAt,
                'ai_sentiment' => $sentiment,
                'ai_sentiment_score' => $sentimentScore,
                'created_at' => $createdAt,
                'updated_at' => $respondedAt ?? $createdAt,
                'submission_key_hash' => hash('sha256', $suggestionId.Str::random(16)),
                'payload_fingerprint' => hash('sha256', $title.$content),
            ];

            if (count($suggestionsBatch) >= $batchSize) {
                DB::table('suggestions')->insert($suggestionsBatch);
                $suggestionsBatch = [];
            }
        }

        if (! empty($suggestionsBatch)) {
            DB::table('suggestions')->insert($suggestionsBatch);
        }

        $finalCount = DB::table('suggestions')->count();
        $this->command?->info("Đã seed thành công! Tổng số suggestions hiện tại: {$finalCount}");
    }
}
