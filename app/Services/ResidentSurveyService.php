<?php

namespace App\Services;

use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyResponseDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidentSurveyService
{
    public function __construct(
        protected SurveyAccessService $accessService
    ) {}

    /**
     * Get list of active surveys available for a resident.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActiveSurveysForResident(User $user): Collection
    {
        $apartments = $this->accessService->residentApartments($user);
        if ($apartments->isEmpty()) {
            return collect();
        }

        $blockIds = $apartments->pluck('block_id')->unique()->all();
        $apartmentIds = $apartments->pluck('id')->unique()->all();
        $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();

        $surveys = SatisfactionSurvey::query()
            ->with(['targetBlock', 'questions'])
            ->where('is_active', 1)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where(function ($q) use ($blockIds): void {
                $q->whereNull('target_block_id')->orWhereIn('target_block_id', $blockIds);
            })
            ->orderBy('start_date', 'desc')
            ->get();

        // Check which surveys the resident has already submitted
        $submittedSurveyIds = SurveyResponse::whereIn('apartment_id', $apartmentIds)
            ->pluck('survey_id')
            ->all();

        return $surveys->map(function (SatisfactionSurvey $survey) use ($submittedSurveyIds) {
            $isSubmitted = in_array($survey->id, $submittedSurveyIds, true);
            $endDate = Carbon::parse($survey->end_date, 'Asia/Ho_Chi_Minh');
            $daysLeft = max(0, Carbon::now('Asia/Ho_Chi_Minh')->diffInDays($endDate, false));

            return [
                'id' => $survey->id,
                'survey_code' => $survey->survey_code,
                'title' => $survey->title,
                'description' => $survey->description,
                'target_block_name' => $survey->targetBlock?->block_name ?? 'Toàn khu đô thị',
                'start_date' => $survey->start_date?->format('Y-m-d'),
                'end_date' => $survey->end_date?->format('Y-m-d'),
                'days_left' => (int) $daysLeft,
                'total_questions' => $survey->questions->count(),
                'has_submitted' => $isSubmitted,
                'is_open' => $survey->isOpen(),
            ];
        });
    }

    /**
     * Get a specific survey with its questions for answering.
     *
     * @return array<string, mixed>
     */
    public function getSurveyDetails(SatisfactionSurvey $survey, User $user): array
    {
        abort_unless($this->accessService->canResidentAccessSurvey($user, $survey), 403, 'Khảo sát này không áp dụng cho căn hộ hoặc tòa nhà của bạn.');

        $apartments = $this->accessService->residentApartments($user);
        $apartmentIds = $apartments->pluck('id')->all();

        $existingResponse = SurveyResponse::where('survey_id', $survey->id)
            ->whereIn('apartment_id', $apartmentIds)
            ->first();

        return [
            'survey' => [
                'id' => $survey->id,
                'survey_code' => $survey->survey_code,
                'title' => $survey->title,
                'description' => $survey->description,
                'target_block_name' => $survey->targetBlock?->block_name ?? 'Toàn khu đô thị',
                'start_date' => $survey->start_date?->format('Y-m-d'),
                'end_date' => $survey->end_date?->format('Y-m-d'),
                'is_open' => $survey->isOpen(),
                'has_submitted' => $existingResponse !== null,
                'submitted_at' => $existingResponse?->submitted_at?->format('d/m/Y H:i'),
            ],
            'questions' => $survey->questions()->orderBy('question_order', 'asc')->get()->map(fn (SurveyQuestion $q) => [
                'id' => $q->id,
                'question_order' => $q->question_order,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'options' => is_array($q->options_json) ? $q->options_json : [],
                'is_required' => (bool) $q->is_required,
            ]),
        ];
    }

    /**
     * Submit a survey response with anti-duplicate and consistency validation.
     *
     * @param  array<string, mixed>  $answers  Keyed by question_id
     */
    public function submitResponse(SatisfactionSurvey $survey, User $user, array $answers, ?string $idempotencyKey = null): SurveyResponse
    {
        // 1. Check resident access eligibility
        abort_unless($this->accessService->canResidentAccessSurvey($user, $survey), 403, 'Bạn không thuộc phạm vi đối tượng của cuộc khảo sát này.');

        // 2. Check survey availability
        if (! $survey->isOpen()) {
            abort(422, 'Chiến dịch khảo sát này hiện không trong thời gian nhận phản hồi hoặc đã kết thúc.');
        }

        // 3. Resolve resident apartment
        $apartment = $this->accessService->residentApartmentForSurvey($user, $survey);
        if (! $apartment) {
            abort(422, 'Không tìm thấy thông tin căn hộ hợp lệ để gửi khảo sát.');
        }

        // 4. Check for duplicate submission by this apartment
        $existing = SurveyResponse::where('survey_id', $survey->id)
            ->where('apartment_id', $apartment->id)
            ->exists();

        if ($existing) {
            abort(409, 'Căn hộ của bạn đã nộp phiếu đánh giá cho khảo sát này. Mỗi căn hộ chỉ được gửi một lần.');
        }

        // 5. Validate answers against survey questions
        $questions = $survey->questions()->get()->keyBy('id');
        $validatedDetails = [];

        foreach ($questions as $questionId => $question) {
            $userAnswer = $answers[$questionId] ?? null;

            // Check required
            if ($question->is_required && ($userAnswer === null || (is_string($userAnswer) && trim($userAnswer) === ''))) {
                abort(422, "Vui lòng trả lời câu hỏi bắt buộc: '{$question->question_text}'");
            }

            if ($userAnswer === null || (is_string($userAnswer) && trim($userAnswer) === '')) {
                continue;
            }

            $detail = [
                'id' => (string) Str::uuid(),
                'question_id' => $question->id,
                'numeric_score' => null,
                'selected_option' => null,
                'text_answer' => null,
            ];

            if ($question->question_type === SurveyQuestion::TYPE_RATING_1_TO_5) {
                if (! is_numeric($userAnswer) || (int) $userAnswer != $userAnswer) {
                    abort(422, "Điểm đánh giá câu hỏi #{$question->question_order} phải là số nguyên từ 1 đến 5 sao.");
                }
                $score = (int) $userAnswer;
                if ($score < 1 || $score > 5) {
                    abort(422, "Điểm đánh giá câu hỏi #{$question->question_order} phải nằm trong khoảng từ 1 đến 5 sao.");
                }
                $detail['numeric_score'] = $score;
            } elseif ($question->question_type === SurveyQuestion::TYPE_YES_NO) {
                $ansRaw = strtoupper(trim((string) $userAnswer));
                $normalized = match ($ansRaw) {
                    'YES', 'CO', 'CÓ', '1', 'TRUE' => 'YES',
                    'NO', 'KHONG', 'KHÔNG', '0', 'FALSE' => 'NO',
                    default => null,
                };
                if ($normalized === null) {
                    abort(422, "Câu trả lời cho câu hỏi #{$question->question_order} phải là Có hoặc Không.");
                }
                $detail['selected_option'] = $normalized;
            } elseif ($question->question_type === SurveyQuestion::TYPE_MULTIPLE_CHOICE) {
                $ansStr = trim((string) $userAnswer);
                $options = is_array($question->options_json) ? $question->options_json : [];
                if (! empty($options) && ! in_array($ansStr, $options, true)) {
                    abort(422, "Lựa chọn không hợp lệ cho câu hỏi: '{$question->question_text}'");
                }
                $detail['selected_option'] = $ansStr;
            } elseif ($question->question_type === SurveyQuestion::TYPE_FREE_TEXT) {
                $text = trim((string) $userAnswer);
                if (mb_strlen($text) > 1000) {
                    abort(422, 'Nội dung câu trả lời tự luận không được vượt quá 1000 ký tự.');
                }
                $detail['text_answer'] = $text;
            }

            $validatedDetails[] = $detail;
        }

        // 6. Atomically save response and details
        try {
            return DB::transaction(function () use ($survey, $user, $apartment, $idempotencyKey, $validatedDetails) {
                $response = SurveyResponse::create([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey->id,
                    'resident_user_id' => $user->id,
                    'apartment_id' => $apartment->id,
                    'idempotency_key' => $idempotencyKey,
                    'submitted_at' => now(),
                ]);

                foreach ($validatedDetails as $detail) {
                    $detail['response_id'] = $response->id;
                    $detail['created_at'] = now();
                    SurveyResponseDetail::create($detail);
                }

                return $response->load('details');
            });
        } catch (QueryException $e) {
            // MySQL error 1062 = Duplicate entry (covers unique_survey_apartment race condition)
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                abort(409, 'Xung đột: Căn hộ này vừa gửi phiếu đánh giá thành công.');
            }
            throw $e;
        }
    }
}
