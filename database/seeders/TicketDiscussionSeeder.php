<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketDiscussionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TicketDiscussionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Chỉ nạp trao đổi mẫu trong môi trường local/testing.');

            return;
        }
        $discussion = app(TicketDiscussionService::class);
        $staff = User::where('status', 'ACTIVE')->whereHas('roles', fn ($query) => $query->whereIn('role_code', ['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER', 'TECHNICIAN', 'TECHNICAL_STAFF']))->orderBy('id')->get();
        $tickets = Ticket::whereIn('ticket_number', ['TCK-202610-00101', 'TCK-202610-00102', 'TCK-202610-00103', 'TCK-202610-00106'])->orderBy('ticket_number')->get();
        if ($tickets->isEmpty()) {
            $this->command?->warn('Chưa có Ticket mẫu của ResidentTicketSeeder; chưa thêm trao đổi.');

            return;
        }
        foreach ($tickets as $index => $ticket) {
            if (! in_array($ticket->status, ['NEW', 'RECEIVED', 'IN_PROGRESS', 'RESOLVED'], true)) {
                continue;
            }
            $resident = User::find($ticket->creator_user_id);
            if ($resident && ! $discussion->access->manager($resident) && ! $discussion->access->technician($resident) && $this->canComment($discussion, $resident, $ticket)) {
                for ($message = 1; $message <= ($index === 0 ? 18 : 1); $message++) {
                    $this->comment($discussion, $resident, $ticket, '[Mẫu] Cư dân bổ sung thông tin lần '.$message.': sự cố vẫn cần được kiểm tra. Xin báo giúp thời gian xử lý.', false);
                }
            }
            foreach ($staff as $employee) {
                if ($this->canComment($discussion, $employee, $ticket)) {
                    $this->comment($discussion, $employee, $ticket, '[Mẫu] Đã nhận thông tin bổ sung. Bộ phận phụ trách sẽ cập nhật tiến độ tại đây.', false);
                    $this->comment($discussion, $employee, $ticket, '[Mẫu nội bộ] Kiểm tra vật tư và trao đổi với kỹ thuật viên trước khi hẹn cư dân.', true);
                    break;
                }
            }
        }
        $this->command?->info('Đã bổ sung trao đổi mẫu vào Ticket hiện có; không đổi trạng thái hoặc phiên bản.');
    }

    private function canComment(TicketDiscussionService $discussion, User $user, Ticket $ticket): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }
        try {
            return $discussion->comments($user, $ticket->id, null)['meta']['can_comment'];
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }

            return false;
        }
    }

    private function comment(TicketDiscussionService $discussion, User $user, Ticket $ticket, string $text, bool $internal): void
    {
        DB::transaction(function () use ($discussion, $user, $ticket, $text, $internal): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (DB::table('ticket_comments')->where('ticket_id', $ticket->id)->where('sender_user_id', $user->id)->where('comment_text', $text)->where('is_internal_staff_note', $internal)->exists()) {
                return;
            }
            $discussion->store($user, $ticket->id, ['id' => (string) Str::uuid(), 'comment_text' => $text, 'is_internal_staff_note' => $internal]);
        }, 3);
    }
}
