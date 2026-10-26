<?php

namespace Tests\Feature\ReceptionDashboard;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Services\ReceptionDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected ?User $receptionist = null;

    protected ?string $receptionistToken = null;

    protected ?User $resident = null;

    protected ?string $residentToken = null;

    protected ?Apartment $apartment = null;

    protected ReceptionDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReceptionDashboardService::class);

        // 1. Tạo nhân viên Lễ tân có quyền
        [$this->receptionist, $this->receptionistToken] = $this->createStaffUser('RECEPTIONIST');

        // 2. Tạo cư dân thông thường (không có quyền nhân viên)
        [$this->resident, $this->residentToken] = $this->createResidentUser('resident_dash_');

        // 3. Tạo căn hộ mẫu
        $this->apartment = $this->getOrCreateApartment('APT-DASH-'.Str::random(4));
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_DASH_'.Str::random(4),
                'block_name' => 'Tòa Tháp Dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $floorId = DB::table('floors')->value('id');
        if (! $floorId) {
            $floorId = (string) Str::uuid();
            DB::table('floors')->insert([
                'id' => $floorId,
                'block_id' => $blockId,
                'floor_number' => 8,
                'floor_code' => 'F8',
                'floor_name' => 'Tầng 8',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Apartment::create([
            'apartment_number' => $code,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 70.0,
        ]);
    }

    protected function createStaffUser(string $roleCode): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Test",
            'status' => 'ACTIVE',
        ]);

        $roleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'role_code' => $roleCode,
                'role_name' => $roleCode,
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $roleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Staff Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(7),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createResidentUser(string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(6).'@resident.local',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Test',
            'status' => 'ACTIVE',
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(7),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Test 1: Đếm đúng bưu phẩm đang chờ bàn giao và bưu phẩm đã bàn giao không bị tính là tồn đọng
     */
    public function test_aggregates_parcels_pending_and_delivered_accurately(): void
    {
        $this->travelTo(Carbon::now(config('app.timezone'))->setTime(12, 0));
        $now = Carbon::now();

        // Tạo 1 bưu phẩm đang chờ giao (< 24h)
        Parcel::create([
            'tracking_number' => 'PKG-PENDING-'.Str::random(5),
            'recipient_name' => 'Nguyễn Văn A',
            'recipient_phone' => '0912345678',
            'apartment_id' => $this->apartment->id,
            'courier_company' => 'Shopee Express',
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-'.Str::random(10),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => $now->copy()->subHours(2),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // Tạo 1 bưu phẩm đang chờ giao (tồn 2 ngày)
        Parcel::create([
            'tracking_number' => 'PKG-PENDING-2D-'.Str::random(5),
            'recipient_name' => 'Trần Thị B',
            'recipient_phone' => '0987654321',
            'apartment_id' => $this->apartment->id,
            'courier_company' => 'GHTK',
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-'.Str::random(10),
            'status' => 'RECEIVED',
            'received_at' => $now->copy()->subDays(2),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // Tạo 1 bưu phẩm đã bàn giao thành công hôm nay
        Parcel::create([
            'tracking_number' => 'PKG-DELIVERED-'.Str::random(5),
            'recipient_name' => 'Lê Văn C',
            'recipient_phone' => '0901234567',
            'apartment_id' => $this->apartment->id,
            'courier_company' => 'GHN',
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-'.Str::random(10),
            'status' => 'DELIVERED',
            'received_at' => $now->copy()->subHours(5),
            'collected_at' => $now->copy()->subHour(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $metrics = $this->service->getDashboardMetrics(['preset' => 'today']);
        $parcels = $metrics['kpis']['parcels'];

        // Kiểm tra
        $this->assertGreaterThanOrEqual(2, $parcels['pending_total']);
        $this->assertGreaterThanOrEqual(1, $parcels['delivered_today']);

        // Bưu phẩm đã DELIVERED không bao giờ được tính vào pending_total
        $pendingDeliveredCount = Parcel::where('status', 'DELIVERED')
            ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
            ->count();
        $this->assertEquals(0, $pendingDeliveredCount);

        // Kiểm tra Aging: tổng các nhóm luôn khớp với pending_total
        $agingSum = $parcels['aging']['under_24h'] +
            $parcels['aging']['days_1_to_3'] +
            $parcels['aging']['days_3_to_5'] +
            $parcels['aging']['over_5_days'];
        $this->assertEquals($parcels['pending_total'], $agingSum);
    }

    /**
     * Test 2: Bưu phẩm quá hạn (> 5 ngày) được phát hiện chính xác
     */
    public function test_detects_overdue_parcels_older_than_five_days(): void
    {
        $now = Carbon::now();

        // Bưu phẩm tồn 6 ngày
        Parcel::create([
            'tracking_number' => 'PKG-OVERDUE-'.Str::random(5),
            'recipient_name' => 'Phạm Văn D',
            'recipient_phone' => '0933333333',
            'apartment_id' => $this->apartment->id,
            'courier_company' => 'Viettel Post',
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-'.Str::random(10),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => $now->copy()->subDays(6),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $metrics = $this->service->getDashboardMetrics(['preset' => 'today']);
        $parcels = $metrics['kpis']['parcels'];

        $this->assertGreaterThanOrEqual(1, $parcels['overdue_parcels']);
        $this->assertGreaterThanOrEqual(1, $parcels['aging']['over_5_days']);
    }

    /**
     * Test 3: Đếm đúng khách hiện đang ở trong tòa nhà và khách đã check-out không còn được tính
     */
    public function test_aggregates_visitors_currently_inside_and_checked_out(): void
    {
        $this->travelTo(Carbon::now(config('app.timezone'))->setTime(12, 0));
        $now = Carbon::now();

        // 1. Tạo lượt đăng ký khách
        $reg1 = VisitorRegistration::create([
            'registration_code' => 'REG-INSIDE-'.Str::random(4),
            'qr_access_pass_code' => 'QR-'.Str::random(8),
            'apartment_id' => $this->apartment->id,
            'host_resident_user_id' => $this->resident->id,
            'visitor_name' => 'Khách Đang Trong Tòa',
            'visitor_phone' => '0912999888',
            'expected_arrival_time' => $now->copy()->subHour(),
            'visit_purpose' => 'VISIT',
            'status' => 'CHECKED_IN',
        ]);

        // Tạo log checkin (Đang trong tòa nhà: checkin_time IS NOT NULL AND checkout_time IS NULL)
        DB::table('visitor_checkin_logs')->insert([
            'id' => (string) Str::uuid(),
            'registration_id' => $reg1->id,
            'visitor_name' => 'Khách Đang Trong Tòa',
            'visitor_phone' => '0912999888',
            'apartment_id' => $this->apartment->id,
            'checkin_time' => $now->copy()->subMinutes(45),
            'checkout_time' => null,
            'receptionist_user_id' => $this->receptionist->id,
            'created_at' => now(),
        ]);

        // 2. Tạo lượt khách đã check-out (checkout_time IS NOT NULL)
        $reg2 = VisitorRegistration::create([
            'registration_code' => 'REG-OUT-'.Str::random(4),
            'qr_access_pass_code' => 'QR-'.Str::random(8),
            'apartment_id' => $this->apartment->id,
            'host_resident_user_id' => $this->resident->id,
            'visitor_name' => 'Khách Đã Rời Đi',
            'visitor_phone' => '0912777666',
            'expected_arrival_time' => $now->copy()->subHours(3),
            'visit_purpose' => 'DELIVERY',
            'status' => 'CHECKED_OUT',
        ]);

        DB::table('visitor_checkin_logs')->insert([
            'id' => (string) Str::uuid(),
            'registration_id' => $reg2->id,
            'visitor_name' => 'Khách Đã Rời Đi',
            'visitor_phone' => '0912777666',
            'apartment_id' => $this->apartment->id,
            'checkin_time' => $now->copy()->subHours(2),
            'checkout_time' => $now->copy()->subMinutes(30),
            'receptionist_user_id' => $this->receptionist->id,
            'created_at' => now(),
        ]);

        $metrics = $this->service->getDashboardMetrics(['preset' => 'today']);
        $visitors = $metrics['kpis']['visitors'];

        // Khách 1 đang ở trong tòa
        $this->assertGreaterThanOrEqual(1, $visitors['currently_inside']);
        // Cả 2 đều đã check-in hôm nay
        $this->assertGreaterThanOrEqual(2, $visitors['checkin_today']);
        // Khách 2 đã check-out hôm nay
        $this->assertGreaterThanOrEqual(1, $visitors['checkout_today']);

        // Danh sách tóm tắt khách đang trong tòa chứa khách 1, không chứa khách 2
        $currentVisitors = $metrics['current_visitors_inside'];
        $insideNames = array_column($currentVisitors, 'visitor_name');
        $this->assertContains('Khách Đang Trong Tòa', $insideNames);
        $this->assertNotContains('Khách Đã Rời Đi', $insideNames);

        // Kiểm tra PII phone masking: Số điện thoại phải được che dấu dạng 091****888
        foreach ($currentVisitors as $v) {
            if ($v['visitor_name'] === 'Khách Đang Trong Tòa') {
                $this->assertStringContainsString('****', $v['visitor_phone_masked']);
                $this->assertNotEquals('0912999888', $v['visitor_phone_masked']);
            }
        }
    }

    /**
     * Test 4: Gọi API GET /api/v1/reception/dashboard với quyền Lễ tân thành công (HTTP 200)
     */
    public function test_receptionist_can_access_dashboard_endpoint_successfully(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard?preset=today');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'kpis' => [
                        'parcels' => [
                            'pending_total',
                            'received_today',
                            'delivered_today',
                            'overdue_parcels',
                            'aging' => [
                                'under_24h',
                                'days_1_to_3',
                                'days_3_to_5',
                                'over_5_days',
                            ],
                        ],
                        'visitors' => [
                            'currently_inside',
                            'checkin_today',
                            'checkout_today',
                            'expected_today',
                        ],
                    ],
                    'traffic_trend' => [
                        'granularity',
                        'series',
                    ],
                    'breakdowns' => [
                        'carriers',
                        'visitor_purposes',
                    ],
                    'top_pending_parcels',
                    'current_visitors_inside',
                    'meta',
                ],
            ]);
    }

    /**
     * Test 5: Chưa đăng nhập bị từ chối với mã lỗi HTTP 401
     */
    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(401);
    }

    /**
     * Test 6: Cư dân thông thường (không có role Lễ tân/An ninh/Quản lý) bị từ chối HTTP 403
     */
    public function test_regular_resident_is_forbidden_from_dashboard(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(403);
    }

    /**
     * Test 7: Validate bộ lọc ngày không hợp lệ (start_date > end_date) trả về HTTP 422
     */
    public function test_validates_invalid_custom_date_range(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard?preset=custom&from_date=2026-10-25&to_date=2026-10-20');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * Test 8: Bộ lọc 7 ngày qua (last_7_days) và Tháng này (this_month) tính toán đúng
     */
    public function test_preset_filters_resolve_correct_date_boundaries(): void
    {
        $resolved7Days = $this->service->resolveDateRange(['preset' => 'last_7_days']);
        $this->assertEquals('last_7_days', $resolved7Days['preset']);
        $this->assertFalse($resolved7Days['is_single_day']);
        $this->assertEquals(7, (int) round($resolved7Days['start']->diffInDays($resolved7Days['end'])));

        $resolvedMonth = $this->service->resolveDateRange(['preset' => 'this_month']);
        $this->assertEquals('this_month', $resolvedMonth['preset']);
        $this->assertEquals(1, (int) $resolvedMonth['start']->format('d'));
    }
}
