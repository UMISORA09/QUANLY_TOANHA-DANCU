<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketWorkflowService
{
    public function __construct(public TicketManagementService $tickets) {}

    public function perform(User $user, string $id, string $action, int $version, array $data = [], bool $resident = false): Ticket
    {
        return DB::transaction(function () use ($user, $id, $action, $version, $data, $resident) {
            $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($resident) {
                $this->tickets->access->resident($user, $ticket);
                abort_unless(in_array($action, ['confirm', 'reopen', 'cancel'], true), 403);
            } else {
                $this->tickets->access->view($user, $ticket);
                if (in_array($action, ['receive', 'assign', 'cancel'], true)) {
                    $this->tickets->access->manage($user, $action === 'assign' ? 'TICKET:ASSIGN' : 'TICKET:UPDATE');
                } else {
                    abort_unless(in_array($action, ['start', 'resolve'], true) && $this->tickets->access->technician($user) && $ticket->current_technician_user_id === $user->id && $user->hasPermission($action === 'resolve' ? 'TICKET:RESOLVE' : 'TICKET:UPDATE'), 403);
                }
            }
            $this->tickets->version($ticket, $version);
            $from = $ticket->status;
            $allowed = match ($action) {
                'receive' => ['NEW'], 'assign' => ['NEW', 'RECEIVED', 'IN_PROGRESS'], 'start' => ['RECEIVED'],
                'resolve' => ['IN_PROGRESS'], 'confirm', 'reopen' => ['RESOLVED'], 'cancel' => ['NEW', 'RECEIVED'], default => [],
            };
            if (! in_array($from, $allowed, true)) {
                throw new TicketConflictException('Không thể thực hiện thao tác ở trạng thái hiện tại.');
            }
            if ($action === 'assign') {
                $technician = User::where('status', 'ACTIVE')->findOrFail($data['technician_user_id']);
                abort_unless($this->tickets->access->technician($technician) && $technician->hasPermission('TICKET:VIEW') && $technician->hasPermission('TICKET:UPDATE') && $technician->hasPermission('TICKET:RESOLVE'), 422, 'Tài khoản không phải kỹ thuật viên hợp lệ.');
                $techBlock = $this->tickets->access->block($technician);
                $block = DB::table('apartments')->where('id', $ticket->apartment_id)->value('block_id');
                abort_if($techBlock !== null && $techBlock !== $block, 422, 'Kỹ thuật viên không phụ trách tòa nhà này.');
                $ticket->current_technician_user_id = $technician->id;
                $ticket->coordinator_user_id = $user->id;
            } else {
                $ticket->status = match ($action) {
                    'receive' => 'RECEIVED', 'start', 'reopen' => 'IN_PROGRESS', 'resolve' => 'RESOLVED', 'confirm' => 'CLOSED', 'cancel' => 'CANCELLED'
                };
                if ($action === 'receive') {
                    $ticket->coordinator_user_id = $user->id;
                }
                if ($action === 'resolve') {
                    $ticket->resolved_at = now();
                }
                if ($action === 'reopen') {
                    $ticket->resolved_at = null;
                }
                if ($action === 'confirm') {
                    $ticket->closed_at = now();
                }
            }
            $ticket->version++;
            $ticket->save();
            $this->tickets->record($user, $ticket, $action === 'assign' ? 'ASSIGNED' : 'STATUS_CHANGED', $data['reason'] ?? $data['note'] ?? $action, $from);
            app(TicketNotificationService::class)->changed($ticket, $action);

            return $ticket->load(['category', 'apartment.block', 'attachments', 'history']);
        }, 3);
    }
}
