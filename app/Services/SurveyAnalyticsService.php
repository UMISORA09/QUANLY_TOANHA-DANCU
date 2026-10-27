<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponseDetail;
use App\Models\User;

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
        // 1. Total survey responses
        $totalResponses = $survey->responses()->count();

        // 2. Determine eligible apartments denominator
        $eligibleCountQuery = Apartment::query()->whereNull('deleted_at');
        if ($survey->target_block_id) {
            $eligibleCountQuery->where('block_id', $survey->target_block_id);
        }
        $eligibleApartmentsCount = max(1, $eligibleCountQuery->count());

        $participationRate = $totalResponses > 0
            ? round(($totalResponses / $eligibleApartmentsCount) * 100, 2)
            : 0.0;

        // 3. Question-level statistics
        $questions = $survey->questions()->orderBy('question_order', 'asc')->get();
        $detailsByQuestion = SurveyResponseDetail::whereIn('question_id', $questions->pluck('id'))->get()->groupBy('question_id');
        $questionStats = [];

        $allRatingScores = [];

        foreach ($questions as $question) {
            $details = $detailsByQuestion->get($question->id, collect());
            $answersCount = $details->count();

            $stat = [
                'question_id' => $question->id,
                'question_order' => $question->question_order,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'is_required' => $question->is_required,
                'total_answers' => $answersCount,
            ];

            if ($question->question_type === SurveyQuestion::TYPE_RATING_1_TO_5) {
                $validRatings = $details->filter(fn ($d) => $d->numeric_score !== null && $d->numeric_score >= 1 && $d->numeric_score <= 5);
                $validCount = $validRatings->count();

                if ($validCount > 0) {
                    $sum = (float) $validRatings->sum('numeric_score');
                    $avgRating = round($sum / $validCount, 2);

                    // CSAT: Rating 4 & 5 count
                    $satisfiedCount = $validRatings->filter(fn ($d) => $d->numeric_score >= 4)->count();
                    $csatPercent = round(($satisfiedCount / $validCount) * 100, 2);

                    // Distribution 1 to 5
                    $counts = [
                        1 => $validRatings->where('numeric_score', 1)->count(),
                        2 => $validRatings->where('numeric_score', 2)->count(),
                        3 => $validRatings->where('numeric_score', 3)->count(),
                        4 => $validRatings->where('numeric_score', 4)->count(),
                        5 => $validRatings->where('numeric_score', 5)->count(),
                    ];

                    // Sentiment breakdown: Satisfied (4-5), Neutral (3), Dissatisfied (1-2)
                    $neutralCount = $counts[3];
                    $dissatisfiedCount = $counts[1] + $counts[2];

                    $stat['average_rating'] = $avgRating;
                    $stat['csat_percentage'] = $csatPercent;
                    $stat['satisfied_count'] = $satisfiedCount;
                    $stat['neutral_count'] = $neutralCount;
                    $stat['dissatisfied_count'] = $dissatisfiedCount;
                    $stat['rating_distribution'] = [
                        ['stars' => 1, 'count' => $counts[1], 'percent' => round(($counts[1] / $validCount) * 100, 1)],
                        ['stars' => 2, 'count' => $counts[2], 'percent' => round(($counts[2] / $validCount) * 100, 1)],
                        ['stars' => 3, 'count' => $counts[3], 'percent' => round(($counts[3] / $validCount) * 100, 1)],
                        ['stars' => 4, 'count' => $counts[4], 'percent' => round(($counts[4] / $validCount) * 100, 1)],
                        ['stars' => 5, 'count' => $counts[5], 'percent' => round(($counts[5] / $validCount) * 100, 1)],
                    ];

                    foreach ($validRatings as $vr) {
                        $allRatingScores[] = $vr->numeric_score;
                    }
                } else {
                    $stat['average_rating'] = 0.0;
                    $stat['csat_percentage'] = 0.0;
                    $stat['satisfied_count'] = 0;
                    $stat['neutral_count'] = 0;
                    $stat['dissatisfied_count'] = 0;
                    $stat['rating_distribution'] = [
                        ['stars' => 1, 'count' => 0, 'percent' => 0.0],
                        ['stars' => 2, 'count' => 0, 'percent' => 0.0],
                        ['stars' => 3, 'count' => 0, 'percent' => 0.0],
                        ['stars' => 4, 'count' => 0, 'percent' => 0.0],
                        ['stars' => 5, 'count' => 0, 'percent' => 0.0],
                    ];
                    $stat['notice'] = 'Chưa đủ dữ liệu để thống kê';
                }
            } elseif ($question->question_type === SurveyQuestion::TYPE_YES_NO) {
                $yesCount = $details->filter(fn ($d) => in_array(strtoupper(trim((string) $d->selected_option)), ['YES', 'CÓ', 'CO', '1'], true))->count();
                $noCount = $details->filter(fn ($d) => in_array(strtoupper(trim((string) $d->selected_option)), ['NO', 'KHÔNG', 'KHONG', '0'], true))->count();
                $totalYesNo = $yesCount + $noCount;

                $stat['yes_count'] = $yesCount;
                $stat['no_count'] = $noCount;
                $stat['yes_percentage'] = $totalYesNo > 0 ? round(($yesCount / $totalYesNo) * 100, 1) : 0.0;
                $stat['no_percentage'] = $totalYesNo > 0 ? round(($noCount / $totalYesNo) * 100, 1) : 0.0;
            } elseif ($question->question_type === SurveyQuestion::TYPE_MULTIPLE_CHOICE) {
                $options = is_array($question->options_json) ? $question->options_json : [];
                $distribution = [];

                foreach ($options as $opt) {
                    $c = $details->filter(fn ($d) => trim((string) $d->selected_option) === trim((string) $opt))->count();
                    $distribution[] = [
                        'option' => $opt,
                        'count' => $c,
                        'percent' => $answersCount > 0 ? round(($c / $answersCount) * 100, 1) : 0.0,
                    ];
                }

                $stat['options_distribution'] = $distribution;
            } elseif ($question->question_type === SurveyQuestion::TYPE_FREE_TEXT) {
                // Paginated/clean text feedback excluding resident IDs for privacy
                $stat['text_responses'] = $details
                    ->filter(fn ($d) => ! empty(trim((string) $d->text_answer)))
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'answer' => $d->text_answer,
                        'created_at' => $d->created_at?->format('d/m/Y H:i') ?? '',
                    ])
                    ->values()
                    ->take(50);
            }

            $questionStats[] = $stat;
        }

        // Overall CSAT for this survey
        $overallValidRatingCount = count($allRatingScores);
        if ($overallValidRatingCount > 0) {
            $sum = array_sum($allRatingScores);
            $overallAvgRating = round($sum / $overallValidRatingCount, 2);
            $satisfiedTotal = count(array_filter($allRatingScores, fn ($s) => $s >= 4));
            $overallCsatPercent = round(($satisfiedTotal / $overallValidRatingCount) * 100, 2);
            $neutralTotal = count(array_filter($allRatingScores, fn ($s) => $s === 3));
            $dissatisfiedTotal = count(array_filter($allRatingScores, fn ($s) => $s <= 2));
        } else {
            $overallAvgRating = 0.0;
            $overallCsatPercent = 0.0;
            $satisfiedTotal = 0;
            $neutralTotal = 0;
            $dissatisfiedTotal = 0;
        }

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
        $allRatingScores = SurveyResponseDetail::whereIn('question_id', $questionIds)
            ->whereNotNull('numeric_score')
            ->whereBetween('numeric_score', [1, 5])
            ->pluck('numeric_score')->all();

        $totalValidRatings = count($allRatingScores);
        if ($totalValidRatings > 0) {
            $overallAvgRating = round(array_sum($allRatingScores) / $totalValidRatings, 2);
            $satisfiedCount = count(array_filter($allRatingScores, fn ($s) => $s >= 4));
            $overallCsat = round(($satisfiedCount / $totalValidRatings) * 100, 2);
            $dissatisfiedCount = count(array_filter($allRatingScores, fn ($s) => $s <= 2));
        } else {
            $overallAvgRating = 0.0;
            $overallCsat = 0.0;
            $dissatisfiedCount = 0;
        }

        return [
            'total_surveys' => $totalSurveys,
            'active_surveys' => $activeSurveys,
            'total_responses' => $totalResponses,
            'overall_average_rating' => $overallAvgRating,
            'overall_csat_percentage' => $overallCsat,
            'total_valid_ratings' => $totalValidRatings,
            'dissatisfied_count' => $dissatisfiedCount,
        ];
    }
}
