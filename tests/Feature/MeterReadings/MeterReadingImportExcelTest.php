<?php

namespace Tests\Feature\MeterReadings;

use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\MeterReadingBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeterReadingImportExcelTest extends TestCase
{
    protected string $adminToken = 'smart_token_admin_test_import';

    protected function setUp(): void
    {
        parent::setUp();

        $sqlitePath = database_path('database.sqlite');
        if (file_exists($sqlitePath)) {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $sqlitePath,
            ]);
            DB::purge();
            DB::reconnect();
        }

        $adminUser = User::where('username', 'admin')->first();
        if ($adminUser) {
            $this->adminToken = 'smart_token_'.$adminUser->id.'_import';
        }
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Test 01: Tải tệp mẫu template CSV
     */
    public function test_01_can_download_meter_reading_template_csv(): void
    {
        $cycle = Carbon::now()->format('Y-m');
        $response = $this->withHeaders($this->authHeaders())->get("/api/v1/meter-readings/template?cycle={$cycle}");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        $this->assertStringContainsString('Mã Đồng Hồ', $content);
        $this->assertStringContainsString('Chỉ Số Kỳ Này (*)', $content);
    }

    /**
     * Test 02: Import file CSV hợp lệ thành công
     */
    public function test_02_can_import_valid_meter_readings_csv(): void
    {
        $meter1 = Meter::first();
        $this->assertNotNull($meter1);

        $cycle = '2027-05';
        MeterReading::where('billing_cycle', $cycle)->delete();

        $prev = (float) $meter1->current_reading;
        $newVal = $prev + 45.5;

        $csvContent = "\xEF\xBB\xBF".
            "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số (YYYY-MM-DD),Thay Đồng Hồ Mới (1/0),Ghi Chú\n".
            "1,{$meter1->meter_code},A101,Block A,Tầng 1,{$meter1->meter_type},{$prev},{$newVal},2027-05-25,0,Chốt định kỳ tháng 5\n";

        $file = UploadedFile::fake()->createWithContent('chot_chi_so_t5.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'billing_month_year' => $cycle,
                    'total_records' => 1,
                    'success_records' => 1,
                    'failed_records' => 0,
                    'import_status' => 'COMPLETED',
                ],
            ]);

        // Kiểm tra bản ghi reading đã được tạo
        $this->assertDatabaseHas('meter_readings', [
            'meter_id' => $meter1->id,
            'billing_cycle' => $cycle,
            'current_reading' => $newVal,
            'consumed_units' => 45.5,
            'reading_source' => 'EXCEL_IMPORT',
        ]);
    }

    /**
     * Test 03: Validate lỗi khi file có mã đồng hồ không tồn tại
     */
    public function test_03_detects_error_for_non_existent_meter(): void
    {
        $cycle = '2027-06';
        $invalidCode = 'MTR-NON-EXISTENT-XYZ999';

        $csvContent = "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số,Thay Đồng Hồ,Ghi Chú\n".
            "1,{$invalidCode},A999,Block X,Tầng 9,ELECTRICITY,100,150,2027-06-25,0,Test\n";

        $file = UploadedFile::fake()->createWithContent('test_invalid.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_records' => 1,
                    'success_records' => 0,
                    'failed_records' => 1,
                    'import_status' => 'FAILED',
                ],
            ]);

        $errors = $response->json('data.error_summary_json');
        $this->assertNotEmpty($errors);
        $this->assertEquals($invalidCode, $errors[0]['meter_code']);
    }

    /**
     * Test 04: Validate lỗi khi chỉ số mới nhỏ hơn chỉ số cũ mà không bật cờ thay đồng hồ
     */
    public function test_04_detects_error_when_current_is_less_than_previous(): void
    {
        $meter = Meter::first();
        $this->assertNotNull($meter);

        $cycle = '2027-07';
        $prev = 500.0;
        $invalidCurr = 400.0; // Nhỏ hơn

        $csvContent = "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số,Thay Đồng Hồ Mới (1/0),Ghi Chú\n".
            "1,{$meter->meter_code},A101,Block A,Tầng 1,ELECTRICITY,{$prev},{$invalidCurr},2027-07-25,0,Chỉ số bị lùi\n";

        $file = UploadedFile::fake()->createWithContent('invalid_curr.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(200);
        $failedCount = $response->json('data.failed_records');
        $this->assertGreaterThanOrEqual(1, $failedCount);

        $errors = $response->json('data.error_summary_json');
        $this->assertStringContainsString('nhỏ hơn chỉ số cũ', $errors[0]['error']);
    }

    /**
     * Test 05: Chấp nhận thay đồng hồ khi có cờ Thay Đồng Hồ Mới = 1
     */
    public function test_05_accepts_reset_when_meter_replaced_flag_is_active(): void
    {
        $meter = Meter::first();
        $cycle = '2027-08';
        MeterReading::where('meter_id', $meter->id)->where('billing_cycle', $cycle)->delete();

        $prev = 2000.0;
        $resetCurr = 30.0;

        $csvContent = "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số,Thay Đồng Hồ Mới (1/0),Ghi Chú\n".
            "1,{$meter->meter_code},A101,Block A,Tầng 1,ELECTRICITY,{$prev},{$resetCurr},2027-08-25,1,Thay công tơ mới\n";

        $file = UploadedFile::fake()->createWithContent('reset_meter.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'success_records' => 1,
                    'failed_records' => 0,
                ],
            ]);

        $this->assertDatabaseHas('meter_readings', [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'consumed_units' => 30.0,
        ]);
    }

    /**
     * Test 06: Tự động phát hiện bất thường khi sản lượng trong file tăng vọt
     */
    public function test_06_flags_abnormal_consumption_on_import(): void
    {
        $meter = Meter::where('meter_type', 'ELECTRICITY')->first();
        $this->assertNotNull($meter);

        $cycle = '2027-09';
        MeterReading::where('meter_id', $meter->id)->where('billing_cycle', $cycle)->delete();

        $prev = (float) $meter->current_reading;
        $hugeCurrent = $prev + 900.0; // Tiêu thụ 900 kWh >= 600 kWh

        $csvContent = "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số,Thay Đồng Hồ Mới (1/0),Ghi Chú\n".
            "1,{$meter->meter_code},A101,Block A,Tầng 1,ELECTRICITY,{$prev},{$hugeCurrent},2027-09-25,0,Tiêu thụ đột biến\n";

        $file = UploadedFile::fake()->createWithContent('abnormal.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('meter_readings', [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'is_abnormal_consumption' => 1,
        ]);
    }

    /**
     * Test 07: Không thể import vào kỳ đã bị khóa sổ
     */
    public function test_07_prevents_import_into_locked_billing_cycle(): void
    {
        $meter = Meter::first();
        $cycle = '2027-10';

        // Tạo 1 bản ghi đã bị khóa
        MeterReading::updateOrCreate(
            ['meter_id' => $meter->id, 'billing_cycle' => $cycle],
            [
                'apartment_id' => $meter->apartment_id,
                'period_start_date' => '2027-10-01',
                'period_end_date' => '2027-10-31',
                'previous_reading' => 100,
                'current_reading' => 150,
                'consumed_units' => 50,
                'reading_source' => 'MANUAL',
                'is_locked_for_billing' => true, // ĐÃ KHÓA
            ]
        );

        $csvContent = "STT,Mã Đồng Hồ,Số Căn Hộ,Khối Tòa Nhà,Tầng,Loại Dịch Vụ,Chỉ Số Kỳ Trước,Chỉ Số Kỳ Này (*),Ngày Ghi Số,Thay Đồng Hồ,Ghi Chú\n".
            "1,{$meter->meter_code},A101,Block A,Tầng 1,ELECTRICITY,150,200,2027-10-25,0,Import vao ky da khoa\n";

        $file = UploadedFile::fake()->createWithContent('locked_import.csv', $csvContent);

        $response = $this->withHeaders($this->authHeaders())->post('/api/v1/meter-readings/import', [
            'file' => $file,
            'billing_cycle' => $cycle,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test 08: Lấy danh sách lịch sử các đợt import (Batches)
     */
    public function test_08_can_list_meter_reading_batches(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/meter-reading-batches?per_page=5');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    /**
     * Test 09: Xem chi tiết một đợt import
     */
    public function test_09_can_get_batch_detail(): void
    {
        $batch = MeterReadingBatch::latest()->first();
        $this->assertNotNull($batch, 'Cần có ít nhất 1 batch từ các test trước');

        $response = $this->withHeaders($this->authHeaders())->getJson("/api/v1/meter-reading-batches/{$batch->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $batch->id,
                    'batch_code' => $batch->batch_code,
                ],
            ]);
    }
}
