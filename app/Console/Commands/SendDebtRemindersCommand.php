<?php

namespace App\Console\Commands;

use App\Services\DebtReminderService;
use Illuminate\Console\Command;

class SendDebtRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:send-debt-reminders
                            {--overdue-only : Chỉ gửi nhắc nợ cho các hóa đơn đã quá hạn}
                            {--days-before-due=3 : Số ngày trước khi đến hạn để gửi nhắc nợ}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tự động quét và đưa email nhắc nợ vào Queue để gửi tới cư dân (Chức năng 11 - XuanHoa)';

    /**
     * Execute the console command.
     */
    public function handle(DebtReminderService $reminderService): int
    {
        $overdueOnly = (bool) $this->option('overdue-only');
        $daysBeforeDue = (int) $this->option('days-before-due');

        $this->info('Đang quét danh sách hóa đơn cần nhắc nợ...');

        $dispatchedCount = $reminderService->autoDispatchScheduledReminders($overdueOnly, $daysBeforeDue);

        $this->info("Hoàn tất! Đã đưa {$dispatchedCount} email nhắc nợ vào hàng đợi Queue để xử lý nền.");

        return Command::SUCCESS;
    }
}
