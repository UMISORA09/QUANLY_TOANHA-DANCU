<?php

namespace Tests\Feature\Payments;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token;

    protected Block $block;

    protected Floor $floor;

    protected Apartment $apt1;

    protected Apartment $apt2;

    protected Invoice $invPeriod1;

    protected Invoice $invPeriod2;

    protected Payment $pay1;

    protected Payment $pay2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'finance.admin@cassavas.com',
            'full_name' => 'Kế Toán Trưởng Tòa Nhà',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        $this->block = Block::create([
            'block_code' => 'T-A',
            'block_name' => 'Tòa Tháp A Sapphire',
            'total_floors' => 20,
            'total_apartments' => 80,
        ]);

        $this->floor = Floor::create([
            'block_id' => $this->block->id,
            'floor_code' => 'FL-A03',
            'floor_number' => 3,
            'floor_name' => 'Tầng 3',
        ]);

        $this->apt1 = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'A-301',
            'net_usable_area_sqm' => 70.0,
            'gross_floor_area_sqm' => 75.0,
            'current_resident_user_id' => $this->adminUser->id,
            'status' => 'OCCUPIED',
        ]);

        $this->apt2 = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'A-302',
            'net_usable_area_sqm' => 85.0,
            'gross_floor_area_sqm' => 90.0,
            'current_resident_user_id' => $this->adminUser->id,
            'status' => 'OCCUPIED',
        ]);

        // Hóa đơn kỳ 2026-08 (kỳ trước)
        $this->invPeriod1 = Invoice::create([
            'invoice_number' => 'HD-202608-A301',
            'apartment_id' => $this->apt1->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-08',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-20',
            'subtotal_amount' => 1200000,
            'tax_amount' => 120000,
            'total_amount' => 1320000,
            'paid_amount' => 1320000,
            'remaining_balance' => 0,
            'status' => 'PAID',
        ]);

        // Hóa đơn kỳ 2026-09 (kỳ trước nữa)
        $this->invPeriod2 = Invoice::create([
            'invoice_number' => 'HD-202609-A302',
            'apartment_id' => $this->apt2->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-09',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'subtotal_amount' => 2000000,
            'tax_amount' => 200000,
            'total_amount' => 2200000,
            'paid_amount' => 1000000,
            'remaining_balance' => 1200000,
            'status' => 'PARTIAL',
        ]);

        // Thanh toán 1: Tiền mặt kỳ 2026-08
        $this->pay1 = Payment::create([
            'payment_reference_code' => 'PAY-202608-A301-SUCCESS',
            'invoice_id' => $this->invPeriod1->id,
            'apartment_id' => $this->apt1->id,
            'payer_user_id' => $this->adminUser->id,
            'amount_paid' => 1320000,
            'payment_gateway' => 'CASH',
            'payment_status' => 'SUCCESS',
            'payment_time' => Carbon::parse('2026-08-10 10:30:00'),
            'notes' => 'Cư dân thanh toán tiền mặt kỳ 08/2026',
        ]);

        PaymentReceipt::create([
            'receipt_number' => 'BL-202608-0001',
            'payment_id' => $this->pay1->id,
            'receipt_date' => '2026-08-10',
            'amount' => 1320000,
            'amount_in_words' => 'Một triệu ba trăm hai mươi nghìn đồng chẵn',
            'received_from_name' => 'Kế Toán Trưởng Tòa Nhà',
            'digital_signature_hash' => 'hash_signature_08_test',
        ]);

        // Thanh toán 2: Chuyển khoản ngân hàng kỳ 2026-09
        $this->pay2 = Payment::create([
            'payment_reference_code' => 'PAY-202609-A302-SUCCESS',
            'gateway_transaction_id' => 'VCB9922001',
            'invoice_id' => $this->invPeriod2->id,
            'apartment_id' => $this->apt2->id,
            'payer_user_id' => $this->adminUser->id,
            'amount_paid' => 1000000,
            'payment_gateway' => 'BANK_TRANSFER',
            'payment_status' => 'SUCCESS',
            'payment_time' => Carbon::parse('2026-09-12 14:15:00'),
            'notes' => 'Chuyển khoản Vietcombank',
        ]);

        PaymentReceipt::create([
            'receipt_number' => 'BL-202609-0002',
            'payment_id' => $this->pay2->id,
            'receipt_date' => '2026-09-12',
            'amount' => 1000000,
            'amount_in_words' => 'Một triệu đồng chẵn',
            'received_from_name' => 'Kế Toán Trưởng Tòa Nhà',
            'digital_signature_hash' => 'hash_signature_09_test',
        ]);
    }

    public function test_list_payments_history_with_billing_period_and_date_range_filter(): void
    {
        // Lọc kỳ 2026-08
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments/history?billing_period=2026-08');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('PAY-202608-A301-SUCCESS', $data[0]['payment_reference_code']);

        // Lọc theo khoảng ngày date_from và date_to (trong tháng 9/2026)
        $resDate = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments/history?date_from=2026-09-01&date_to=2026-09-30');

        $resDate->assertStatus(200);
        $dateData = $resDate->json('data.data');
        $this->assertCount(1, $dateData);
        $this->assertEquals('PAY-202609-A302-SUCCESS', $dateData[0]['payment_reference_code']);
    }

    public function test_list_payments_filter_by_gateway(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments/history?payment_gateway=BANK_TRANSFER');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('BANK_TRANSFER', $data[0]['payment_gateway']);
        $this->assertEquals('VCB9922001', $data[0]['gateway_transaction_id']);
    }

    public function test_search_payments_by_reference_code_or_apartment(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments/history?search=A-301');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('A-301', $data[0]['apartment']['apartment_number']);
    }

    public function test_payments_summary_calculates_total_amounts_and_counts(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments/summary');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_transactions' => 2,
                    'successful_transactions' => 2,
                    'total_amount' => 2320000,
                ],
            ]);

        $byGateway = $response->json('data.by_gateway');
        $this->assertArrayHasKey('CASH', $byGateway);
        $this->assertArrayHasKey('BANK_TRANSFER', $byGateway);
        $this->assertEquals(1320000, $byGateway['CASH']['total']);
        $this->assertEquals(1000000, $byGateway['BANK_TRANSFER']['total']);
    }

    public function test_get_receipt_detail_by_payment_id(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payments/{$this->pay1->id}/receipt");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'receipt' => [
                        'receipt_number' => 'BL-202608-0001',
                        'amount' => 1320000,
                        'digital_signature_hash' => 'hash_signature_08_test',
                    ],
                ],
            ]);
    }

    public function test_get_receipt_returns_404_if_payment_not_found(): void
    {
        $randomUuid = '01a00000-0000-0000-0000-000000000000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payments/{$randomUuid}/receipt");

        $response->assertStatus(404);
    }
}
