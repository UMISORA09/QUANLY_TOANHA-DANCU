<?php

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token;

    protected Block $blockA;

    protected Block $blockB;

    protected Apartment $aptA101;

    protected Apartment $aptA102;

    protected Apartment $aptB201;

    protected Invoice $invoiceIssued;

    protected Invoice $invoicePaid;

    protected Invoice $invoiceOverdue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'admin@cassavas.com',
            'full_name' => 'Kế Toán Trưởng Admin',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        $this->blockA = Block::create([
            'block_code' => 'T-A',
            'block_name' => 'Tòa Tháp A Sapphire',
            'total_floors' => 25,
            'total_apartments' => 100,
        ]);

        $this->blockB = Block::create([
            'block_code' => 'T-B',
            'block_name' => 'Tòa Tháp B Ruby',
            'total_floors' => 20,
            'total_apartments' => 80,
        ]);

        $floorA1 = Floor::create([
            'block_id' => $this->blockA->id,
            'floor_code' => 'FL-A01',
            'floor_number' => 1,
            'floor_name' => 'Tầng 1',
        ]);

        $floorB2 = Floor::create([
            'block_id' => $this->blockB->id,
            'floor_code' => 'FL-B02',
            'floor_number' => 2,
            'floor_name' => 'Tầng 2',
        ]);

        $this->aptA101 = Apartment::create([
            'block_id' => $this->blockA->id,
            'floor_id' => $floorA1->id,
            'apartment_number' => 'A-101',
            'net_usable_area_sqm' => 80.0,
            'gross_floor_area_sqm' => 85.0,
            'status' => 'OCCUPIED',
            'current_resident_user_id' => $this->adminUser->id,
        ]);

        $this->aptA102 = Apartment::create([
            'block_id' => $this->blockA->id,
            'floor_id' => $floorA1->id,
            'apartment_number' => 'A-102',
            'net_usable_area_sqm' => 65.0,
            'gross_floor_area_sqm' => 70.0,
            'status' => 'OCCUPIED',
            'current_resident_user_id' => $this->adminUser->id,
        ]);

        $this->aptB201 = Apartment::create([
            'block_id' => $this->blockB->id,
            'floor_id' => $floorB2->id,
            'apartment_number' => 'B-201',
            'net_usable_area_sqm' => 100.0,
            'gross_floor_area_sqm' => 105.0,
            'status' => 'OCCUPIED',
            'current_resident_user_id' => $this->adminUser->id,
        ]);

        // Tạo 3 hóa đơn với các trạng thái khác nhau
        // 1. Hóa đơn ISSUED (hạn tương lai)
        $this->invoiceIssued = Invoice::create([
            'invoice_number' => 'HD-202610-T-A-A-101',
            'apartment_id' => $this->aptA101->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-10',
            'issue_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'subtotal_amount' => 1000000.00,
            'tax_amount' => 100000.00,
            'total_amount' => 1100000.00,
            'paid_amount' => 0.00,
            'remaining_balance' => 1100000.00,
            'status' => 'ISSUED',
        ]);

        // 2. Hóa đơn PAID (đã thanh toán đủ)
        $this->invoicePaid = Invoice::create([
            'invoice_number' => 'HD-202609-T-A-A-102',
            'apartment_id' => $this->aptA102->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-09',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'subtotal_amount' => 800000.00,
            'tax_amount' => 80000.00,
            'total_amount' => 880000.00,
            'paid_amount' => 880000.00,
            'remaining_balance' => 0.00,
            'status' => 'PAID',
        ]);

        // 3. Hóa đơn OVERDUE (quá hạn)
        $this->invoiceOverdue = Invoice::create([
            'invoice_number' => 'HD-202608-T-B-B-201',
            'apartment_id' => $this->aptB201->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-08',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15', // Quá hạn
            'subtotal_amount' => 1500000.00,
            'tax_amount' => 150000.00,
            'total_amount' => 1650000.00,
            'paid_amount' => 0.00,
            'remaining_balance' => 1650000.00,
            'status' => 'ISSUED', // Do due_date < now nên query sẽ nhận diện là OVERDUE
        ]);
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    public function test_cannot_access_invoices_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/invoices');
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/invoices/summary');
        $response->assertStatus(401);
    }

    public function test_list_invoices_with_pagination(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?per_page=10');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'current_page',
                    'total',
                ],
            ]);

        $this->assertGreaterThanOrEqual(3, $response->json('data.total'));
    }

    public function test_filter_invoices_by_status(): void
    {
        // 1. Lọc hóa đơn PAID
        $resPaid = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?status=PAID');

        $resPaid->assertStatus(200)
            ->assertJsonPath('success', true);

        foreach ($resPaid->json('data.data') as $inv) {
            $this->assertEquals('PAID', $inv['status']);
        }

        // 2. Lọc hóa đơn OVERDUE
        $resOverdue = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?status=OVERDUE');

        $resOverdue->assertStatus(200);
        $overdueNumbers = collect($resOverdue->json('data.data'))->pluck('invoice_number');
        $this->assertTrue($overdueNumbers->contains('HD-202608-T-B-B-201'));

        // 3. Lọc hóa đơn ISSUED còn hạn
        $resIssued = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?status=ISSUED');

        $resIssued->assertStatus(200);
        $issuedNumbers = collect($resIssued->json('data.data'))->pluck('invoice_number');
        $this->assertTrue($issuedNumbers->contains('HD-202610-T-A-A-101'));
    }

    public function test_filter_invoices_by_billing_period(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?billing_period=2026-10');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('HD-202610-T-A-A-101', $data[0]['invoice_number']);
    }

    public function test_filter_invoices_by_block(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/invoices?block_id={$this->blockB->id}");

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('HD-202608-T-B-B-201', $data[0]['invoice_number']);
    }

    public function test_search_invoices_by_apartment_or_code(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices?search=A-101');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('HD-202610-T-A-A-101', $data[0]['invoice_number']);
    }

    public function test_get_invoice_financial_summary_metrics(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices/summary');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_invoices',
                    'total_amount',
                    'paid_amount',
                    'remaining_balance',
                    'collection_rate',
                    'counts_by_status' => [
                        'DRAFT',
                        'ISSUED',
                        'PARTIAL',
                        'PAID',
                        'OVERDUE',
                        'CANCELLED',
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(3, $response->json('data.total_invoices'));
        $this->assertGreaterThan(0, $response->json('data.paid_amount'));
        $this->assertGreaterThan(0, $response->json('data.remaining_balance'));
    }

    public function test_show_invoice_detail_with_items(): void
    {
        // Thêm item vào hóa đơn
        InvoiceItem::create([
            'invoice_id' => $this->invoiceIssued->id,
            'service_code' => 'MANAGEMENT_FEE',
            'item_description' => 'Phí quản lý vận hành',
            'quantity' => 80,
            'unit_name' => 'm²',
            'unit_price' => 12500,
            'amount_before_tax' => 1000000,
            'vat_percentage' => 10,
            'vat_amount' => 100000,
            'total_line_amount' => 1100000,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/invoices/{$this->invoiceIssued->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_number', 'HD-202610-T-A-A-101')
            ->assertJsonPath('data.items.0.service_code', 'MANAGEMENT_FEE');
    }

    public function test_cancel_unpaid_invoice_and_unlock_meters(): void
    {
        // Gắn 1 meter reading bị khóa vào hóa đơn
        $meter = Meter::create([
            'apartment_id' => $this->aptA101->id,
            'meter_code' => 'EM-CANCEL-TEST',
            'meter_type' => 'ELECTRICITY',
            'installation_date' => '2026-01-01',
            'status' => 'ACTIVE',
        ]);

        $reading = MeterReading::create([
            'meter_id' => $meter->id,
            'apartment_id' => $this->aptA101->id,
            'billing_cycle' => '2026-10',
            'period_start_date' => '2026-09-01',
            'period_end_date' => '2026-09-30',
            'previous_reading' => 10,
            'current_reading' => 20,
            'consumed_units' => 10,
            'is_locked_for_billing' => true,
        ]);

        InvoiceItem::create([
            'invoice_id' => $this->invoiceIssued->id,
            'service_code' => 'ELECTRICITY',
            'item_description' => 'Điện sinh hoạt',
            'meter_reading_id' => $reading->id,
            'quantity' => 10,
            'unit_name' => 'kWh',
            'unit_price' => 2000,
            'amount_before_tax' => 20000,
            'vat_percentage' => 10,
            'vat_amount' => 2000,
            'total_line_amount' => 22000,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/invoices/{$this->invoiceIssued->id}/cancel", [
                'reason' => 'Khách hàng đổi hợp đồng',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'CANCELLED');

        // Bản ghi meter_reading phải được mở khóa
        $reading->refresh();
        $this->assertFalse((bool) $reading->is_locked_for_billing);
    }

    public function test_cannot_cancel_already_paid_invoice(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/invoices/{$this->invoicePaid->id}/cancel", [
                'reason' => 'Muốn hủy',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->invoicePaid->refresh();
        $this->assertEquals('PAID', $this->invoicePaid->status);
    }

    public function test_bulk_cancel_invoices(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/bulk-cancel', [
                'invoice_ids' => [
                    $this->invoiceIssued->id,
                    $this->invoiceOverdue->id,
                ],
                'reason' => 'Hủy hàng loạt cuối năm',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cancelled_count', 2);

        $this->invoiceIssued->refresh();
        $this->invoiceOverdue->refresh();
        $this->assertEquals('CANCELLED', $this->invoiceIssued->status);
        $this->assertEquals('CANCELLED', $this->invoiceOverdue->status);
    }
}
