<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token = 'test_revenue_analytics_token';

    protected Block $block;

    protected Floor $floor;

    protected Apartment $apartment1;

    protected Apartment $apartment2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'analytics_admin@example.com',
            'full_name' => 'Analytics Admin User',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        $this->block = Block::create([
            'block_code' => 'BL-ANALYTICS',
            'block_name' => 'Tòa Tháp Phân Tích',
            'total_floors' => 15,
            'total_apartments' => 60,
        ]);

        $this->floor = Floor::create([
            'block_id' => $this->block->id,
            'floor_code' => 'FL-A08',
            'floor_number' => 8,
            'floor_name' => 'Tầng 08',
        ]);

        $this->apartment1 = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'AN-801',
            'net_usable_area_sqm' => 85.5,
            'gross_floor_area_sqm' => 90.0,
            'status' => 'OCCUPIED',
        ]);

        $this->apartment2 = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'AN-802',
            'net_usable_area_sqm' => 95.0,
            'gross_floor_area_sqm' => 100.0,
            'status' => 'OCCUPIED',
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Test lấy toàn bộ dữ liệu Dashboard phân tích doanh thu thành công.
     */
    public function test_can_get_revenue_dashboard_data(): void
    {
        // Tạo hóa đơn 1: Đã thanh toán đầy đủ
        $inv1 = Invoice::create([
            'invoice_number' => 'INV-TEST-202601',
            'apartment_id' => $this->apartment1->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-01',
            'issue_date' => '2026-01-05',
            'total_amount' => 2000000,
            'paid_amount' => 2000000,
            'remaining_balance' => 0,
            'status' => 'PAID',
            'due_date' => '2026-01-25',
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv1->id,
            'service_code' => 'ELECTRICITY',
            'item_description' => 'Tiền điện tháng 01/2026',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 1200000,
            'amount_before_tax' => 1200000,
            'total_line_amount' => 1200000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv1->id,
            'service_code' => 'WATER',
            'item_description' => 'Tiền nước tháng 01/2026',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 800000,
            'amount_before_tax' => 800000,
            'total_line_amount' => 800000,
        ]);

        Payment::create([
            'payment_reference_code' => 'PAY-REF-001',
            'invoice_id' => $inv1->id,
            'apartment_id' => $this->apartment1->id,
            'payer_user_id' => $this->adminUser->id,
            'amount_paid' => 2000000,
            'payment_time' => '2026-01-20 10:00:00',
            'payment_gateway' => 'BANK_TRANSFER',
            'payment_status' => 'SUCCESS',
            'gateway_transaction_id' => 'TXN-BANK-001',
        ]);

        // Tạo hóa đơn 2: Chưa thanh toán (Còn nợ)
        $inv2 = Invoice::create([
            'invoice_number' => 'INV-TEST-202602',
            'apartment_id' => $this->apartment2->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-02',
            'issue_date' => '2026-02-05',
            'total_amount' => 3500000,
            'paid_amount' => 1000000,
            'remaining_balance' => 2500000,
            'status' => 'PARTIAL',
            'due_date' => '2026-02-25',
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv2->id,
            'service_code' => 'MANAGEMENT_FEE',
            'item_description' => 'Phí quản lý tháng 02/2026',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 3500000,
            'amount_before_tax' => 3500000,
            'total_line_amount' => 3500000,
        ]);

        Payment::create([
            'payment_reference_code' => 'PAY-REF-002',
            'invoice_id' => $inv2->id,
            'apartment_id' => $this->apartment2->id,
            'payer_user_id' => $this->adminUser->id,
            'amount_paid' => 1000000,
            'payment_time' => '2026-02-15 14:00:00',
            'payment_gateway' => 'CASH',
            'payment_status' => 'SUCCESS',
            'gateway_transaction_id' => 'TXN-CASH-002',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/v1/invoices/analytics/dashboard?year=2026');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'year',
                    'summary' => [
                        'total_billed',
                        'total_collected',
                        'total_debt',
                        'total_invoices',
                        'paid_invoices',
                        'collection_rate',
                    ],
                    'monthly_trend',
                    'revenue_by_category',
                    'payment_methods',
                    'top_debtors',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(5500000, $data['summary']['total_billed']);
        $this->assertEquals(3000000, $data['summary']['total_collected']);
        $this->assertEquals(2500000, $data['summary']['total_debt']);
        $this->assertEquals(2, $data['summary']['total_invoices']);
    }

    /**
     * Test lấy xu hướng doanh thu theo 12 tháng.
     */
    public function test_can_get_monthly_trend_with_12_months(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/v1/invoices/analytics/monthly-trend?year=2026');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'year',
                'data' => [
                    '*' => [
                        'month',
                        'month_name',
                        'billed_amount',
                        'collected_amount',
                        'debt_amount',
                        'invoice_count',
                        'collection_rate',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(12, $data);
        $this->assertEquals('2026-01', $data[0]['month']);
        $this->assertEquals('2026-12', $data[11]['month']);
    }

    /**
     * Test cơ cấu doanh thu phân bổ theo loại phí (Điện, Nước, Quản lý, Gửi xe).
     */
    public function test_can_get_revenue_by_category_breakdown(): void
    {
        $inv = Invoice::create([
            'invoice_number' => 'INV-CATEGORY-TEST',
            'apartment_id' => $this->apartment1->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-05',
            'issue_date' => '2026-05-05',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'remaining_balance' => 1500000,
            'status' => 'ISSUED',
            'due_date' => '2026-05-25',
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'service_code' => 'ELECTRICITY',
            'item_description' => 'Điện tiêu thụ',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 1000000,
            'amount_before_tax' => 1000000,
            'total_line_amount' => 1000000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'service_code' => 'PARKING_FEE',
            'item_description' => 'Gửi 2 xe máy',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 500000,
            'amount_before_tax' => 500000,
            'total_line_amount' => 500000,
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/v1/invoices/analytics/category-breakdown?year=2026');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'item_type',
                        'label',
                        'total_amount',
                        'percentage',
                        'color',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $types = array_column($data, 'item_type');
        $this->assertContains('ELECTRICITY', $types);
        $this->assertContains('PARKING_FEE', $types);
    }

    /**
     * Test danh sách Top căn hộ còn nợ nhiều nhất.
     */
    public function test_can_get_top_debtors_ranking(): void
    {
        // Căn 1 nợ 4,000,000
        Invoice::create([
            'invoice_number' => 'INV-DEBT-1',
            'apartment_id' => $this->apartment1->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-03',
            'issue_date' => '2026-03-05',
            'total_amount' => 4000000,
            'paid_amount' => 0,
            'remaining_balance' => 4000000,
            'status' => 'OVERDUE',
            'due_date' => '2026-03-25',
        ]);

        // Căn 2 nợ 8,000,000 (nhiều hơn căn 1)
        Invoice::create([
            'invoice_number' => 'INV-DEBT-2',
            'apartment_id' => $this->apartment2->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-03',
            'issue_date' => '2026-03-05',
            'total_amount' => 8000000,
            'paid_amount' => 0,
            'remaining_balance' => 8000000,
            'status' => 'OVERDUE',
            'due_date' => '2026-03-25',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/v1/invoices/analytics/top-debtors?limit=5');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'apartment_id',
                        'apartment_number',
                        'block_name',
                        'total_debt',
                        'unpaid_invoice_count',
                    ],
                ],
            ]);

        $debtors = $response->json('data');
        $this->assertNotEmpty($debtors);
        // Căn 2 nợ 8,000,000 phải đứng đầu bảng
        $this->assertEquals('AN-802', $debtors[0]['apartment_number']);
        $this->assertEquals(8000000, $debtors[0]['total_debt']);
    }

    /**
     * Test từ chối truy cập khi không có xác thực.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/invoices/analytics/dashboard');

        $response->assertStatus(401);
    }
}
