<?php

namespace App\Http\Controllers;

use App\Models\Suggestion;
use App\Services\AiSentimentService;
use App\Services\ManagementSuggestionService;
use App\Services\SuggestionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementSuggestionController extends Controller
{
    public function __construct(
        public ManagementSuggestionService $service,
        public SuggestionAccessService $accessService,
        public AiSentimentService $aiSentimentService
    ) {}

    /**
     * List suggestions with filters, search, pagination, and KPIs.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list($request->user(), $request->all());
        $paginator = $result['suggestions'];

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'kpis' => $result['kpis'],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Show detail of a single suggestion.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $suggestion = $this->service->detail($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => $suggestion,
        ]);
    }

    /**
     * Transition suggestion status (state machine + OCC).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:RECEIVED,REVIEWING,ACCEPTED,RESOLVED,REJECTED',
            'version' => 'required|integer|min:1',
            'rejection_reason' => 'nullable|string|max:2000',
        ], [
            'status.required' => 'Vui lòng chọn trạng thái mới.',
            'version.required' => 'Thiếu thông tin phiên bản kiểm soát tranh chấp (version).',
        ]);

        $suggestion = $this->service->updateStatus(
            $request->user(),
            $id,
            $data['status'],
            (int) $data['version'],
            $data['rejection_reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật trạng thái thành công.',
            'data' => $suggestion,
        ]);
    }

    /**
     * Submit official reply.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'official_response_text' => 'required|string|min:5|max:5000',
            'version' => 'required|integer|min:1',
            'status' => 'nullable|string|in:REVIEWING,ACCEPTED,RESOLVED,REJECTED',
        ], [
            'official_response_text.required' => 'Nội dung phản hồi không được để trống.',
            'official_response_text.min' => 'Nội dung phản hồi cần tối thiểu 5 ký tự.',
            'version.required' => 'Thiếu thông tin phiên bản dữ liệu (version).',
        ]);

        $suggestion = $this->service->reply(
            $request->user(),
            $id,
            $data['official_response_text'],
            (int) $data['version'],
            $data['status'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Gửi phản hồi chính thức thành công.',
            'data' => $suggestion,
        ]);
    }

    /**
     * Analyze AI sentiment for a suggestion.
     */
    public function analyzeSentiment(Request $request): JsonResponse
    {
        abort_unless(
            $this->accessService->isManager($request->user()) && $request->user()->hasPermission('SUGGESTION:VIEW'),
            403,
            'Bạn không có quyền yêu cầu phân tích cảm xúc góp ý.'
        );

        $data = $request->validate([
            'suggestion_id' => 'required|uuid|exists:suggestions,id',
        ]);

        $suggestion = Suggestion::findOrFail($data['suggestion_id']);

        $result = $this->aiSentimentService->analyzeSuggestion($suggestion);

        if ($result['status'] === 'BLOCKED') {
            return response()->json([
                'success' => false,
                'status' => 'BLOCKED',
                'message' => $result['message'],
                'data' => $suggestion->fresh(),
            ], 503);
        }

        if ($result['status'] === 'FAILED') {
            return response()->json([
                'success' => false,
                'status' => 'FAILED',
                'message' => $result['message'],
                'data' => $suggestion->fresh(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'status' => 'SUCCESS',
            'message' => 'Phân tích cảm xúc hoàn tất.',
            'data' => $suggestion->fresh(),
        ]);
    }

    /**
     * Create a new suggestion box.
     */
    public function storeBox(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box_name' => 'required|string|max:150',
            'block_id' => 'nullable|uuid|exists:blocks,id',
            'description' => 'nullable|string|max:1000',
            'is_open' => 'nullable|boolean',
        ]);

        $box = $this->service->createBox($request->user(), $data);

        return response()->json([
            'success' => true,
            'message' => 'Tạo hòm thư góp ý thành công.',
            'data' => $box,
        ], 201);
    }
}
