<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResidentCreateTicketRequest;
use App\Http\Requests\ResidentUpdateTicketRequest;
use App\Models\TicketAttachment;
use App\Services\ResidentTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ResidentTicketController extends Controller
{
    public function __construct(
        public ResidentTicketService $ticketService
    ) {}

    /**
     * Lấy danh sách Ticket của cư dân kèm bộ lọc và phân trang.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:30',
            'category_id' => 'nullable|string|max:36',
            'priority' => 'nullable|string|max:20',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:5|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $result = $this->ticketService->list($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => $result['tickets']->items(),
            'pagination' => [
                'current_page' => $result['tickets']->currentPage(),
                'last_page' => $result['tickets']->lastPage(),
                'per_page' => $result['tickets']->perPage(),
                'total' => $result['tickets']->total(),
            ],
            'counters' => $result['counters'],
        ]);
    }

    /**
     * Lấy danh mục sự cố kỹ thuật.
     */
    public function categories(): JsonResponse
    {
        $categories = $this->ticketService->categories();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Tạo Ticket báo cáo sự cố mới.
     */
    public function store(ResidentCreateTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $files = $request->file('attachments');
        if ($files && ! is_array($files)) {
            $files = [$files];
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?: ($validated['idempotency_key'] ?? null);

        $ticket = $this->ticketService->create(
            user: $request->user(),
            data: $validated,
            files: $files,
            idempotencyKey: $idempotencyKey
        );

        return response()->json([
            'success' => true,
            'message' => 'Gửi yêu cầu báo cáo sự cố thành công! Ban quản lý sẽ tiếp nhận trong thời gian sớm nhất.',
            'data' => $ticket,
        ], 201);
    }

    /**
     * Xem thông tin chi tiết một Ticket.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ticketService->detail($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    /**
     * Cập nhật thông tin Ticket (chỉ khi đang ở NEW).
     */
    public function update(ResidentUpdateTicketRequest $request, string $id): JsonResponse
    {
        $ticket = $this->ticketService->update(
            user: $request->user(),
            ticketId: $id,
            data: $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật nội dung yêu cầu thành công.',
            'data' => $ticket,
        ]);
    }

    /**
     * Cư dân hủy Ticket.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $ticket = $this->ticketService->cancel(
            user: $request->user(),
            ticketId: $id,
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã hủy yêu cầu báo cáo sự cố thành công.',
            'data' => $ticket,
        ]);
    }

    /**
     * Cư dân xác nhận đã khắc phục xong (Chuyển RESOLVED sang CLOSED).
     */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $ticket = $this->ticketService->confirm(
            user: $request->user(),
            ticketId: $id,
            note: $validated['note'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Cảm ơn bạn đã xác nhận và nghiệm thu công tác sửa chữa.',
            'data' => $ticket,
        ]);
    }

    /**
     * Cư dân báo chưa khắc phục xong (Reopen từ RESOLVED sang IN_PROGRESS).
     */
    public function reopen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'Vui lòng cung cấp lý do sự cố chưa được xử lý triệt để.',
            'reason.min' => 'Lý do phản hồi phải có ít nhất 5 ký tự.',
        ]);

        $ticket = $this->ticketService->reopen(
            user: $request->user(),
            ticketId: $id,
            reason: $validated['reason']
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã gửi phản hồi. Kỹ thuật viên sẽ tiếp tục kiểm tra và xử lý lại sự cố.',
            'data' => $ticket,
        ]);
    }

    /**
     * Xem toàn bộ lịch sử trạng thái của Ticket.
     */
    public function history(Request $request, string $id): JsonResponse
    {
        $history = $this->ticketService->history($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Tải tệp đính kèm an toàn có kiểm tra quyền sở hữu.
     */
    public function downloadAttachment(Request $request, string $ticketId, string $attachmentId)
    {
        // Kiểm tra quyền sở hữu đối với ticket
        $this->ticketService->detail($request->user(), $ticketId);

        $attachment = TicketAttachment::where('ticket_id', $ticketId)->where('id', $attachmentId)->first();
        if (! $attachment) {
            abort(404, 'Không tìm thấy tệp đính kèm.');
        }

        if ($attachment->storage_path && Storage::disk('public')->exists($attachment->storage_path)) {
            return Storage::disk('public')->response($attachment->storage_path, $attachment->file_name);
        }

        abort(404, 'Tệp tin không tồn tại trên hệ thống lưu trữ.');
    }
}
