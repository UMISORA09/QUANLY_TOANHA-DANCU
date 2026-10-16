<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketHistory;
use App\Models\User;
use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResidentTicketService
{
    public const VALID_STATUSES = ['NEW', 'RECEIVED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'CANCELLED'];

    public const VALID_PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public function __construct(
        public DatabaseResidentAmenityBookingRepository $repository
    ) {}

    /**
     * Lấy danh mục sự cố / hỗ trợ kỹ thuật (Áp dụng Cache-Aside pattern).
     */
    public function categories(): Collection
    {
        return Cache::remember('portal:ticket_categories:all', 600, function () {
            return TicketCategory::orderBy('category_name')->get();
        });
    }

    /**
     * Lấy danh sách căn hộ hợp lệ mà cư dân đang sinh sống.
     */
    public function residentApartments(User $user): Collection
    {
        return $this->repository->apartments($user->id);
    }

    /**
     * Danh sách Ticket của cư dân với bộ lọc, tìm kiếm và phân trang phía Server.
     *
     * @param  array<string, mixed>  $filters
     * @return array{tickets: LengthAwarePaginator, counters: array<string, int>}
     */
    public function list(User $user, array $filters = []): array
    {
        $apartments = $this->residentApartments($user);
        $apartmentIds = $apartments->pluck('id')->all();

        $baseQuery = Ticket::query()
            ->where(function ($q) use ($user, $apartmentIds) {
                $q->where('creator_user_id', $user->id);
                if (! empty($apartmentIds)) {
                    $q->orWhereIn('apartment_id', $apartmentIds);
                }
            });

        // Tính toán các counters thống kê
        $counters = [
            'all' => (clone $baseQuery)->count(),
            'new' => (clone $baseQuery)->where('status', 'NEW')->count(),
            'in_progress' => (clone $baseQuery)->whereIn('status', ['RECEIVED', 'IN_PROGRESS'])->count(),
            'resolved' => (clone $baseQuery)->where('status', 'RESOLVED')->count(),
            'closed' => (clone $baseQuery)->where('status', 'CLOSED')->count(),
            'cancelled' => (clone $baseQuery)->where('status', 'CANCELLED')->count(),
        ];

        $query = (clone $baseQuery)
            ->with([
                'category:id,category_code,category_name,default_priority',
                'apartment:id,apartment_number,block_id',
                'apartment.block:id,block_name,block_code',
                'attachments',
            ]);

        // 1. Lọc theo trạng thái
        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            $status = strtoupper((string) $filters['status']);
            if ($status === 'IN_PROGRESS') {
                $query->whereIn('status', ['RECEIVED', 'IN_PROGRESS']);
            } elseif (in_array($status, self::VALID_STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        // 2. Lọc theo danh mục sự cố
        if (! empty($filters['category_id']) && $filters['category_id'] !== 'ALL') {
            $query->where('category_id', $filters['category_id']);
        }

        // 3. Lọc theo mức độ ưu tiên
        if (! empty($filters['priority']) && $filters['priority'] !== 'ALL') {
            $query->where('priority', strtoupper((string) $filters['priority']));
        }

        // 4. Tìm kiếm từ khóa (Mã ticket, tiêu đề, vị trí)
        if (! empty($filters['search'])) {
            $keyword = trim((string) $filters['search']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ticket_number', 'like', "%{$keyword}%")
                    ->orWhere('title', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 5), 50);
        $paginated = $query->orderBy('created_at', 'desc')->orderBy('id')->paginate($perPage);

        return [
            'tickets' => $paginated,
            'counters' => $counters,
        ];
    }

    /**
     * Xem thông tin chi tiết của Ticket kèm kiểm tra quyền sở hữu IDOR.
     */
    public function detail(User $user, string $ticketId): Ticket
    {
        $ticket = Ticket::with([
            'category',
            'apartment.block',
            'creator:id,full_name,email,phone_number',
            'technician:id,full_name,email,phone_number',
            'attachments',
            'history.performer:id,full_name,email',
        ])->find($ticketId);

        if (! $ticket) {
            abort(404, 'Không tìm thấy yêu cầu báo cáo sự cố kỹ thuật này.');
        }

        $this->assertOwnership($user, $ticket);

        return $ticket;
    }

    /**
     * Tạo Ticket mới với cơ chế chống submit trùng & giao dịch cơ sở dữ liệu an toàn.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>|null  $files
     */
    public function create(User $user, array $data, ?array $files = null, ?string $idempotencyKey = null): Ticket
    {
        $apartments = $this->residentApartments($user);
        if ($apartments->isEmpty()) {
            abort(403, 'Bạn chưa có hồ sơ cư trú hợp lệ để gửi yêu cầu hỗ trợ.');
        }

        // Xác định căn hộ
        $apartmentId = $data['apartment_id'] ?? ($apartments->count() === 1 ? $apartments->first()->id : null);
        if (! $apartmentId || ! $apartments->contains('id', $apartmentId)) {
            throw ValidationException::withMessages([
                'apartment_id' => 'Vui lòng chọn căn hộ bạn đang sinh sống.',
            ]);
        }

        $idempKey = $idempotencyKey ?: ($data['idempotency_key'] ?? null);

        // Kiểm tra category hợp lệ
        $category = TicketCategory::find($data['category_id']);
        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'Danh mục sự cố không hợp lệ hoặc đã ngừng áp dụng.',
            ]);
        }

        // Xác định mức độ khẩn cấp (priority)
        $priority = ! empty($data['priority']) && in_array(strtoupper((string) $data['priority']), self::VALID_PRIORITIES, true)
            ? strtoupper((string) $data['priority'])
            : ($category->default_priority ?: 'MEDIUM');

        $now = Carbon::now();
        $slaDeadline = $now->copy()->addHours($category->sla_resolution_time_hours ?: 24);

        $preferredServiceTime = ! empty($data['preferred_service_time'])
            ? Carbon::parse($data['preferred_service_time'])
            : null;

        $createdFiles = [];

        try {
            $ticket = DB::transaction(function () use (
                $user, $data, $apartmentId, $category, $priority, $preferredServiceTime, $slaDeadline, $now, $files, $idempKey, &$createdFiles
            ) {
                // Serialize creation per resident so retries across workers share one durable result.
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if ($idempKey) {
                    $existing = Ticket::withTrashed()->where('creator_user_id', $user->id)
                        ->where('idempotency_key', $idempKey)->lockForUpdate()->first();
                    if ($existing) {
                        if ($existing->trashed()) {
                            throw new TicketConflictException('Yêu cầu này đã bị xóa. Vui lòng tạo yêu cầu mới.');
                        }

                        return $existing;
                    }
                }

                // Tạo mã Ticket duy nhất
                $ticketNumber = $this->generateUniqueTicketNumber();

                $ticket = Ticket::create([
                    'ticket_number' => $ticketNumber,
                    'idempotency_key' => $idempKey,
                    'category_id' => $category->id,
                    'apartment_id' => $apartmentId,
                    'creator_user_id' => $user->id,
                    'title' => trim((string) $data['title']),
                    'description' => trim((string) $data['description']),
                    'location' => trim((string) ($data['location'] ?? 'Tại căn hộ')),
                    'priority' => $priority,
                    'status' => 'NEW',
                    'preferred_service_time' => $preferredServiceTime,
                    'sla_deadline' => $slaDeadline,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (! empty($files)) {
                    app(TicketAttachmentService::class)->store($ticket, $user->id, $files, $createdFiles);
                }

                // Ghi nhận lịch sử trạng thái ban đầu
                TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'action_type' => 'CREATED',
                    'old_status' => null,
                    'new_status' => 'NEW',
                    'performed_by_user_id' => $user->id,
                    'notes' => 'Cư dân tạo mới yêu cầu hỗ trợ kỹ thuật',
                    'created_at' => $now,
                ]);

                app(TicketNotificationService::class)->changed($ticket, 'created');

                return $ticket;
            });

        } catch (\Throwable $e) {
            app(TicketAttachmentService::class)->cleanup($createdFiles);
            throw $e;
        }

        return $ticket->load(['category', 'apartment.block', 'attachments', 'history']);
    }

    /**
     * Cập nhật thông tin Ticket (Chỉ cho phép khi ở trạng thái NEW và có kiểm tra OCC).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $ticketId, array $data): Ticket
    {
        return DB::transaction(function () use ($user, $ticketId, $data) {
            $ticket = Ticket::whereKey($ticketId)->lockForUpdate()->first();
            if (! $ticket) {
                abort(404, 'Không tìm thấy yêu cầu báo cáo sự cố kỹ thuật này.');
            }

            app(TicketAccessService::class)->resident($user, $ticket);

            if ($ticket->status !== 'NEW') {
                throw new TicketConflictException('Không thể chỉnh sửa yêu cầu khi đã được ban quản lý tiếp nhận hoặc xử lý.', 409);
            }

            // Optimistic Concurrency Control (OCC)
            if (isset($data['version']) && (int) $data['version'] !== (int) $ticket->version) {
                throw new TicketConflictException('Dữ liệu yêu cầu đã được cập nhật bởi một phiên làm việc khác. Vui lòng tải lại dữ liệu mới nhất.', 409);
            }

            if (! empty($data['updated_at'])) {
                try {
                    $clientTimestamp = Carbon::parse($data['updated_at'])->timestamp;
                    $dbTimestamp = $ticket->updated_at ? $ticket->updated_at->timestamp : 0;
                    if ($clientTimestamp !== $dbTimestamp) {
                        throw new TicketConflictException('Dữ liệu yêu cầu đã được cập nhật bởi một phiên làm việc khác. Vui lòng tải lại dữ liệu mới nhất.', 409);
                    }
                } catch (\Throwable $e) {
                    if ($e instanceof TicketConflictException) {
                        throw $e;
                    }
                }
            }

            $now = Carbon::now();

            $ticket->title = trim((string) $data['title']);
            $ticket->description = trim((string) $data['description']);
            $ticket->location = trim((string) ($data['location'] ?? $ticket->location));

            if (! empty($data['priority']) && in_array(strtoupper((string) $data['priority']), self::VALID_PRIORITIES, true)) {
                $ticket->priority = strtoupper((string) $data['priority']);
            }

            if (array_key_exists('preferred_service_time', $data)) {
                $ticket->preferred_service_time = ! empty($data['preferred_service_time'])
                    ? Carbon::parse($data['preferred_service_time'])
                    : null;
            }

            $ticket->version = ((int) $ticket->version) + 1;
            $ticket->updated_at = $now;
            $ticket->save();

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'action_type' => 'UPDATED',
                'old_status' => $ticket->status,
                'new_status' => $ticket->status,
                'performed_by_user_id' => $user->id,
                'notes' => 'Cư dân cập nhật nội dung sự cố',
                'created_at' => $now,
            ]);

            return $ticket->load(['category', 'apartment.block', 'attachments', 'history']);
        });
    }

    /**
     * Xem lịch sử trạng thái của Ticket.
     */
    public function history(User $user, string $ticketId): Collection
    {
        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            abort(404, 'Không tìm thấy yêu cầu này.');
        }

        $this->assertOwnership($user, $ticket);

        return TicketHistory::with('performer:id,full_name,email')
            ->where('ticket_id', $ticketId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Kiểm tra quyền sở hữu Ticket để ngăn ngừa lỗi bảo mật IDOR.
     */
    private function assertOwnership(User $user, Ticket $ticket): void
    {
        app(TicketAccessService::class)->view($user, $ticket);
    }

    /**
     * Sinh mã Ticket duy nhất dạng TCK-YYYYMM-XXXXX (Chống va chạm mã số).
     */
    private function generateUniqueTicketNumber(): string
    {
        $prefix = 'TCK-'.date('Ym').'-';
        for ($i = 0; $i < 10; $i++) {
            $code = $prefix.strtoupper(Str::random(5));
            if (! Ticket::where('ticket_number', $code)->exists()) {
                return $code;
            }
        }

        return $prefix.sprintf('%05d', mt_rand(10000, 99999));
    }
}
