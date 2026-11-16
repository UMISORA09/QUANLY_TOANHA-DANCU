<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResidentCreateTicketRequest;
use App\Http\Requests\ResidentUpdateTicketRequest;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\ResidentTicketService;
use App\Services\TicketAccessService;
use App\Services\TicketAttachmentService;
use App\Services\TicketConflictException;
use App\Services\TicketManagementService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'reason' => 'required|string|min:5|max:500',
            'version' => 'required|integer|min:1',
        ]);

        $ticket = app(TicketWorkflowService::class)->perform($request->user(), $id, 'cancel', (int) $validated['version'], $validated, resident: true);

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
            'version' => 'required|integer|min:1',
        ]);

        $ticket = app(TicketWorkflowService::class)->perform($request->user(), $id, 'confirm', (int) $validated['version'], $validated, resident: true);

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
            'version' => 'required|integer|min:1',
        ], [
            'reason.required' => 'Vui lòng cung cấp lý do sự cố chưa được xử lý triệt để.',
            'reason.min' => 'Lý do phản hồi phải có ít nhất 5 ký tự.',
        ]);

        $ticket = app(TicketWorkflowService::class)->perform($request->user(), $id, 'reopen', (int) $validated['version'], $validated, resident: true);

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

        $disk = $attachment->storage_disk ?: 'public';
        if ($attachment->storage_path && Storage::disk($disk)->exists($attachment->storage_path)) {
            return Storage::disk($disk)->response($attachment->storage_path, $attachment->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        abort(404, 'Tệp tin không tồn tại trên hệ thống lưu trữ.');
    }

    public function addAttachments(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'attachments' => 'required|array|max:5', 'attachments.*' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);
        $created = [];
        try {
            DB::transaction(function () use ($request, $id, $data, &$created): void {
                $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->ticketService->detail($request->user(), $id);
                $proof = app(TicketAccessService::class)->technician($request->user()) && $ticket->current_technician_user_id === $request->user()->id && $ticket->status === 'IN_PROGRESS' && $request->user()->hasPermission('TICKET:RESOLVE');
                if (! $proof) {
                    app(TicketAccessService::class)->resident($request->user(), $ticket);
                }
                if ((! $proof && $ticket->status !== 'NEW') || $ticket->version !== (int) $data['version']) {
                    throw new TicketConflictException('Yêu cầu đã thay đổi hoặc không còn được sửa ảnh.');
                }
                app(TicketAttachmentService::class)->store($ticket, $request->user()->id, $request->file('attachments'), $created, $proof);
                $ticket->increment('version');
                app(TicketManagementService::class)->record($request->user(), $ticket, 'UPDATED', 'Bổ sung ảnh sự cố.');
            });
        } catch (\Throwable $exception) {
            app(TicketAttachmentService::class)->cleanup($created);
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $this->ticketService->detail($request->user(), $id)]);
    }

    public function removeAttachment(Request $request, string $id, string $attachmentId): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $id, $attachmentId, $data): void {
            $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->ticketService->detail($request->user(), $id);
            $proof = app(TicketAccessService::class)->technician($request->user()) && $ticket->current_technician_user_id === $request->user()->id && $ticket->status === 'IN_PROGRESS' && $request->user()->hasPermission('TICKET:RESOLVE');
            if (! $proof) {
                app(TicketAccessService::class)->resident($request->user(), $ticket);
            }
            if ((! $proof && $ticket->status !== 'NEW') || $ticket->version !== (int) $data['version']) {
                throw new TicketConflictException('Yêu cầu đã thay đổi hoặc không còn được sửa ảnh.');
            }
            $attachment = TicketAttachment::where('ticket_id', $id)->whereKey($attachmentId)->firstOrFail();
            abort_if($proof && (! $attachment->is_resolution_proof || $attachment->uploaded_by_user_id !== $request->user()->id), 403);
            $attachment->delete();
            $ticket->increment('version');
            app(TicketManagementService::class)->record($request->user(), $ticket, 'UPDATED', 'Bỏ ảnh sự cố.');
            DB::afterCommit(fn () => Storage::disk($attachment->storage_disk ?: 'public')->delete($attachment->storage_path));
        });

        return response()->json(['success' => true, 'data' => $this->ticketService->detail($request->user(), $id)]);
    }
}
