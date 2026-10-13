<?php

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceGenerationBatch;
use App\Models\InvoiceItem;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\PricingTier;
use App\Models\ServicePricingConfig;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceBatchGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token;

    protected Block $blockA;

    protected Block $blockB;

    protected Apartment $aptA101;

    protected Apartment $aptA102;

    protected Apartment $aptB201;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo User Admin với status ACTIVE
        $this->adminUser = User::factory()->create([
            'email' => 'accountant@cassavas.com',
            'full_name' => 'Kế Toán Trưởng',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        // 2. Tạo Block và Floors
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

        // 3. Tạo Căn hộ
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
            'net_usable_area_sqm' => 60.0,
            'gross_floor_area_sqm' => 65.0,
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

        // 4. Tạo Cấu hình giá Điện lũy tiến (Bậc 1: 0-50 kWh giá 1806, Bậc 2: >50 kWh giá 2167, VAT 8%)
        $elecConfig = ServicePricingConfig::updateOrCreate(
            ['service_code' => 'ELECTRICITY'],
            [
                'service_name' => 'Điện Sinh Hoạt',
                'meter_type' => 'ELECTRICITY',
                'billing_type' => 'TIERED_USAGE',
                'unit_name' => 'kWh',
                'vat_percentage' => 8.0,
                'environmental_protection_fee_pct' => 0.0,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        PricingTier::where('pricing_config_id', $elecConfig->id)->delete();

        PricingTier::create([
            'pricing_config_id' => $elecConfig->id,
            'tier_order' => 1,
            'tier_name' => 'Bậc 1 (0-50 kWh)',
            'min_usage_threshold' => 0,
            'max_usage_threshold' => 50,
            'unit_price' => 1806.00,
        ]);

        PricingTier::create([
            'pricing_config_id' => $elecConfig->id,
            'tier_order' => 2,
            'tier_name' => 'Bậc 2 (> 50 kWh)',
            'min_usage_threshold' => 50,
            'max_usage_threshold' => null,
            'unit_price' => 2167.00,
        ]);

        // 5. Cấu hình giá Nước lũy tiến (Bậc 1: 0-10 m3 giá 7500, Bậc 2: >10 m3 giá 9000, VAT 5%, Phí BVMT 10%)
        $waterConfig = ServicePricingConfig::updateOrCreate(
            ['service_code' => 'WATER'],
            [
                'service_name' => 'Nước Sinh Hoạt',
                'meter_type' => 'WATER',
                'billing_type' => 'TIERED_USAGE',
                'unit_name' => 'm³',
                'vat_percentage' => 5.0,
                'environmental_protection_fee_pct' => 10.0,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        PricingTier::where('pricing_config_id', $waterConfig->id)->delete();

        PricingTier::create([
            'pricing_config_id' => $waterConfig->id,
            'tier_order' => 1,
            'tier_name' => 'Bậc 1 (0-10 m3)',
            'min_usage_threshold' => 0,
            'max_usage_threshold' => 10,
            'unit_price' => 7500.00,
        ]);

        PricingTier::create([
            'pricing_config_id' => $waterConfig->id,
            'tier_order' => 2,
            'tier_name' => 'Bậc 2 (> 10 m3)',
            'min_usage_threshold' => 10,
            'max_usage_threshold' => null,
            'unit_price' => 9000.00,
        ]);

        // 6. Cấu hình Phí Quản Lý Vận Hành
        ServicePricingConfig::updateOrCreate(
            ['service_code' => 'MANAGEMENT_FEE'],
            [
                'service_name' => 'Phí Quản Lý Vận Hành',
                'billing_type' => 'FIXED_PER_SQM',
                'unit_name' => 'm²',
                'fixed_unit_price' => 15000.00,
                'vat_percentage' => 10.0,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        // 7. Tạo Đồng hồ và Chỉ số đo mẫu kỳ 2026-10 cho căn A-101
        $elecMeter = Meter::create([
            'apartment_id' => $this->aptA101->id,
            'meter_code' => 'EM-A101',
            'meter_type' => 'ELECTRICITY',
            'installation_date' => '2026-01-01',
            'status' => 'ACTIVE',
        ]);

        MeterReading::create([
            'meter_id' => $elecMeter->id,
            'apartment_id' => $this->aptA101->id,
            'billing_cycle' => '2026-10',
            'period_start_date' => '2026-09-01',
            'period_end_date' => '2026-09-30',
            'previous_reading' => 100,
            'current_reading' => 180,
            'consumed_units' => 80, // 50 * 1806 + 30 * 2167 = 90,300 + 65,010 = 155,310 + VAT 8% (12,424.8) = 167,734.8
            'reading_source' => 'MANUAL_ENTRY',
            'is_locked_for_billing' => false,
        ]);

        $waterMeter = Meter::create([
            'apartment_id' => $this->aptA101->id,
            'meter_code' => 'WM-A101',
            'meter_type' => 'WATER',
            'installation_date' => '2026-01-01',
            'status' => 'ACTIVE',
        ]);

        MeterReading::create([
            'meter_id' => $waterMeter->id,
            'apartment_id' => $this->aptA101->id,
            'billing_cycle' => '2026-10',
            'period_start_date' => '2026-09-01',
            'period_end_date' => '2026-09-30',
            'previous_reading' => 20,
            'current_reading' => 35,
            'consumed_units' => 15, // 10 * 7500 + 5 * 9000 = 75,000 + 45,000 = 120,000 + VAT 5% (6000) + BVMT 10% (12000) = 138,000
            'reading_source' => 'MANUAL_ENTRY',
            'is_locked_for_billing' => false,
        ]);

        // 8. Phương tiện cho căn A-101
        Vehicle::create([
            'apartment_id' => $this->aptA101->id,
            'owner_user_id' => $this->adminUser->id,
            'license_plate' => '29A-12345',
            'vehicle_category' => 'CAR',
            'monthly_parking_fee' => 1200000.00,
            'is_active' => true,
        ]);
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    public function test_cannot_access_invoice_batch_apis_without_authentication(): void
    {
        $response = $this->postJson('/api/v1/invoices/batch/preview', [
            'billing_period' => '2026-10',
        ]);

        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/invoices/batch/generate', [
            'billing_period' => '2026-10',
        ]);

        $response->assertStatus(401);
    }

    public function test_preview_batch_returns_accurate_estimation_and_breakdowns(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/preview', [
                'billing_period' => '2026-10',
            ]);

        $totalApts = Apartment::count();

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_apartments', $totalApts)
            ->assertJsonPath('data.existing_invoices_count', 0)
            ->assertJsonPath('data.eligible_for_generation', $totalApts);

        $previewData = $response->json('data.preview_items');
        $this->assertCount($totalApts, $previewData);

        // Tìm căn A-101
        $apt101Preview = collect($previewData)->firstWhere('apartment_number', 'A-101');
        $this->assertNotNull($apt101Preview);
        $this->assertTrue($apt101Preview['has_electricity_reading']);
        $this->assertTrue($apt101Preview['has_water_reading']);
        $this->assertGreaterThan(0, $apt101Preview['total_amount']);
    }

    public function test_preview_batch_validates_billing_period_format(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/preview', [
                'billing_period' => '2026/10', // Sai định dạng YYYY-MM
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['billing_period']);
    }

    public function test_generate_batch_creates_invoices_and_items_within_transaction(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
                'issue_date' => '2026-10-01',
                'due_date' => '2026-10-15',
                'notes' => 'Hóa đơn dịch vụ tháng 10 năm 2026',
            ]);

        $totalApts = Apartment::count();

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_invoices_created', $totalApts)
            ->assertJsonPath('data.errors_count', 0);

        // Kiểm tra Batch trong DB
        $this->assertDatabaseHas('invoice_generation_batches', [
            'billing_period' => '2026-10',
            'status' => 'COMPLETED',
            'total_invoices_created' => $totalApts,
        ]);

        // Kiểm tra Hóa đơn của từng căn
        $this->assertDatabaseHas('invoices', [
            'apartment_id' => $this->aptA101->id,
            'billing_period' => '2026-10',
            'status' => 'ISSUED',
        ]);

        $invoiceA101 = Invoice::where('apartment_id', $this->aptA101->id)
            ->where('billing_period', '2026-10')
            ->first();

        $this->assertNotNull($invoiceA101);
        $this->assertStringStartsWith('HD-202610-T-A-A-101', $invoiceA101->invoice_number);

        // Kiểm tra các khoản mục chi tiết của căn A-101
        $items = InvoiceItem::where('invoice_id', $invoiceA101->id)->get();
        $this->assertTrue($items->contains('service_code', 'ELECTRICITY'));
        $this->assertTrue($items->contains('service_code', 'WATER'));
        $this->assertTrue($items->contains('service_code', 'MANAGEMENT_FEE'));
        $this->assertTrue($items->contains('service_code', 'PARKING_FEE'));
    }

    public function test_generate_batch_locks_meter_readings_for_billing(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
            ]);

        // Các bản ghi meter_reading phải được đổi is_locked_for_billing = true
        $this->assertDatabaseHas('meter_readings', [
            'apartment_id' => $this->aptA101->id,
            'billing_cycle' => '2026-10',
            'is_locked_for_billing' => 1,
        ]);
    }

    public function test_generate_batch_calculates_electricity_and_water_with_tiered_rates(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
            ]);

        $invoice = Invoice::where('apartment_id', $this->aptA101->id)
            ->where('billing_period', '2026-10')
            ->first();

        $this->assertNotNull($invoice);

        $elecItem = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('service_code', 'ELECTRICITY')
            ->first();

        $this->assertNotNull($elecItem);
        $this->assertEquals(80.0, $elecItem->quantity);
        $this->assertNotEmpty($elecItem->tier_calculation_details);

        $waterItem = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('service_code', 'WATER')
            ->first();

        $this->assertNotNull($waterItem);
        $this->assertEquals(15.0, $waterItem->quantity);
        $this->assertGreaterThan(0, $waterItem->environmental_fee_amount);
    }

    public function test_generate_batch_includes_previous_unpaid_debt(): void
    {
        // Tạo hóa đơn tháng trước còn nợ 500,000 VND
        Invoice::create([
            'invoice_number' => 'HD-202609-T-A-A-101',
            'apartment_id' => $this->aptA101->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-09',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'subtotal_amount' => 500000.0,
            'tax_amount' => 50000.0,
            'total_amount' => 550000.0,
            'paid_amount' => 50000.0,
            'remaining_balance' => 500000.0, // Nợ 500k
            'status' => 'PARTIAL',
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
                'include_previous_debt' => true,
            ]);

        $currentInvoice = Invoice::where('apartment_id', $this->aptA101->id)
            ->where('billing_period', '2026-10')
            ->first();

        $this->assertNotNull($currentInvoice);
        $this->assertEquals(500000.0, $currentInvoice->previous_debt_amount);
        $this->assertEquals($currentInvoice->total_amount, $currentInvoice->remaining_balance);
    }

    public function test_generate_batch_skips_existing_unless_overwrite_is_true(): void
    {
        // Chạy lần 1
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
            ]);

        $totalApts = Apartment::count();
        $countBefore = Invoice::where('billing_period', '2026-10')->count();
        $this->assertEquals($totalApts, $countBefore);

        // Chạy lần 2 không bật overwrite -> bị skip toàn bộ căn
        $res = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
                'overwrite_existing' => false,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.total_invoices_created', 0)
            ->assertJsonPath('data.skipped_count', $totalApts);

        $this->assertEquals($countBefore, Invoice::where('billing_period', '2026-10')->count());
    }

    public function test_generate_batch_filters_by_specific_block(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-10',
                'block_id' => $this->blockA->id, // Chỉ sinh cho Tòa A (có 2 căn A-101 và A-102)
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_invoices_created', 2);

        $this->assertDatabaseMissing('invoices', [
            'apartment_id' => $this->aptB201->id,
            'billing_period' => '2026-10',
        ]);
    }

    public function test_list_generation_batches_and_show_detail(): void
    {
        $batch = InvoiceGenerationBatch::create([
            'batch_number' => 'INV-BATCH-202610-ALL-0001',
            'billing_period' => '2026-10',
            'executed_by_user_id' => $this->adminUser->id,
            'total_apartments_processed' => 10,
            'total_invoices_created' => 10,
            'total_amount_calculated' => 15000000.00,
            'status' => 'COMPLETED',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $listRes = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/invoices/batches');

        $listRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $detailRes = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/invoices/batches/{$batch->id}");

        $detailRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch.batch_number', 'INV-BATCH-202610-ALL-0001');
    }
}
