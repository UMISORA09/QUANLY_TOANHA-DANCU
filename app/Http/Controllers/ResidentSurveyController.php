<?php

namespace App\Http\Controllers;

use App\Models\SatisfactionSurvey;
use App\Services\ResidentSurveyService;
use App\Services\SurveyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResidentSurveyController extends Controller
{
    public function __construct(
        protected ResidentSurveyService $residentSurveyService,
        protected SurveyAccessService $accessService
    ) {}

    /**
     * Get all active surveys available to the resident.
     */
    public function activeSurveys(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401, 'Vui lòng đăng nhập.');

        $surveys = $this->residentSurveyService->getActiveSurveysForResident($user);

        return response()->json([
            'status' => 'success',
            'data' => $surveys,
        ]);
    }

    /**
     * Get questions and details of a survey for taking the survey.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401, 'Vui lòng đăng nhập.');

        $survey = SatisfactionSurvey::findOrFail($id);
        $details = $this->residentSurveyService->getSurveyDetails($survey, $user);

        return response()->json([
            'status' => 'success',
            'data' => $details,
        ]);
    }

    /**
     * Submit responses for a survey.
     */
    public function submit(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401, 'Vui lòng đăng nhập.');

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $survey = SatisfactionSurvey::findOrFail($id);
        $response = $this->residentSurveyService->submitResponse(
            $survey,
            $user,
            $validated['answers'],
            $validated['idempotency_key'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Cảm ơn bạn đã hoàn thành bài khảo sát ý kiến!',
            'data' => [
                'response_id' => $response->id,
                'submitted_at' => $response->submitted_at?->format('d/m/Y H:i'),
            ],
        ], 201);
    }

    /**
     * Check submission status for a survey.
     */
    public function submissionStatus(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401, 'Vui lòng đăng nhập.');

        $survey = SatisfactionSurvey::findOrFail($id);
        $details = $this->residentSurveyService->getSurveyDetails($survey, $user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_submitted' => $details['survey']['has_submitted'],
                'submitted_at' => $details['survey']['submitted_at'] ?? null,
            ],
        ]);
    }
}
