<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentInvoicePaymentSecurityTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    private string $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $this->invoiceId = (string) Str::uuid();
        DB::table('invoices')->insert([
            'id' => $this->invoiceId, 'invoice_number' => 'TEST-'.Str::random(12),
            'apartment_id' => $this->apartmentId, 'resident_user_id' => $this->residentUser->id,
            'billing_period' => today()->format('Y-m'), 'issue_date' => today(), 'due_date' => today()->addWeek(),
            'total_amount' => 200000, 'remaining_balance' => 200000, 'status' => 'ISSUED',
        ]);
    }

    public function test_anonymous_expired_and_demo_tokens_cannot_pay_invoice(): void
    {
        $this->flushHeaders()->postJson($this->paymentUrl())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer smart_token_admin_demo')->postJson($this->paymentUrl())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 1]);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson($this->paymentUrl())->assertUnauthorized();
        $this->assertInvoiceUnpaid();
    }

    public function test_other_resident_cannot_pay_or_read_invoice(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson($this->paymentUrl())->assertNotFound();
        $this->assertInvoiceUnpaid();
    }

    public function test_owner_cannot_mark_invoice_paid_without_verified_receipt(): void
    {
        $this->postJson($this->paymentUrl(), ['status' => 'PAID', 'paid_amount' => 200000, 'transaction_id' => 'FAKE'])->assertConflict();
        $this->postJson($this->paymentUrl())->assertConflict();
        $this->assertInvoiceUnpaid();
    }

    public function test_deleted_invoice_is_not_accessible(): void
    {
        DB::table('invoices')->where('id', $this->invoiceId)->update(['deleted_at' => now()]);
        $this->postJson($this->paymentUrl())->assertNotFound();
        $this->assertInvoiceUnpaid();
    }

    private function paymentUrl(): string
    {
        return '/api/v1/resident/invoices/'.$this->invoiceId.'/pay';
    }

    private function assertInvoiceUnpaid(): void
    {
        $this->assertDatabaseHas('invoices', ['id' => $this->invoiceId, 'status' => 'ISSUED', 'paid_amount' => 0, 'remaining_balance' => 200000]);
    }
}
