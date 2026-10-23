<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TicketNotificationService
{
    public function commented(Ticket $ticket, User $sender, bool $internal): void
    {
        $access = app(TicketAccessService::class);
        $ids = [$ticket->coordinator_user_id, $ticket->current_technician_user_id];
        if (! $ticket->coordinator_user_id) {
            $ids = [...$ids, ...User::where('status', 'ACTIVE')->whereHas('roles', fn ($query) => $query->whereIn('role_code', ['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER']))->pluck('id')->all()];
        }
        if (! $internal && ($access->manager($sender) || $access->technician($sender))) {
            $ids[] = $ticket->creator_user_id;
        }
        foreach (User::with('roles.permissions')->where('status', 'ACTIVE')->whereIn('id', array_unique(array_filter($ids)))->where('id', '!=', $sender->id)->get() as $recipient) {
            $staff = $access->manager($recipient) || $access->technician($recipient);
            if ($internal && ! $staff) {
                continue;
            }
            try {
                $access->view($recipient, $ticket);
                if (! $staff) {
                    $access->resident($recipient, $ticket);
                }
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }

                continue;
            }
            $path = $staff ? ($recipient->isSuperAdmin() ? '/admin' : '/quan-ly') : '/cu-dan';
            DB::table('user_in_app_notifications')->insert([
                'id' => (string) Str::uuid(), 'recipient_user_id' => $recipient->id,
                'title' => ($internal ? 'Ghi chú nội bộ mới' : 'Trao đổi mới').' · '.$ticket->ticket_number,
                'body_message' => 'Có nội dung mới trong yêu cầu. Mở yêu cầu để xem chi tiết.',
                'deep_link_url' => $path.'?'.http_build_query(['tab' => 'tickets', 'ticket_id' => $ticket->id]),
                'category' => 'TICKET', 'is_read' => 0, 'created_at' => now(),
            ]);
        }
    }

    public function changed(Ticket $ticket, string $action): void
    {
        $block = DB::table('apartments')->where('id', $ticket->apartment_id)->value('block_id');
        $ids = [];
        if ($action === 'created' || ($action === 'cancel' && ! $ticket->coordinator_user_id && ! $ticket->current_technician_user_id)) {
            foreach (User::where('status', 'ACTIVE')->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER']))->get() as $user) {
                $profile = DB::table('staff_profiles')->where('user_id', $user->id)->first();
                if ($user->isSuperAdmin() || ($profile && ($profile->assigned_block_id === null || $profile->assigned_block_id === $block) && $user->hasPermission('TICKET:VIEW') && $user->hasPermission('TICKET:UPDATE'))) {
                    $ids[] = $user->id;
                }
            }
        } elseif ($action === 'assign') {
            $ids[] = $ticket->current_technician_user_id;
        } elseif (in_array($action, ['reopen', 'cancel', 'confirm'], true)) {
            $ids = [$ticket->current_technician_user_id, $ticket->coordinator_user_id];
        } else {
            $ids[] = $ticket->creator_user_id;
        }
        if ($action === 'cancel') {
            $ids[] = $ticket->creator_user_id;
        }
        $labels = ['created' => 'Yêu cầu sự cố mới', 'receive' => 'Đã tiếp nhận yêu cầu', 'assign' => 'Bạn được giao xử lý sự cố', 'start' => 'Kỹ thuật viên đang xử lý', 'resolve' => 'Sự cố đã khắc phục, chờ nghiệm thu', 'reopen' => 'Cư dân phản hồi chưa đạt', 'cancel' => 'Yêu cầu đã hủy', 'confirm' => 'Cư dân đã nghiệm thu'];
        foreach (array_unique(array_filter($ids)) as $id) {
            $user = User::where('status', 'ACTIVE')->find($id);
            if (! $user) {
                continue;
            }
            $resident = $id === $ticket->creator_user_id;
            $path = $resident ? '/cu-dan' : ($user->isSuperAdmin() ? '/admin' : '/quan-ly');
            DB::table('user_in_app_notifications')->insert(['id' => (string) Str::uuid(), 'recipient_user_id' => $id, 'title' => ($labels[$action] ?? 'Cập nhật yêu cầu').' · '.$ticket->ticket_number,
                'body_message' => $ticket->title, 'deep_link_url' => $path.'?'.http_build_query(['tab' => 'tickets', 'ticket_id' => $ticket->id]), 'category' => 'TICKET', 'is_read' => 0, 'created_at' => now()]);
        }
    }
}
