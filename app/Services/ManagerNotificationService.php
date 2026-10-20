<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ManagerNotificationService
{
    public function tickets(User $user): Builder
    {
        $access = app(TicketAccessService::class);
        if ($access->manager($user) || $access->technician($user)) {
            return $this->inbox($user)->where('category', 'TICKET');
        }

        return DB::table('user_in_app_notifications')->where('recipient_user_id', $user->id)->where('category', 'TICKET')->where('deep_link_url', 'like', '/cu-dan?%');
    }

    public function inbox(User $user): Builder
    {
        $access = app(TicketAccessService::class);
        abort_unless($access->manager($user) || $access->technician($user), 403);
        $block = $access->block($user);

        // shortcut: object IDs use existing deep links; add object columns before supporting new link formats.
        return DB::table('user_in_app_notifications as n')->where('n.recipient_user_id', $user->id)
            ->where(function (Builder $sources) use ($user, $access, $block): void {
                $sources->whereRaw('1 = 0');
                if ($user->hasPermission('TICKET:VIEW')) {
                    $sources->orWhere(function (Builder $tickets) use ($user, $access, $block): void {
                        $tickets->where('n.category', 'TICKET')->whereExists(function (Builder $query) use ($user, $access, $block): void {
                            $query->selectRaw('1')->from('tickets as t')->join('apartments as a', 'a.id', '=', 't.apartment_id')
                                ->whereRaw("t.id = SUBSTRING_INDEX(SUBSTRING_INDEX(n.deep_link_url, 'ticket_id=', -1), '&', 1)")
                                ->whereNull('t.deleted_at')->whereNull('a.deleted_at')
                                ->when($block, fn (Builder $q) => $q->where('a.block_id', $block))
                                ->when($access->technician($user), fn (Builder $q) => $q->where('t.current_technician_user_id', $user->id));
                        });
                    });
                }
                if ($access->manager($user) && $user->hasPermission('AMENITY:UPDATE')) {
                    $sources->orWhere(function (Builder $amenities) use ($block): void {
                        $amenities->where('n.category', 'AMENITY_BOOKING');
                        if ($block) {
                            $amenities->whereExists(function (Builder $q) use ($block): void {
                                $q->selectRaw('1')->from('amenity_bookings as b')->join('apartments as a', 'a.id', '=', 'b.apartment_id')
                                    ->whereRaw("b.amenity_id = SUBSTRING_INDEX(SUBSTRING_INDEX(n.deep_link_url, 'amenity_id=', -1), '&', 1)")
                                    ->whereRaw("b.booking_code = SUBSTRING_INDEX(SUBSTRING_INDEX(n.deep_link_url, 'booking_code=', -1), '&', 1)")
                                    ->where('a.block_id', $block);
                            });
                        }
                    });
                }
            });
    }
}
