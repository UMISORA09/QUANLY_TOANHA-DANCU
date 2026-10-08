<?php

namespace Tests\Feature\MeterReadings;

use App\Models\Apartment;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeterReadingManagementTest extends TestCase
{
    protected string $adminToken = 'smart_token_admin_test_meter';

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
            $this->adminToken = 'smart_token_'.$adminUser->id.'_meter';
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
     * Test 01: Lấy KPI tổng quan tiến độ chốt số theo kỳ
     */
    public function test_01_can_get_meter_reading_summary(): void
    {
        $cycle = Carbon::now()->format('Y-m');
        $response = $this->withHeaders($this->authHeaders())->getJson("/api/v1/meter-readings/summary?cycle={$cycle}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'billing_cycle',
                    'total_meters',
                    'recorded_meters',
                    'pending_meters',
                    'completion_rate',
                    'abnormal_count',
                    'total_electricity_kwh',
                    'total_water_m3',
                    'is_cycle_locked',
                ],
            ]);
    }

    /**
     * Test 02: Lấy danh sách đồng hồ và phân trang
     */
    public function test_02_can_list_meters(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/meters?per_page=10');

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
     * Test 03: Khai báo đồng hồ mới cho căn hộ
     */
    public function test_03_can_create_new_meter_for_apartment(): void
    {
        $apartment = Apartment::first();
        $this->assertNotNull($apartment, 'Cần có ít nhất 1 căn hộ trong CSDL');

        $testCode = 'TEST-MTR-'.strtoupper(Str::random(6));

        $payload = [
            'apartment_id' => $apartment->id,
            'meter_type' => 'WATER',
            'meter_code' => $testCode,
            'installation_date' => '2026-01-01',
            'initial_reading' => 10.0,
            'multiplier_factor' => 1.0,
            'notes' => 'Đồng hồ nước kiểm thử',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meters', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'meter_code' => $testCode,
                    'meter_type' => 'WATER',
                    'apartment_id' => $apartment->id,
                    'initial_reading' => 10.0,
                ],
            ]);

        $this->assertDatabaseHas('meters', [
            'meter_code' => $testCode,
            'meter_type' => 'WATER',
        ]);
    }

    /**
     * Test 04: Chốt số thủ công tính toán tiêu thụ chính xác
     */
    public function test_04_can_record_manual_reading_accurately(): void
    {
        $apartment = Apartment::first();
        $meter = Meter::where('apartment_id', $apartment->id)->first();
        if (! $meter) {
            $meter = Meter::create([
                'apartment_id' => $apartment->id,
                'meter_type' => 'ELECTRICITY',
                'meter_code' => 'MTR-TEST-'.Str::random(5),
                'initial_reading' => 100.0,
                'current_reading' => 100.0,
                'installation_date' => '2026-01-01',
                'multiplier_factor' => 1.0,
                'is_active' => true,
            ]);
        }

        $cycle = '2026-10';
        $prevReading = (float) $meter->current_reading;
        $newReading = $prevReading + 150.5;

        $payload = [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'previous_reading' => $prevReading,
            'current_reading' => $newReading,
            'period_start_date' => '2026-10-01',
            'period_end_date' => '2026-10-31',
            'notes' => 'Chốt số tháng 10/2026',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'meter_id' => $meter->id,
                    'billing_cycle' => $cycle,
                    'previous_reading' => $prevReading,
                    'current_reading' => $newReading,
                    'consumed_units' => 150.5,
                ],
            ]);

        // Kiểm tra meter đã được update current_reading
        $freshMeter = $meter->fresh();
        $this->assertEquals($newReading, (float) $freshMeter->current_reading);
    }

    /**
     * Test 05: Validate lỗi khi chỉ số mới nhỏ hơn chỉ số cũ (không có force_reset)
     */
    public function test_05_fails_when_current_reading_is_less_than_previous(): void
    {
        $meter = Meter::first();
        $this->assertNotNull($meter);

        $cycle = '2026-11';
        $prevReading = 200.0;
        $invalidReading = 150.0; // Nhỏ hơn cũ

        $payload = [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'previous_reading' => $prevReading,
            'current_reading' => $invalidReading,
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test 06: Cho phép force_reset khi thay đồng hồ mới
     */
    public function test_06_can_force_reset_reading_when_meter_replaced(): void
    {
        $meter = Meter::first();
        $this->assertNotNull($meter);

        $cycle = '2026-12';
        $prevReading = 2000.0;
        $resetReading = 25.0; // Đồng hồ mới lắp lại từ 0

        $payload = [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'previous_reading' => $prevReading,
            'current_reading' => $resetReading,
            'force_reset' => true,
            'notes' => 'Thay mới đồng hồ công tơ',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'consumed_units' => 25.0,
                ],
            ]);
    }

    /**
     * Test 07: Tự động cảnh báo bất thường khi lượng tiêu thụ tăng vọt
     */
    public function test_07_detects_abnormal_consumption(): void
    {
        $meter = Meter::where('meter_type', 'ELECTRICITY')->first();
        $this->assertNotNull($meter);

        $cycle = '2027-01';
        $prevReading = (float) $meter->current_reading;
        $abnormalReading = $prevReading + 850.0; // Tiêu thụ 850 kWh >= ngưỡng 600 kWh

        $payload = [
            'meter_id' => $meter->id,
            'billing_cycle' => $cycle,
            'previous_reading' => $prevReading,
            'current_reading' => $abnormalReading,
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_abnormal_consumption' => true,
                ],
            ]);

        $this->assertNotEmpty($response->json('data.abnormal_reason'));
    }

    /**
     * Test 08: Sửa đổi bản ghi chỉ số đo
     */
    public function test_08_can_update_reading(): void
    {
        $reading = MeterReading::first();
        $this->assertNotNull($reading);

        $updatedCurrent = (float) $reading->previous_reading + 55.0;

        $payload = [
            'current_reading' => $updatedCurrent,
            'previous_reading' => (float) $reading->previous_reading,
            'abnormal_reason' => 'Đã hiệu chỉnh lại theo ảnh chụp',
        ];

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/meter-readings/{$reading->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $reading->id,
                    'consumed_units' => 55.0,
                ],
            ]);
    }

    /**
     * Test 09: Khóa sổ kỳ chốt số và ngăn chặn chỉnh sửa khi đã khóa
     */
    public function test_09_can_lock_cycle_and_prevent_modification(): void
    {
        $reading = MeterReading::first();
        $this->assertNotNull($reading);
        $cycle = $reading->billing_cycle;

        // Khóa sổ
        $lockRes = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings/lock-cycle', [
            'billing_cycle' => $cycle,
        ]);

        $lockRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Cố tình sửa reading đã bị khóa
        $editRes = $this->withHeaders($this->authHeaders())->putJson("/api/v1/meter-readings/{$reading->id}", [
            'current_reading' => (float) $reading->current_reading + 10,
        ]);

        $editRes->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        // Cố tình xóa reading đã bị khóa
        $delRes = $this->withHeaders($this->authHeaders())->deleteJson("/api/v1/meter-readings/{$reading->id}");
        $delRes->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        // Mở khóa sổ lại
        $unlockRes = $this->withHeaders($this->authHeaders())->postJson('/api/v1/meter-readings/unlock-cycle', [
            'billing_cycle' => $cycle,
        ]);

        $unlockRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Test 10: Xóa bản ghi chỉ số khi chưa bị khóa sổ
     */
    public function test_10_can_delete_reading_when_not_locked(): void
    {
        $meter = Meter::first();
        $randomCycle = '2099-'.str_pad((string) rand(1, 12), 2, '0', STR_PAD_LEFT);
        MeterReading::where('meter_id', $meter->id)->where('billing_cycle', $randomCycle)->delete();

        $reading = MeterReading::create([
            'meter_id' => $meter->id,
            'apartment_id' => $meter->apartment_id,
            'billing_cycle' => $randomCycle,
            'period_start_date' => '2099-01-01',
            'period_end_date' => '2099-01-31',
            'previous_reading' => 100,
            'current_reading' => 120,
            'consumed_units' => 20,
            'reading_source' => 'MANUAL',
            'is_locked_for_billing' => false,
        ]);

        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/v1/meter-readings/{$reading->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseMissing('meter_readings', [
            'id' => $reading->id,
        ]);
    }
}
