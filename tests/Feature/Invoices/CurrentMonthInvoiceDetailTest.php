<?php

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentMonthInvoiceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $residentUser;

    protected string $token;

    protected Block $block;

    protected Floor $floor;

    protected Apartment $apartment;

    protected Invoice $currentInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->residentUser = User::factory()->create([
            'email' => 'resident.hoa@cassavas.com',
            'full_name' => 'Nguyễn Xuân Hòa Cư Dân',
            'status' => 'ACTIVE',
        ]);

        $this->token = $this->issueTestBearerToken($this->residentUser);

        $this->block = Block::create([
            'block_code' => 'T-A',
            'block_name' => 'Tòa Tháp A Sapphire',
            'total_floors' => 25,
            'total_apartments' => 100,
        ]);

        $this->floor = Floor::create([
            'block_id' => $this->block->id,
            'floor_code' => 'FL-A05',
            'floor_number' => 5,
            'floor_name' => 'Tầng 5',
        ]);

        $this->apartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'A-502',
            'net_usable_area_sqm' => 80.0,
            'gross_floor_area_sqm' => 85.0,
            'current_resident_user_id' => $this->residentUser->id,
            'status' => 'OCCUPIED',
        ]);

        $currentPeriod = Carbon::now()->format('Y-m');

        // Tạo hóa đơn tháng hiện tại
        $this->currentInvoice = Invoice::create([
            'invoice_number' => 'HD-'.$currentPeriod.'-A502',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->residentUser->id,
            'billing_period' => $currentPeriod,
            'issue_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
            'due_date' => Carbon::now()->startOfMonth()->addDays(20)->format('Y-m-d'),
            'subtotal_amount' => 1500000,
            'tax_amount' => 150000,
            'previous_debt_amount' => 0,
            'total_amount' => 1650000,
            'paid_amount' => 650000,
            'remaining_balance' => 1000000,
            'status' => 'PARTIAL',
        ]);

        // 1. Dòng tiền điện (có bậc thang)
        InvoiceItem::create([
            'invoice_id' => $this->currentInvoice->id,
            'service_code' => 'ELECTRICITY',
            'item_description' => 'Tiền điện sinh hoạt lũy tiến',
            'quantity' => 180,
            'unit_name' => 'kWh',
            'unit_price' => 2500,
            'amount_before_tax' => 450000,
            'vat_percentage' => 8,
            'vat_amount' => 36000,
            'environmental_fee_amount' => 0,
            'total_line_amount' => 486000,
            'tier_calculation_details' => [
                ['tier_name' => 'Bậc 1 (0-50 kWh)', 'tier_usage' => 50, 'unit_price' => 1806, 'tier_amount' => 90300],
                ['tier_name' => 'Bậc 2 (51-100 kWh)', 'tier_usage' => 50, 'unit_price' => 1866, 'tier_amount' => 93300],
                ['tier_name' => 'Bậc 3 (101-200 kWh)', 'tier_usage' => 80, 'unit_price' => 2167, 'tier_amount' => 173360],
            ],
        ]);

        // 2. Dòng tiền nước
        InvoiceItem::create([
            'invoice_id' => $this->currentInvoice->id,
            'service_code' => 'WATER',
            'item_description' => 'Tiền nước sinh hoạt',
            'quantity' => 25,
            'unit_name' => 'm3',
            'unit_price' => 12000,
            'amount_before_tax' => 300000,
            'vat_percentage' => 5,
            'vat_amount' => 15000,
            'environmental_fee_amount' => 30000,
            'total_line_amount' => 345000,
        ]);

        // 3. Phí quản lý vận hành
        InvoiceItem::create([
            'invoice_id' => $this->currentInvoice->id,
            'service_code' => 'MANAGEMENT_FEE',
            'item_description' => 'Phí quản lý vận hành tòa nhà',
            'quantity' => 80.0,
            'unit_name' => 'm2',
            'unit_price' => 10000,
            'amount_before_tax' => 800000,
            'vat_percentage' => 10,
            'vat_amount' => 80000,
            'environmental_fee_amount' => 0,
            'total_line_amount' => 880000,
        ]);

        // Tạo 1 giao dịch thanh toán đã thực hiện trước đó
        $payment = Payment::create([
            'payment_reference_code' => 'PAY-TEST-001',
            'invoice_id' => $this->currentInvoice->id,
            'apartment_id' => $this->apartment->id,
            'payer_user_id' => $this->residentUser->id,
            'amount_paid' => 650000,
            'payment_gateway' => 'BANK_TRANSFER',
            'payment_status' => 'SUCCESS',
            'payment_time' => Carbon::now()->subDay(),
        ]);

        PaymentReceipt::create([
            'receipt_number' => 'BL-202610-0001',
            'payment_id' => $payment->id,
            'receipt_date' => Carbon::now()->subDay()->format('Y-m-d'),
            'amount' => 650000,
            'amount_in_words' => 'Sáu trăm năm mươi nghìn đồng chẵn',
            'received_from_name' => 'Nguyễn Xuân Hòa',
            'digital_signature_hash' => 'dummy_sha256_signature_hash',
        ]);
    }

    public function test_get_current_month_invoice_returns_detail_with_items_and_payments(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Lấy chi tiết hóa đơn tháng hiện tại thành công.',
                'data' => [
                    'id' => $this->currentInvoice->id,
                    'invoice_number' => $this->currentInvoice->invoice_number,
                    'status' => 'PARTIAL',
                    'remaining_balance' => 1000000,
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(3, $data['items']);
        $this->assertCount(1, $data['payments']);
        $this->assertEquals('BL-202610-0001', $data['payments'][0]['receipt']['receipt_number']);
    }

    public function test_get_current_month_invoice_by_resident_user_context(): void
    {
        // Truy cập không cần truyền apartment_id, tự nhận diện theo user
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/invoices/current-month');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'invoice_number' => $this->currentInvoice->invoice_number,
                ],
            ]);
    }

    public function test_get_current_month_invoice_returns_404_if_none_exists(): void
    {
        // Tạo apartment của chính resident nhưng không có hóa đơn nào
        $emptyApartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'A-EMPTY',
            'net_usable_area_sqm' => 50.0,
            'gross_floor_area_sqm' => 55.0,
            'current_resident_user_id' => $this->residentUser->id,
            'status' => 'OCCUPIED',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$emptyApartment->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn cho kỳ phí hiện tại.',
            ]);
    }

    public function test_does_not_fallback_to_previous_month_invoice_and_returns_404(): void
    {
        // Căn hộ của cư dân chỉ có hóa đơn của 2 tháng trước, không có tháng hiện tại
        $pastPeriod = Carbon::now()->subMonths(2)->format('Y-m');
        $apartmentPast = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'A-PAST-ONLY',
            'net_usable_area_sqm' => 60.0,
            'gross_floor_area_sqm' => 65.0,
            'current_resident_user_id' => $this->residentUser->id,
            'status' => 'OCCUPIED',
        ]);

        Invoice::create([
            'invoice_number' => 'HD-'.$pastPeriod.'-PAST',
            'apartment_id' => $apartmentPast->id,
            'resident_user_id' => $this->residentUser->id,
            'billing_period' => $pastPeriod,
            'issue_date' => Carbon::now()->subMonths(2)->startOfMonth()->format('Y-m-d'),
            'due_date' => Carbon::now()->subMonths(2)->startOfMonth()->addDays(20)->format('Y-m-d'),
            'subtotal_amount' => 1000000,
            'tax_amount' => 100000,
            'total_amount' => 1100000,
            'paid_amount' => 1100000,
            'remaining_balance' => 0,
            'status' => 'PAID',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$apartmentPast->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn cho kỳ phí hiện tại.',
            ]);
    }

    public function test_cancelled_current_month_invoice_returns_404(): void
    {
        $this->currentInvoice->update(['status' => 'CANCELLED']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn cho kỳ phí hiện tại.',
            ]);
    }

    public function test_draft_current_month_invoice_returns_404(): void
    {
        $this->currentInvoice->update(['status' => 'DRAFT']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn cho kỳ phí hiện tại.',
            ]);
    }

    public function test_valid_statuses_current_month_invoice_returns_200(): void
    {
        foreach (['ISSUED', 'PARTIAL', 'PAID', 'OVERDUE'] as $validStatus) {
            $this->currentInvoice->update(['status' => $validStatus]);

            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'status' => $validStatus,
                    ],
                ]);
        }
    }

    public function test_resident_cannot_view_another_residents_apartment_invoice(): void
    {
        // Cư dân B với căn hộ khác
        $residentB = User::factory()->create([
            'email' => 'resident.b@cassavas.com',
            'full_name' => 'Cư Dân B',
            'status' => 'ACTIVE',
        ]);

        $apartmentB = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'B-909',
            'net_usable_area_sqm' => 90.0,
            'gross_floor_area_sqm' => 95.0,
            'current_resident_user_id' => $residentB->id,
            'status' => 'OCCUPIED',
        ]);

        Invoice::create([
            'invoice_number' => 'HD-'.Carbon::now()->format('Y-m').'-B909',
            'apartment_id' => $apartmentB->id,
            'resident_user_id' => $residentB->id,
            'billing_period' => Carbon::now()->format('Y-m'),
            'issue_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
            'due_date' => Carbon::now()->startOfMonth()->addDays(20)->format('Y-m-d'),
            'subtotal_amount' => 2000000,
            'tax_amount' => 200000,
            'total_amount' => 2200000,
            'paid_amount' => 0,
            'remaining_balance' => 2200000,
            'status' => 'ISSUED',
        ]);

        // Cư dân A (token) cố xem hóa đơn của căn hộ B -> 403
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$apartmentB->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền xem hóa đơn của căn hộ này.',
            ]);
    }

    public function test_resident_can_view_own_apartment_invoice_with_apartment_id(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->currentInvoice->id,
                    'apartment_id' => $this->apartment->id,
                ],
            ]);
    }

    public function test_admin_or_accountant_can_view_any_apartment_invoice(): void
    {
        $adminUser = User::factory()->create([
            'email' => 'manager.building@cassavas.com',
            'full_name' => 'Ban Quản Lý Tòa Nhà',
            'status' => 'ACTIVE',
        ]);

        $role = Role::firstOrCreate(
            ['role_code' => 'BUILDING_MANAGER'],
            ['role_name' => 'Ban Quản Lý Tòa Nhà', 'status' => 'ACTIVE']
        );

        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $adminUser->id, 'role_id' => $role->id],
            ['assigned_at' => now()]
        );

        $adminToken = $this->issueTestBearerToken($adminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/current-month?apartment_id={$this->apartment->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->currentInvoice->id,
                    'apartment_id' => $this->apartment->id,
                ],
            ]);
    }

    public function test_get_invoice_detail_by_id_includes_payments_and_receipts(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/{$this->currentInvoice->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->currentInvoice->id,
                    'invoice_number' => $this->currentInvoice->invoice_number,
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['payments']);
        $this->assertNotNull($data['payments'][0]['receipt']);
    }

    public function test_get_detailed_statement_returns_categorized_breakdown_and_words(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/{$this->currentInvoice->id}/statement");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_amount' => 1650000,
                        'remaining_balance' => 1000000,
                    ],
                ],
            ]);

        $data = $response->json('data');
        // Kiểm tra đọc số tiền bằng chữ tiếng Việt
        $this->assertStringContainsString('đồng chẵn', $data['summary']['amount_in_words']);
        $this->assertNotEmpty($data['categorized_items']['electricity']);
        $this->assertNotEmpty($data['categorized_items']['water']);
        $this->assertNotEmpty($data['categorized_items']['management']);
    }
}
