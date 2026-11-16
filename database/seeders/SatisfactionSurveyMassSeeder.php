<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SatisfactionSurveyMassSeeder extends Seeder
{
    /**
     * Seed surveys, questions, and ~500 responses with details (F13).
     */
    public function run(): void
    {
        $targetCount = 500;
        $currentCount = DB::table('survey_responses')->count();

        if ($currentCount >= $targetCount) {
            $this->command?->info("survey_responses đã có {$currentCount} bản ghi (>= {$targetCount}), bỏ qua tạo thêm.");

            return;
        }

        $needed = $targetCount - $currentCount;
        $this->command?->info("Đang tạo thêm khảo sát và {$needed} phản hồi cư dân (survey_responses)...");

        $adminUser = DB::table('users')->where('status', 'ACTIVE')->first();
        $adminId = $adminUser ? $adminUser->id : (string) Str::uuid();

        $apartments = DB::table('apartments')->get();
        if ($apartments->isEmpty()) {
            $this->command?->error('Không tìm thấy căn hộ nào trong apartments để tạo survey responses.');

            return;
        }

        $residentRows = DB::table('residents')->select('user_id', 'apartment_id')->get();
        $allUserIds = DB::table('users')->pluck('id')->all();

        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // Định nghĩa 4 chiến dịch khảo sát lớn
        $surveysConfig = [
            [
                'code' => 'SURVEY-2026-Q1-ALL',
                'title' => 'Khảo sát Chất lượng Dịch vụ Vận hành Quý 1/2026',
                'desc' => 'Đánh giá chất lượng vệ sinh môi trường, an ninh và hệ thống tiện ích trong quý 1/2026.',
                'start' => $now->copy()->subDays(200)->toDateString(),
                'end' => $now->copy()->subDays(160)->toDateString(),
                'target_responses' => 140,
                'questions' => [
                    ['order' => 1, 'text' => 'Mức độ hài lòng tổng quan về dịch vụ vận hành của Ban Quản lý:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 2, 'text' => 'Đánh giá công tác vệ sinh và thu gom rác thải tại sảnh và các tầng:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 3, 'text' => 'Bạn có cảm thấy hệ thống an ninh và camera giám sát hoạt động hiệu quả không?', 'type' => 'YES_NO', 'opts' => ['Có', 'Không']],
                    ['order' => 4, 'text' => 'Khu vực tiện ích nào cần được nâng cấp hoặc bổ sung thêm trang thiết bị?', 'type' => 'MULTIPLE_CHOICE', 'opts' => ['Phòng Gym', 'Hồ bơi', 'Khu vui chơi trẻ em', 'Sân thể thao', 'Khu BBQ']],
                    ['order' => 5, 'text' => 'Góp ý cụ thể để Ban Quản lý nâng cao chất lượng môi trường sống:', 'type' => 'FREE_TEXT', 'opts' => []],
                ],
            ],
            [
                'code' => 'SURVEY-2026-Q2-SECURITY',
                'title' => 'Khảo sát Đánh giá An ninh & Dịch vụ Giữ xe Quý 2/2026',
                'desc' => 'Thu thập ý kiến cư dân về kiểm soát ra vào, thái độ nhân viên bảo vệ và quản lý bãi đỗ xe hầm.',
                'start' => $now->copy()->subDays(130)->toDateString(),
                'end' => $now->copy()->subDays(90)->toDateString(),
                'target_responses' => 130,
                'questions' => [
                    ['order' => 1, 'text' => 'Đánh giá mức độ an toàn và thái độ của nhân viên an ninh bảo vệ:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 2, 'text' => 'Đánh giá trật tự sắp xếp xe và vệ sinh tại tầng hầm đỗ xe B1, B2:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 3, 'text' => 'Hệ thống quẹt thẻ từ và nhận diện biển số xe tự động có thuận tiện không?', 'type' => 'YES_NO', 'opts' => ['Thuận tiện', 'Chưa thuận tiện']],
                    ['order' => 4, 'text' => 'Vấn đề nào bạn quan tâm nhất tại khu vực bãi xe tầng hầm?', 'type' => 'MULTIPLE_CHOICE', 'opts' => ['Phân làn dốc hầm', 'Bổ sung camera góc khuất', 'Trạm sạc xe điện', 'Kiểm soát xe khách vãng lai']],
                    ['order' => 5, 'text' => 'Ý kiến đề xuất để nâng cao công tác an ninh tòa nhà:', 'type' => 'FREE_TEXT', 'opts' => []],
                ],
            ],
            [
                'code' => 'SURVEY-2026-Q3-AMENITY',
                'title' => 'Khảo sát Tiện ích & Môi trường Cảnh quan Quý 3/2026',
                'desc' => 'Khảo sát mức độ hài lòng về các tiện ích thể thao, hồ bơi, công viên dạo bộ và vườn nướng BBQ.',
                'start' => $now->copy()->subDays(60)->toDateString(),
                'end' => $now->copy()->subDays(20)->toDateString(),
                'target_responses' => 130,
                'questions' => [
                    ['order' => 1, 'text' => 'Mức độ hài lòng về chất lượng nước và độ an toàn tại hồ bơi nội khu:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 2, 'text' => 'Đánh giá môi trường cây xanh, bồn hoa và cảnh quan lối dạo bộ:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 3, 'text' => 'Quy trình đặt chỗ tiện ích trực tuyến trên ứng dụng có nhanh chóng không?', 'type' => 'YES_NO', 'opts' => ['Có, rất nhanh', 'Không, còn bất tiện']],
                    ['order' => 4, 'text' => 'Tiện ích nào bạn và gia đình thường xuyên sử dụng nhất trong tuần?', 'type' => 'MULTIPLE_CHOICE', 'opts' => ['Hồ bơi', 'Phòng Gym', 'Sân Pickleball', 'Vườn nướng BBQ', 'Phòng sinh hoạt cộng đồng']],
                    ['order' => 5, 'text' => 'Góp ý thêm về thời gian mở cửa hoặc trang thiết bị tiện ích:', 'type' => 'FREE_TEXT', 'opts' => []],
                ],
            ],
            [
                'code' => 'SURVEY-2026-Q4-CSAT-ANNUAL',
                'title' => 'Khảo sát Toàn diện Mức độ Hài lòng Cư dân Năm 2026',
                'desc' => 'Khảo sát thường niên quy mô toàn dự án để tổng kết chỉ số CSAT và định hướng nâng cao chất lượng năm 2027.',
                'start' => $now->copy()->subDays(10)->toDateString(),
                'end' => $now->copy()->addDays(20)->toDateString(),
                'target_responses' => 140,
                'questions' => [
                    ['order' => 1, 'text' => 'Đánh giá mức độ hài lòng tổng quan đối với cuộc sống tại tòa nhà trong năm qua:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 2, 'text' => 'Đánh giá tốc độ và chất lượng tiếp nhận, xử lý sự cố kỹ thuật của BQL:', 'type' => 'RATING_1_TO_5', 'opts' => []],
                    ['order' => 3, 'text' => 'Bạn có sẵn lòng giới thiệu chung cư này cho bạn bè hoặc người thân về sinh sống không?', 'type' => 'YES_NO', 'opts' => ['Chắc chắn có', 'Cần cân nhắc thêm']],
                    ['order' => 4, 'text' => 'Hạng mục nào bạn mong muốn Ban Quản lý ưu tiên đầu tư nhất trong năm tới?', 'type' => 'MULTIPLE_CHOICE', 'opts' => ['Bảo trì thang máy', 'Nâng cấp cảnh quan sân vườn', 'Hệ thống kiểm soát an ninh thông minh', 'Hoạt động giao lưu cộng đồng']],
                    ['order' => 5, 'text' => 'Lời nhắn gửi, đóng góp hoặc nguyện vọng tới Ban Quản trị tòa nhà:', 'type' => 'FREE_TEXT', 'opts' => []],
                ],
            ],
        ];

        $freeTextPool = [
            'Hệ thống quản lý vận hành rất chuyên nghiệp, các nhân viên đều thân thiện và nhiệt tình.',
            'Cần cải thiện tốc độ xử lý sự cố ngoài giờ hành chính, đặc biệt là ban đêm.',
            'Rất hài lòng với cảnh quan và không gian xanh thoáng mát của dự án.',
            'Nên lắp thêm camera góc khuất ở tầng hầm B2 và tăng cường bảo vệ tuần tra đêm.',
            'Hồ bơi rất sạch, mong BQL tiếp tục duy trì tiêu chuẩn này thường xuyên.',
            'Đề xuất bổ sung thêm các thiết bị tập tạ đơn và bóng tập tại phòng gym.',
            'Ứng dụng cư dân rất tiện lợi, thao tác đóng tiền và đặt chỗ tiện ích rất mượt mà.',
            'Cần có giải pháp xử lý nghiêm các trường hợp chủ nuôi chó thả rông không rọ mõm.',
            'Thời gian súc xả bể nước ngầm nên báo trước ít nhất 3 ngày để cư dân chủ động tích trữ nước.',
            'Khen ngợi đội ngũ lao công làm việc rất chăm chỉ, hành lang tầng luôn sạch sẽ thơm mát.',
        ];

        $totalCreatedResponses = 0;

        foreach ($surveysConfig as $sConfig) {
            // 1. Tạo hoặc lấy Survey
            $surveyRecord = DB::table('satisfaction_surveys')->where('survey_code', $sConfig['code'])->first();
            if (! $surveyRecord) {
                $surveyId = (string) Str::uuid();
                DB::table('satisfaction_surveys')->insert([
                    'id' => $surveyId,
                    'survey_code' => $sConfig['code'],
                    'title' => $sConfig['title'],
                    'description' => $sConfig['desc'],
                    'target_block_id' => null,
                    'start_date' => $sConfig['start'],
                    'end_date' => $sConfig['end'],
                    'is_active' => true,
                    'created_by' => $adminId,
                    'version' => 1,
                    'created_at' => Carbon::parse($sConfig['start'])->setTime(8, 0),
                    'updated_at' => Carbon::parse($sConfig['start'])->setTime(8, 0),
                    'eligible_apartment_ids' => null,
                ]);
            } else {
                $surveyId = $surveyRecord->id;
            }

            // 2. Tạo câu hỏi cho Survey
            $questions = [];
            foreach ($sConfig['questions'] as $q) {
                $existingQ = DB::table('survey_questions')
                    ->where('survey_id', $surveyId)
                    ->where('question_order', $q['order'])
                    ->first();

                if (! $existingQ) {
                    $qId = (string) Str::uuid();
                    DB::table('survey_questions')->insert([
                        'id' => $qId,
                        'survey_id' => $surveyId,
                        'question_order' => $q['order'],
                        'question_text' => $q['text'],
                        'question_type' => $q['type'],
                        'options_json' => json_encode($q['opts'], JSON_UNESCAPED_UNICODE),
                        'is_required' => true,
                        'created_at' => Carbon::parse($sConfig['start'])->setTime(8, 0),
                        'updated_at' => Carbon::parse($sConfig['start'])->setTime(8, 0),
                    ]);
                    $questions[] = [
                        'id' => $qId,
                        'type' => $q['type'],
                        'options' => $q['opts'],
                    ];
                } else {
                    $questions[] = [
                        'id' => $existingQ->id,
                        'type' => $existingQ->question_type,
                        'options' => json_decode($existingQ->options_json, true) ?: [],
                    ];
                }
            }

            // 3. Tạo responses cho Survey này
            // Lấy các căn hộ chưa phản hồi survey này để tránh vi phạm unique(['survey_id', 'apartment_id'])
            $alreadyRespondedAptIds = DB::table('survey_responses')
                ->where('survey_id', $surveyId)
                ->pluck('apartment_id')
                ->all();

            $availableApts = $apartments->whereNotIn('id', $alreadyRespondedAptIds)->values();
            $responsesToCreate = min($sConfig['target_responses'], $availableApts->count());

            $responsesBatch = [];
            $detailsBatch = [];
            $batchSize = 100;

            for ($r = 0; $r < $responsesToCreate; $r++) {
                $responseId = (string) Str::uuid();
                $apt = $availableApts[$r];

                // Tìm cư dân tương ứng căn hộ nếu có
                $matchedResident = $residentRows->firstWhere('apartment_id', $apt->id);
                $userId = $matchedResident ? $matchedResident->user_id : $allUserIds[array_rand($allUserIds)];

                // Thời gian nộp khảo sát nằm trong khoảng start_date và end_date
                $startDate = Carbon::parse($sConfig['start']);
                $endDate = Carbon::parse($sConfig['end']);
                $maxSubmitDate = $now->lt($endDate) ? $now : $endDate;
                $diffDays = max(1, $startDate->diffInDays($maxSubmitDate));
                $submittedAt = $startDate->copy()->addDays(rand(0, $diffDays))->setTime(rand(7, 22), rand(0, 59));

                $responsesBatch[] = [
                    'id' => $responseId,
                    'survey_id' => $surveyId,
                    'resident_user_id' => $userId,
                    'apartment_id' => $apt->id,
                    'idempotency_key' => null,
                    'submitted_at' => $submittedAt,
                ];

                // Chi tiết câu trả lời
                foreach ($questions as $q) {
                    $numericScore = null;
                    $selectedOption = null;
                    $textAnswer = null;

                    if ($q['type'] === 'RATING_1_TO_5') {
                        $starRand = rand(1, 100);
                        $numericScore = $starRand <= 60 ? 5 : ($starRand <= 85 ? 4 : ($starRand <= 95 ? 3 : rand(1, 2)));
                    } elseif ($q['type'] === 'YES_NO') {
                        $selectedOption = (rand(1, 100) <= 85) ? ($q['options'][0] ?? 'Có') : ($q['options'][1] ?? 'Không');
                    } elseif ($q['type'] === 'MULTIPLE_CHOICE') {
                        $opts = $q['options'];
                        $selectedOption = ! empty($opts) ? $opts[array_rand($opts)] : null;
                    } elseif ($q['type'] === 'FREE_TEXT') {
                        $textAnswer = $freeTextPool[array_rand($freeTextPool)];
                    }

                    $detailsBatch[] = [
                        'id' => (string) Str::uuid(),
                        'response_id' => $responseId,
                        'question_id' => $q['id'],
                        'numeric_score' => $numericScore,
                        'selected_option' => $selectedOption,
                        'text_answer' => $textAnswer,
                        'created_at' => $submittedAt,
                    ];
                }

                if (count($responsesBatch) >= $batchSize) {
                    DB::table('survey_responses')->insert($responsesBatch);
                    DB::table('survey_response_details')->insert($detailsBatch);
                    $responsesBatch = [];
                    $detailsBatch = [];
                }
            }

            if (! empty($responsesBatch)) {
                DB::table('survey_responses')->insert($responsesBatch);
                DB::table('survey_response_details')->insert($detailsBatch);
            }

            $totalCreatedResponses += $responsesToCreate;
        }

        $finalSurveyCount = DB::table('satisfaction_surveys')->count();
        $finalQCount = DB::table('survey_questions')->count();
        $finalResponseCount = DB::table('survey_responses')->count();
        $finalDetailCount = DB::table('survey_response_details')->count();

        $this->command?->info("Đã seed thành công! Tổng surveys: {$finalSurveyCount}, questions: {$finalQCount}, survey_responses: {$finalResponseCount}, survey_response_details: {$finalDetailCount}");
    }
}
