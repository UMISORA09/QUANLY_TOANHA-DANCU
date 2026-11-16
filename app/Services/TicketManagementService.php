<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketManagementService
{
    public function __construct(public TicketAccessService $access) {}

    public function detail(User $user, string $id): Ticket
    {
        $ticket = $this->access->scope($user, Ticket::query())->with(['category', 'apartment.block', 'creator:id,full_name,email', 'technician:id,full_name', 'attachments', 'history.performer:id,full_name'])->findOrFail($id);
        $ticket->setAttribute('actions', $this->access->actions($user, $ticket));

        return $ticket;
    }

    public function record(User $actor, Ticket $ticket, string $action, string $note, ?string $before = null): void
    {
        TicketHistory::create(['ticket_id' => $ticket->id, 'action_type' => $action, 'old_status' => $before ?? $ticket->status, 'new_status' => $ticket->status, 'performed_by_user_id' => $actor->id, 'notes' => $note]);
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'table_name' => 'tickets', 'record_id' => $ticket->id, 'action' => $action === 'CREATED' ? 'INSERT' : 'UPDATE', 'performed_by_user_id' => $actor->id, 'new_data' => json_encode($ticket->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function version(Ticket $ticket, int $version): void
    {
        if ($ticket->version !== $version) {
            throw new TicketConflictException('Yêu cầu đã thay đổi. Vui lòng tải lại trước khi tiếp tục.');
        }
    }

    public function update(User $user, string $id, array $data): Ticket
    {
        $this->access->manage($user);
        DB::transaction(function () use ($user, $id, $data): void {
            $ticket = $this->access->scope($user, Ticket::query())->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->version($ticket, (int) $data['version']);
            if (in_array($ticket->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new TicketConflictException('Không thể sửa yêu cầu đã kết thúc.');
            }
            $allowed = ['category_id', 'priority'];
            if ($ticket->status === 'NEW') {
                $allowed = [...$allowed, 'title', 'description', 'location', 'preferred_service_time'];
            } elseif (array_intersect(array_keys($data), ['title', 'description', 'location', 'preferred_service_time'])) {
                throw new TicketConflictException('Sau tiếp nhận chỉ được sửa danh mục và ưu tiên.');
            }
            $ticket->fill(array_intersect_key($data, array_flip($allowed)));
            $ticket->version++;
            $ticket->save();
            $this->record($user, $ticket, 'UPDATED', 'Cập nhật nội dung/phân loại yêu cầu.');
        });

        return $this->detail($user, $id);
    }

    public function archive(User $user, string $id, array $data): void
    {
        abort_unless($user->isSuperAdmin(), 403);
        DB::transaction(function () use ($user, $id, $data): void {
            $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->version($ticket, (int) $data['version']);
            if (! in_array($ticket->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new TicketConflictException('Chỉ lưu trữ yêu cầu đã đóng hoặc hủy.');
            }
            $ticket->version++;
            $ticket->save();
            $this->record($user, $ticket, 'UPDATED', 'Lưu trữ: '.$data['reason']);
            $ticket->delete();
        });
    }
}
