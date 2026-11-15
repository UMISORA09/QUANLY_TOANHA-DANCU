<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketRatingService
{
    public function __construct(public TicketAccessService $access) {}

    private function canRate(User $user, Ticket $ticket): bool
    {
        return $ticket->creator_user_id === $user->id
            && ! $this->access->manager($user) && ! $this->access->technician($user)
            && app(ResidentTicketService::class)->residentApartments($user)->contains('id', $ticket->apartment_id);
    }

    /** @return array{data: ?object, can_rate: bool, ticket_version: int} */
    public function show(User $user, string $id): array
    {
        $ticket = Ticket::findOrFail($id);
        $this->access->view($user, $ticket);
        $rating = DB::table('ticket_ratings')->where('ticket_id', $id)
            ->first(['id', 'ticket_id', 'rating_stars', 'review_comment', 'created_at']);

        return ['data' => $rating, 'ticket_version' => $ticket->version, 'can_rate' => ! $rating && $ticket->status === 'CLOSED' && $this->canRate($user, $ticket)];
    }

    /**
     * @param  array{version: int, rating_stars: int, review_comment?: ?string}  $data
     * @return array{data: object, can_rate: bool, ticket_version: int, created: bool}
     */
    public function store(User $user, string $id, array $data): array
    {
        return DB::transaction(function () use ($user, $id, $data): array {
            $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->view($user, $ticket);
            abort_unless($this->canRate($user, $ticket), 403, 'Chỉ người báo sự cố còn quyền cư trú được đánh giá.');
            if ($ticket->status !== 'CLOSED') {
                throw new TicketConflictException('Chỉ đánh giá sau khi yêu cầu đã được nghiệm thu hoàn tất.');
            }
            $stars = (int) $data['rating_stars'];
            $comment = trim($data['review_comment'] ?? '');
            $comment = $comment === '' ? null : $comment;
            $existing = DB::table('ticket_ratings')->where('ticket_id', $id)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->rating_stars !== $stars || $existing->review_comment !== $comment) {
                    throw new TicketConflictException('Yêu cầu đã được đánh giá. Không thể thay đổi đánh giá đã gửi.');
                }

                return [...$this->show($user, $id), 'created' => false];
            }
            app(TicketManagementService::class)->version($ticket, (int) $data['version']);
            $rating = ['id' => (string) Str::uuid(), 'ticket_id' => $id, 'resident_user_id' => $user->id,
                'rating_stars' => $stars, 'review_comment' => $comment, 'created_at' => now()];
            DB::table('ticket_ratings')->insert($rating);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'table_name' => 'ticket_ratings',
                'record_id' => $rating['id'], 'action' => 'INSERT', 'performed_by_user_id' => $user->id,
                'new_data' => json_encode($rating, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return [...$this->show($user, $id), 'created' => true];
        });
    }
}
