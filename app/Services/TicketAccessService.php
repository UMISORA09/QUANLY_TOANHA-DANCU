<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TicketAccessService
{
    public function manager(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasRole('BUILDING_MANAGER');
    }

    public function technician(User $user): bool
    {
        return ! $this->manager($user) && $user->hasRole(['TECHNICIAN', 'TECHNICAL_STAFF']);
    }

    public function block(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }
        $profile = DB::table('staff_profiles')->where('user_id', $user->id)->first();
        abort_unless($profile, 403, 'Chưa có hồ sơ nhân viên và phạm vi tòa nhà. Vui lòng liên hệ Admin.');

        return $profile->assigned_block_id;
    }

    public function scope(User $user, Builder $query): Builder
    {
        abort_unless(($this->manager($user) || $this->technician($user)) && $user->hasPermission('TICKET:VIEW'), 403);
        $block = $this->block($user);
        if ($block) {
            $query->whereHas('apartment', fn (Builder $apartments) => $apartments->where('block_id', $block));
        }
        if ($this->technician($user)) {
            $query->where('current_technician_user_id', $user->id);
        }

        return $query;
    }

    public function view(User $user, Ticket $ticket): void
    {
        if ($this->manager($user) || $this->technician($user)) {
            abort_unless($this->scope($user, Ticket::query())->whereKey($ticket->id)->exists(), 404);

            return;
        }
        $apartments = app(ResidentTicketService::class)->residentApartments($user);
        abort_unless($ticket->creator_user_id === $user->id || $apartments->contains('id', $ticket->apartment_id), 403);
    }

    public function manage(User $user, string $permission = 'TICKET:UPDATE'): void
    {
        abort_unless($this->manager($user) && $user->hasPermission($permission), 403);
        $this->block($user);
    }

    public function resident(User $user, Ticket $ticket): void
    {
        abort_if($this->manager($user) || $this->technician($user), 403, 'Nhân viên phải dùng thao tác của phân hệ quản lý.');
        abort_unless(app(ResidentTicketService::class)->residentApartments($user)->contains('id', $ticket->apartment_id), 403, 'Không còn quyền cư trú tại căn hộ.');
    }

    /** @return array<string, bool> */
    public function actions(User $user, Ticket $ticket): array
    {
        $manager = $this->manager($user);

        return [
            'edit' => $manager && $user->hasPermission('TICKET:UPDATE') && ! in_array($ticket->status, ['CLOSED', 'CANCELLED'], true),
            'archive' => $user->isSuperAdmin() && in_array($ticket->status, ['CLOSED', 'CANCELLED'], true),
        ];
    }
}
