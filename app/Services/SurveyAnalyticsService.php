<?php

namespace App\Services;

use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponseDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SurveyAnalyticsService
{
    public function __construct(
        protected SurveyAccessService $accessService
    ) {}

    /**
     * Compute comprehensive analytics for a single survey.
     *
     * @return array<string, mixed>
     */
    public function getSurveyAnalytics(SatisfactionSurvey $survey, User $user): array
    {
        abort_unless($this->accessService->canViewBlock($user, $survey->target_block_id), 403);

        // 1. Total survey responses
        $totalResponses = $survey->responses()->count();

        // 2. Determine eligible apartments denominator
        $eligibleApartmentsCount = count($survey->eligible_apartment_ids ?? $this->accessService->eligibleApartmentIds($survey->target_block_id));
        $participationRate = $eligibleApartmentsCount > 0 ? round(($totalResponses / $eligibleApartmentsCount) * 100, 2) : null;

        $questions = $survey->questions()->orderBy('question_order')->get();
        $groups = SurveyResponseDetail::whereIn('question_id', $questions->pluck('id'))
            ->selectRaw('question_id, numeric_score, selected_option, COUNT(*) AS answer_count')
            ->groupBy('question_id', 'numeric_score', 'selected_option')->get()->groupBy('question_id');
        $textQuestions = $questions->where('question_type', SurveyQuestion::TYPE_FREE_TEXT)->pluck('id');
        $texts = collect();
        if ($textQuestions->isNotEmpty()) {
            $ranked = DB::table('survey_response_details')->whereIn('question_id', $textQuestions)
                ->whereNotNull('text_answer')->whereRaw("TRIM(text_answer) <> ''")
                ->selectRaw('id, question_id, text_answer, created_at, ROW_NUMBER() OVER (PARTITION BY question_id ORDER BY created_at DESC, id DESC) AS position');
            $texts = DB::query()->fromSub($ranked, 'ranked')->where('position', '<=', 50)->orderBy('position')->get()->groupBy('question_id');
        }
        $questionStats = [];
        $overallValidRatingCount = $sumScores = $satisfiedTotal = $neutralTotal = $dissatisfiedTotal = 0;
        foreach ($questions as $question) {
            $answers = $groups->get($question->id, collect());
            $count = (int) $answers->sum('answer_count');
            $stat = ['question_id' => $question->id, 'question_order' => $question->question_order,
                'question_text' => $question->question_text, 'question_type' => $question->question_type,
                'is_required' => $question->is_required, 'total_answers' => $count];
            if ($question->question_type === SurveyQuestion::TYPE_RATING_1_TO_5) {
                $distribution = array_fill(1, 5, 0);
                foreach ($answers as $answer) {
                    if ($answer->numeric_score !== null && $answer->numeric_score >= 1 && $answer->numeric_score <= 5) {
                        $distribution[(int) $answer->numeric_score] += (int) $answer->answer_count;
                    }
                }
                $validCount = array_sum($distribution);
                $sum = 0;
                foreach ($distribution as $score => $scoreCount) {
                    $sum += $score * $scoreCount;
                }
                $satisfied = $distribution[4] + $distribution[5];
                $dissatisfied = $distribution[1] + $distribution[2];
                $stat += ['data_status' => $validCount > 0 ? 'AVAILABLE' : 'NO_DATA',
                    'average_rating' => $validCount ? round($sum / $validCount, 2) : 0.0,
                    'csat_percentage' => $validCount ? round($satisfied / $validCount * 100, 2) : 0.0,
                    'satisfied_count' => $satisfied, 'neutral_count' => $distribution[3], 'dissatisfied_count' => $dissatisfied,
                    'rating_distribution' => array_map(fn (int $score): array => ['stars' => $score,
                        'count' => $distribution[$score], 'percent' => $validCount ? round($distribution[$score] / $validCount * 100, 1) : 0.0], range(1, 5))];
                if (! $validCount) {
                    $stat['notice'] = 'Chưa đủ dữ liệu để thống kê';
                }
                $overallValidRatingCount += $validCount;
                $sumScores += $sum;
                $satisfiedTotal += $satisfied;
                $neutralTotal += $distribution[3];
                $dissatisfiedTotal += $dissatisfied;
            } elseif ($question->question_type === SurveyQuestion::TYPE_YES_NO) {
                $yes = (int) $answers->filter(fn ($a) => in_array(strtoupper(trim((string) $a->selected_option)), ['YES', 'CÓ', 'CO', '1'], true))->sum('answer_count');
                $no = (int) $answers->filter(fn ($a) => in_array(strtoupper(trim((string) $a->selected_option)), ['NO', 'KHÔNG', 'KHONG', '0'], true))->sum('answer_count');
                $stat += ['yes_count' => $yes, 'no_count' => $no, 'yes_percentage' => $yes + $no ? round($yes / ($yes + $no) * 100, 1) : 0.0,
                    'no_percentage' => $yes + $no ? round($no / ($yes + $no) * 100, 1) : 0.0];
            } elseif ($question->question_type === SurveyQuestion::TYPE_MULTIPLE_CHOICE) {
                $stat['options_distribution'] = array_map(function (string $option) use ($answers, $count): array {
                    $total = (int) $answers->where('selected_option', $option)->sum('answer_count');

                    return ['option' => $option, 'count' => $total, 'percent' => $count ? round($total / $count * 100, 1) : 0.0];
                }, is_array($question->options_json) ? $question->options_json : []);
            } elseif ($question->question_type === SurveyQuestion::TYPE_FREE_TEXT) {
                $stat['text_responses'] = $texts->get($question->id, collect())->map(fn ($row): array => [
                    'id' => $row->id, 'answer' => $row->text_answer, 'created_at' => $row->created_at ? Carbon::parse($row->created_at)->format('d/m/Y H:i') : '',
                ])->values();
            }
            $questionStats[] = $stat;
        }
        $overallAvgRating = $overallValidRatingCount ? round($sumScores / $overallValidRatingCount, 2) : 0.0;
        $overallCsatPercent = $overallValidRatingCount ? round($satisfiedTotal / $overallValidRatingCount * 100, 2) : 0.0;

        return [
            'survey' => [
                'id' => $survey->id,
                'survey_code' => $survey->survey_code,
                'title' => $survey->title,
                'description' => $survey->description,
                'target_block_id' => $survey->target_block_id,
                'target_block_name' => $survey->targetBlock?->block_name ?? 'Toàn khu dân cư',
                'start_date' => $survey->start_date?->format('Y-m-d'),
                'end_date' => $survey->end_date?->format('Y-m-d'),
                'is_active' => (bool) $survey->is_active,
                'computed_status' => $survey->computed_status,
                'version' => $survey->version,
            ],
            'kpis' => [
                'total_responses' => $totalResponses,
                'eligible_apartments_count' => $eligibleApartmentsCount,
                'participation_rate_percent' => $participationRate,
                'eligibility_basis' => $survey->eligible_apartment_ids === null ? 'LEGACY_CURRENT_RESIDENCY' : 'FROZEN_AT_CREATION',
                'data_status' => $overallValidRatingCount > 0 ? 'AVAILABLE' : 'NO_DATA',
                'overall_average_rating' => $overallAvgRating,
                'overall_csat_percentage' => $overallCsatPercent,
                'total_valid_ratings' => $overallValidRatingCount,
                'satisfied_ratings_count' => $satisfiedTotal,
                'neutral_ratings_count' => $neutralTotal,
                'dissatisfied_ratings_count' => $dissatisfiedTotal,
            ],
            'question_stats' => $questionStats,
        ];
    }

    /**
     * Get aggregate KPIs across all accessible surveys for the management dashboard header.
     *
     * @return array<string, mixed>
     */
    public function getManagementOverviewKpis(User $user): array
    {
        $query = SatisfactionSurvey::query();
        $this->accessService->scopeForManager($user, $query);

        $surveys = $query->with('questions')->withCount('responses')->get();

        $totalSurveys = $surveys->count();
        $activeSurveys = $surveys->filter(fn ($s) => $s->isOpen())->count();

        $totalResponses = (int) $surveys->sum('responses_count');
        $questionIds = $surveys->flatMap(fn (SatisfactionSurvey $survey) => $survey->questions
            ->where('question_type', SurveyQuestion::TYPE_RATING_1_TO_5)->pluck('id'));
        $totals = SurveyResponseDetail::whereIn('question_id', $questionIds)->whereBetween('numeric_score', [1, 5])
            ->selectRaw('COUNT(*) AS total, AVG(numeric_score) AS average_score, SUM(numeric_score >= 4) AS satisfied, SUM(numeric_score <= 2) AS dissatisfied')->first();
        $totalValidRatings = (int) $totals->total;
        $overallAvgRating = $totalValidRatings ? round((float) $totals->average_score, 2) : 0.0;
        $overallCsat = $totalValidRatings ? round((int) $totals->satisfied / $totalValidRatings * 100, 2) : 0.0;
        $dissatisfiedCount = (int) $totals->dissatisfied;

        return [
            'total_surveys' => $totalSurveys,
            'active_surveys' => $activeSurveys,
            'total_responses' => $totalResponses,
            'overall_average_rating' => $overallAvgRating,
            'overall_csat_percentage' => $overallCsat,
            'data_status' => $totalValidRatings > 0 ? 'AVAILABLE' : 'NO_DATA',
            'total_valid_ratings' => $totalValidRatings,
            'dissatisfied_count' => $dissatisfiedCount,
        ];
    }
}
