<?php

namespace App\Http\Controllers;

use App\Models\SatisfactionSurvey;
use App\Services\SatisfactionSurveyService;
use App\Services\SurveyAccessService;
use App\Services\SurveyAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementSurveyController extends Controller
{
    public function __construct(
        protected SatisfactionSurveyService $surveyService,
        protected SurveyAnalyticsService $analyticsService,
        protected SurveyAccessService $accessService
    ) {}

    /**
     * List surveys with filters & pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền truy cập quản lý khảo sát.');

        $filters = $request->only(['search', 'target_block_id', 'status']);
        $perPage = min(50, max(5, (int) $request->input('per_page', 15)));

        $paginator = $this->surveyService->listSurveys($filters, $user, $perPage);
        $overviewKpis = $this->analyticsService->getManagementOverviewKpis($user);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'kpis' => $overviewKpis,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Get aggregate overview KPIs for top cards.
     */
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền xem thống kê.');

        $kpis = $this->analyticsService->getManagementOverviewKpis($user);

        return response()->json([
            'status' => 'success',
            'data' => $kpis,
        ]);
    }

    /**
     * Create a new satisfaction survey with questions.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền tạo khảo sát.');

        $validated = $request->validate([
            'survey_code' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_block_id' => ['nullable', 'string', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'is_active' => ['nullable', 'boolean'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['required', 'string', 'max:500'],
            'questions.*.question_type' => ['required', 'string', 'in:RATING_1_TO_5,YES_NO,MULTIPLE_CHOICE,FREE_TEXT'],
            'questions.*.options_json' => ['nullable', 'array'],
            'questions.*.options_json.*' => ['string', 'max:200'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.question_order' => ['nullable', 'integer'],
        ]);

        $survey = $this->surveyService->createSurvey($validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Chiến dịch khảo sát đã được tạo thành công.',
            'data' => $survey,
        ], 201);
    }

    /**
     * Show survey details with questions.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền xem khảo sát.');

        $survey = SatisfactionSurvey::with(['targetBlock', 'questions'])->findOrFail($id);

        $assignedBlockId = $this->accessService->assignedBlock($user);
        if ($assignedBlockId && $survey->target_block_id && $survey->target_block_id !== $assignedBlockId) {
            abort(403, 'Bạn không có quyền xem khảo sát của tòa nhà khác.');
        }

        $survey->setAttribute('computed_status', $survey->computed_status);

        return response()->json([
            'status' => 'success',
            'data' => $survey,
        ]);
    }

    /**
     * Update an existing survey with OCC check.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền chỉnh sửa khảo sát.');

        $survey = SatisfactionSurvey::findOrFail($id);

        $validated = $request->validate([
            'version' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_block_id' => ['nullable', 'string'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.question_text' => ['required_with:questions', 'string', 'max:500'],
            'questions.*.question_type' => ['required_with:questions', 'string', 'in:RATING_1_TO_5,YES_NO,MULTIPLE_CHOICE,FREE_TEXT'],
            'questions.*.options_json' => ['nullable', 'array'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.question_order' => ['nullable', 'integer'],
        ]);

        $updated = $this->surveyService->updateSurvey($survey, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật chiến dịch khảo sát thành công.',
            'data' => $updated,
        ]);
    }

    /**
     * Toggle active state.
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền thay đổi trạng thái khảo sát.');

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $survey = SatisfactionSurvey::findOrFail($id);
        $updated = $this->surveyService->toggleActive($survey, (bool) $validated['is_active'], $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Trạng thái khảo sát đã được cập nhật.',
            'data' => $updated,
        ]);
    }

    /**
     * Get detailed CSAT and statistical analytics.
     */
    public function analytics(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->accessService->isManager($user), 403, 'Bạn không có quyền xem thống kê CSAT.');

        $survey = SatisfactionSurvey::with(['targetBlock'])->findOrFail($id);

        $assignedBlockId = $this->accessService->assignedBlock($user);
        if ($assignedBlockId && $survey->target_block_id && $survey->target_block_id !== $assignedBlockId) {
            abort(403, 'Bạn không có quyền xem phân tích của tòa nhà khác.');
        }

        $analytics = $this->analyticsService->getSurveyAnalytics($survey, $user);

        return response()->json([
            'status' => 'success',
            'data' => $analytics,
        ]);
    }
}
