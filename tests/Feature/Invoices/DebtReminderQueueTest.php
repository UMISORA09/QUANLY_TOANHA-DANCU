<?php

namespace Tests\Feature\Invoices;

use App\Jobs\SendDebtReminderEmailJob;
use App\Mail\DebtReminderMail;
use App\Models\Apartment;
use App\Models\Block;
use App\Models\DebtReminderLog;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DebtReminderQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $residentUser;

    protected string $token;

    protected Block $block;

    protected Floor $floor;

    protected Apartment $apartment;

    protected Invoice $overdueInvoice;

    protected Invoice $issuedInvoice;

    protected Invoice $paidInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'admin.remind@cassavas.com',
            'full_name' => 'Quản Lý Thu Nợ',
            'status' => 'ACTIVE',
        ]);

        $this->residentUser = User::factory()->create([
            'email' => 'resident.debtor@cassavas.com',
            'full_name' => 'Lê Văn Nợ',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        $this->block = Block::create([
            'block_code' => 'T-B',
            'block_name' => 'Tòa Tháp B Ruby',
            'total_floors' => 20,
            'total_apartments' => 80,
        ]);

        $this->floor = Floor::create([
            'block_id' => $this->block->id,
            'floor_code' => 'FL-B08',
            'floor_number' => 8,
            'floor_name' => 'Tầng 8',
        ]);

        $this->apartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'B-805',
            'net_usable_area_sqm' => 75.0,
            'gross_floor_area_sqm' => 80.0,
            'current_resident_user_id' => $this->residentUser->id,
            'status' => 'OCCUPIED',
        ]);

        // 1. Hóa đơn quá hạn
        $this->overdueInvoice = Invoice::create([
            'invoice_number' => 'HD-202609-B805-OVERDUE',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->residentUser->id,
            'billing_period' => '2026-09',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15', // Đã quá hạn
            'subtotal_amount' => 2000000,
            'tax_amount' => 200000,
            'total_amount' => 2200000,
            'paid_amount' => 0,
            'remaining_balance' => 2200000,
            'status' => 'OVERDUE',
        ]);

        // 2. Hóa đơn sắp đến hạn
        $this->issuedInvoice = Invoice::create([
            'invoice_number' => 'HD-202610-B805-ISSUED',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->residentUser->id,
            'billing_period' => '2026-10',
            'issue_date' => Carbon::today()->format('Y-m-d'),
            'due_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'subtotal_amount' => 1500000,
            'tax_amount' => 150000,
            'total_amount' => 1650000,
            'paid_amount' => 0,
            'remaining_balance' => 1650000,
            'status' => 'ISSUED',
        ]);

        // 3. Hóa đơn đã thanh toán xong
        $this->paidInvoice = Invoice::create([
            'invoice_number' => 'HD-202608-B805-PAID',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->residentUser->id,
            'billing_period' => '2026-08',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'subtotal_amount' => 1000000,
            'tax_amount' => 100000,
            'total_amount' => 1100000,
            'paid_amount' => 1100000,
            'remaining_balance' => 0,
            'status' => 'PAID',
        ]);
    }

    public function test_send_single_reminder_dispatches_queue_job_and_creates_log(): void
    {
        Queue::fake();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$this->overdueInvoice->id}/send-reminder");

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'invoice_id' => $this->overdueInvoice->id,
                    'channel_status' => 'QUEUED',
                    'recipient_email' => 'resident.debtor@cassavas.com',
                ],
            ]);

        // Kiểm tra log được lưu vào database
        $this->assertDatabaseHas('debt_reminder_logs', [
            'invoice_id' => $this->overdueInvoice->id,
            'channel_status' => 'QUEUED',
            'recipient_email' => 'resident.debtor@cassavas.com',
        ]);

        // Kiểm tra Job đã được đẩy vào Queue
        Queue::assertPushed(SendDebtReminderEmailJob::class, 1);
    }

    public function test_send_bulk_reminders_dispatches_multiple_queue_jobs(): void
    {
        Queue::fake();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/invoices/bulk-send-reminders', [
            'invoice_ids' => [
                $this->overdueInvoice->id,
                $this->issuedInvoice->id,
            ],
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'queued_count' => 2,
                    'skipped_count' => 0,
                ],
            ]);

        Queue::assertPushed(SendDebtReminderEmailJob::class, 2);
    }

    public function test_job_execution_sends_email_and_marks_log_as_sent(): void
    {
        Mail::fake();

        $log = DebtReminderLog::create([
            'invoice_id' => $this->overdueInvoice->id,
            'apartment_id' => $this->apartment->id,
            'recipient_email' => 'resident.debtor@cassavas.com',
            'recipient_name' => 'Lê Văn Nợ',
            'reminder_type' => 'EMAIL',
            'debt_amount' => 2200000,
            'due_date' => $this->overdueInvoice->due_date,
            'days_overdue' => 20,
            'channel_status' => 'QUEUED',
        ]);

        // Thực thi job trực tiếp
        $job = new SendDebtReminderEmailJob($log->id);
        $job->handle(app(PaymentCollectionService::class));

        // Kiểm tra email được gửi
        Mail::assertSent(DebtReminderMail::class, function ($mail) {
            return $mail->hasTo('resident.debtor@cassavas.com') &&
                   $mail->daysOverdue === 20;
        });

        // Kiểm tra status log chuyển sang SENT
        $this->assertDatabaseHas('debt_reminder_logs', [
            'id' => $log->id,
            'channel_status' => 'SENT',
        ]);

        $freshLog = $log->fresh();
        $this->assertNotNull($freshLog->sent_at);
        $this->assertNull($freshLog->error_message);
    }

    public function test_cannot_send_reminder_for_paid_or_cancelled_invoice(): void
    {
        Queue::fake();

        // 1. Thử trên hóa đơn PAID
        $resPaid = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$this->paidInvoice->id}/send-reminder");

        $resPaid->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        // 2. Thử trên hóa đơn CANCELLED
        $this->overdueInvoice->update(['status' => 'CANCELLED']);

        $resCancelled = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$this->overdueInvoice->id}/send-reminder");

        $resCancelled->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_artisan_command_auto_dispatches_reminders_for_overdue_invoices(): void
    {
        Queue::fake();

        $this->artisan('invoices:send-debt-reminders', ['--overdue-only' => true])
            ->expectsOutputToContain('Hoàn tất!')
            ->assertSuccessful();

        Queue::assertPushed(SendDebtReminderEmailJob::class, 1);
    }

    public function test_get_reminder_logs_history(): void
    {
        DebtReminderLog::create([
            'invoice_id' => $this->overdueInvoice->id,
            'apartment_id' => $this->apartment->id,
            'recipient_email' => 'resident.debtor@cassavas.com',
            'recipient_name' => 'Lê Văn Nợ',
            'reminder_type' => 'EMAIL',
            'debt_amount' => 2200000,
            'due_date' => $this->overdueInvoice->due_date,
            'days_overdue' => 15,
            'channel_status' => 'SENT',
            'sent_at' => Carbon::now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/invoices/reminders/history');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('SENT', $data[0]['channel_status']);
        $this->assertEquals('resident.debtor@cassavas.com', $data[0]['recipient_email']);
    }
}
