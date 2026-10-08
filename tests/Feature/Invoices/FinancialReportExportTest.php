<?php

declare(strict_types=1);

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token;

    protected Block $block;

    protected Floor $floor;

    protected Apartment $apartment;

    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'admin.report@cassavas.com',
            'full_name' => 'Kế Toán Trưởng',
            'status' => 'ACTIVE',
        ]);

        $this->token = 'smart_token_'.$this->adminUser->id.'_invoice';

        $this->block = Block::create([
            'block_code' => 'T-REPORT',
            'block_name' => 'Tòa Nhà Báo Cáo',
            'total_floors' => 10,
            'total_apartments' => 40,
        ]);

        $this->floor = Floor::create([
            'block_id' => $this->block->id,
            'floor_code' => 'FL-R05',
            'floor_number' => 5,
            'floor_name' => 'Tầng 5',
        ]);

        $this->apartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $this->floor->id,
            'apartment_number' => 'RP-501',
            'net_usable_area_sqm' => 70.0,
            'gross_floor_area_sqm' => 75.0,
            'status' => 'OCCUPIED',
        ]);

        // Tạo hóa đơn mẫu
        $this->invoice = Invoice::create([
            'invoice_number' => 'INV-REP-202606',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-06',
            'issue_date' => '2026-06-05',
            'due_date' => '2026-06-25',
            'total_amount' => 2500000,
            'paid_amount' => 500000,
            'remaining_balance' => 2000000,
            'status' => 'PARTIAL',
        ]);

        InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'service_code' => 'ELECTRICITY',
            'item_description' => 'Tiền điện sinh hoạt',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 1500000,
            'amount_before_tax' => 1500000,
            'total_line_amount' => 1500000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'service_code' => 'WATER',
            'item_description' => 'Tiền nước sinh hoạt',
            'quantity' => 1,
            'unit_name' => 'Kỳ',
            'unit_price' => 1000000,
            'amount_before_tax' => 1000000,
            'total_line_amount' => 1000000,
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
     * Test xem trước (preview) dữ liệu báo cáo tài chính thành công.
     */
    public function test_can_preview_financial_report_data(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/v1/reports/financial/preview?period=2026-06');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'filters',
                    'summary' => [
                        'total_invoices',
                        'total_billed',
                        'total_collected',
                        'total_debt',
                        'collection_rate',
                    ],
                    'rows' => [
                        '*' => [
                            'stt',
                            'invoice_number',
                            'apartment_number',
                            'resident_name',
                            'total_amount',
                            'paid_amount',
                            'remaining_balance',
                            'electricity_amount',
                            'water_amount',
                        ],
                    ],
                    'generated_at',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(1, $data['summary']['total_invoices']);
        $this->assertEquals(2500000, $data['summary']['total_billed']);
        $this->assertEquals(500000, $data['summary']['total_collected']);
        $this->assertEquals(2000000, $data['summary']['total_debt']);
    }

    /**
     * Test xuất báo cáo ra file Excel (.CSV có UTF-8 BOM).
     */
    public function test_can_export_financial_report_to_excel_csv(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->get('/api/v1/reports/financial/export-excel?period=2026-06');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertTrue(str_contains(
            $response->headers->get('Content-Disposition') ?: '',
            'Bao_Cao_Tai_Chinh_Cong_No_Ky_2026_06.csv'
        ));

        $content = $response->getContent();
        // Kiểm tra UTF-8 BOM \xEF\xBB\xBF
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('BÁO CÁO TÀI CHÍNH VÀ CÔNG NỢ DỊCH VỤ TÒA NHÀ', $content);
        $this->assertStringContainsString('INV-REP-202606', $content);
        $this->assertStringContainsString('RP-501', $content);
    }

    /**
     * Test xuất báo cáo in ấn / PDF (A4 Landscape Blade View).
     */
    public function test_can_export_financial_report_to_pdf_html(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->get('/api/v1/reports/financial/export-pdf?period=2026-06');

        $response->assertStatus(200);
        $response->assertViewIs('reports.financial_statement');
        $response->assertSee('BÁO CÁO CÔNG NỢ');
        $response->assertSee('INV-REP-202606');
        $response->assertSee('RP-501');
        $response->assertSee('KẾ TOÁN TRƯỞNG');
    }

    /**
     * Test người dùng chưa đăng nhập không thể tải báo cáo.
     */
    public function test_unauthenticated_user_cannot_access_reports(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reports/financial/preview');

        $response->assertStatus(401);
    }
}
