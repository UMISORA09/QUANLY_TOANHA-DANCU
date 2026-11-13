<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketDiscussionService
{
    public function __construct(public TicketAccessService $access) {}

    private function staff(User $user): bool
    {
        return $this->access->manager($user) || $this->access->technician($user);
    }

    private function canSend(User $user, Ticket $ticket): bool
    {
        return in_array($ticket->status, ['NEW', 'RECEIVED', 'IN_PROGRESS', 'RESOLVED'], true)
            && ($this->staff($user) ? $user->hasPermission('TICKET:UPDATE') : app(ResidentTicketService::class)->residentApartments($user)->contains('id', $ticket->apartment_id));
    }

    /** @return array<string, mixed> */
    public function comments(User $user, string $id, ?string $cursor, ?string $since = null): array
    {
        $ticket = Ticket::findOrFail($id);
        $this->access->view($user, $ticket);
        $staff = $this->staff($user);
        $query = DB::table('ticket_comments')->where('ticket_id', $id)
            ->when(! $staff, fn (Builder $query): Builder => $query->where('is_internal_staff_note', false));
        if ($since) {
            $query->where('created_at', '>=', Carbon::parse($since)->setTimezone(config('app.timezone'))->startOfSecond());
        }
        $canSend = $this->canSend($user, $ticket);

        return [...$this->page($query, $cursor, false), 'meta' => [
            'status' => $ticket->status,
            'ticket_version' => $ticket->version,
            'history_count' => DB::table('ticket_history')->where('ticket_id', $id)->count(),
            'can_comment' => $canSend,
            'can_internal_note' => $staff && $canSend,
            'can_view_internal' => $staff,
        ]];
    }

    /** @return array<string, mixed> */
    public function history(User $user, string $id, ?string $cursor): array
    {
        $this->access->view($user, Ticket::findOrFail($id));

        return $this->page(DB::table('ticket_history')->where('ticket_id', $id), $cursor, true);
    }

    /** @return array{items: array<int, array<string, mixed>>, next_cursor: ?string} */
    private function page(Builder $query, ?string $cursor, bool $history): array
    {
        $decoded = $cursor ? Cursor::fromEncoded($cursor) : null;
        if ($cursor && (! $decoded || Validator::make($decoded->toArray(), ['id' => 'required|uuid', 'created_at' => 'required|string|date|max:40', '_pointsToNextItems' => 'required|boolean'])->fails())) {
            throw ValidationException::withMessages(['cursor' => 'Vị trí tải nội dung không hợp lệ. Vui lòng tải lại.']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(15, ['*'], 'cursor', $decoded);

        return ['items' => $this->serialize($page->items(), $history), 'next_cursor' => $page->nextCursor()?->encode()];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $rows, bool $history = false): array
    {
        $key = $history ? 'performed_by_user_id' : 'sender_user_id';
        $users = User::with('roles')->whereIn('id', array_column($rows, $key))->get(['id', 'full_name'])->keyBy('id');

        return array_map(function (object $row) use ($history, $key, $users): array {
            $user = $users->get($row->{$key});
            $person = $user ? ['id' => $user->id, 'full_name' => $user->full_name, 'role' => $user->isSuperAdmin() ? 'Quản trị viên' : ($this->access->manager($user) ? 'Ban quản lý' : ($this->access->technician($user) ? 'Kỹ thuật viên' : 'Cư dân'))] : null;
            $common = ['id' => $row->id, 'ticket_id' => $row->ticket_id, 'created_at' => Carbon::parse($row->created_at)->toIso8601String()];

            return $history
                ? [...$common, 'action_type' => $row->action_type, 'old_status' => $row->old_status, 'new_status' => $row->new_status, 'notes' => $row->notes, 'performer' => $person]
                : [...$common, 'comment_text' => $row->comment_text, 'is_internal_staff_note' => (bool) $row->is_internal_staff_note, 'sender' => $person];
        }, $rows);
    }

    /**
     * @param  array{id: string, comment_text: string, is_internal_staff_note?: bool|int|string}  $data
     * @return array{data: array<string, mixed>, created: bool}
     */
    public function store(User $user, string $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($user, $id, $data): array {
                $user = User::whereKey($user->id)->lockForUpdate()->first();
                abort_unless($user && $user->status === 'ACTIVE', 403, 'Tài khoản không còn hoạt động.');
                $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->access->view($user, $ticket);
                $internal = (bool) ($data['is_internal_staff_note'] ?? false);
                abort_if($internal && ! $this->staff($user), 403, 'Cư dân không được tạo ghi chú nội bộ.');
                if ($this->staff($user)) {
                    abort_unless($user->hasPermission('TICKET:UPDATE'), 403);
                } else {
                    $this->access->resident($user, $ticket);
                }
                $existing = DB::table('ticket_comments')->where('id', $data['id'])->first();
                if ($existing) {
                    abort_unless($existing->ticket_id === $id && $existing->sender_user_id === $user->id && $existing->comment_text === $data['comment_text'] && (bool) $existing->is_internal_staff_note === $internal, 409, 'Mã gửi đã được sử dụng cho một nội dung khác.');

                    return ['data' => $this->serialize([$existing])[0], 'created' => false];
                }
                abort_unless($this->canSend($user, $ticket), 409, 'Yêu cầu đã kết thúc hoặc quyền gửi đã thay đổi. Nội dung chưa được gửi.');
                // The database limit survives Vercel instances, whose array cache cannot enforce a shared limiter.
                abort_if(DB::table('ticket_comments')->where('sender_user_id', $user->id)->where('created_at', '>', now()->subMinute())->count() >= 30, 429, 'Bạn đã gửi quá nhanh. Vui lòng đợi một phút rồi thử lại.');
                DB::table('ticket_comments')->insert(['id' => $data['id'], 'ticket_id' => $id, 'sender_user_id' => $user->id, 'comment_text' => $data['comment_text'], 'is_internal_staff_note' => $internal, 'created_at' => now()]);
                DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'table_name' => 'ticket_comments', 'record_id' => $data['id'], 'action' => 'INSERT', 'performed_by_user_id' => $user->id, 'new_data' => json_encode(['ticket_id' => $id, 'is_internal_staff_note' => $internal], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                app(TicketNotificationService::class)->commented($ticket, $user, $internal);

                return ['data' => $this->serialize([DB::table('ticket_comments')->where('id', $data['id'])->first()])[0], 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Mã gửi đã được sử dụng. Vui lòng tải lại trước khi gửi nội dung khác.');
        }
    }
}
