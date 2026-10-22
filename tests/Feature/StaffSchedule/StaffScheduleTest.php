<?php

namespace Tests\Feature\StaffSchedule;

use App\Models\Department;
use App\Models\Role;
use App\Models\ShiftType;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\StaffScheduleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffScheduleTest extends TestCase
{
    use DatabaseTransactions;

    protected ?User $manager = null;

    protected ?string $managerToken = null;

    protected ?User $resident = null;

    protected ?string $residentToken = null;

    protected ?StaffProfile $receptionProfile = null;

    protected ?StaffProfile $securityProfile = null;

    protected ?ShiftType $morningShift = null;

    protected ?ShiftType $afternoonShift = null;

    protected ?ShiftType $overnightShift = null;

    protected StaffScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StaffScheduleService::class);
        $this->service->ensureInitialData();

        // 1. Tạo Manager User
        [$this->manager, $this->managerToken] = $this->createUserWithRole('BUILDING_MANAGER');

        // 2. Tạo Resident User (không có quyền quản lý lịch)
        [$this->resident, $this->residentToken] = $this->createUserWithRole('RESIDENT');

        // 3. Lấy hoặc tạo staff profiles cho Lễ tân và An ninh
        $this->receptionProfile = StaffProfile::whereHas('user.roles', function ($q) {
            $q->where('roles.role_code', 'RECEPTIONIST');
        })->first();

        if (! $this->receptionProfile) {
            [$receptionUser] = $this->createUserWithRole('RECEPTIONIST');
            $dept = Department::where('department_code', 'FRONT_DESK')->first();
            $this->receptionProfile = StaffProfile::create([
                'id' => (string) Str::uuid(),
                'user_id' => $receptionUser->id,
                'employee_code' => 'LT-TEST-'.strtoupper(Str::random(4)),
                'department_id' => $dept->id,
                'job_title' => 'Nhân viên Lễ tân',
                'hire_date' => now()->toDateString(),
            ]);
        }

        $this->securityProfile = StaffProfile::whereHas('user.roles', function ($q) {
            $q->where('roles.role_code', 'SECURITY_GUARD');
        })->first();

        if (! $this->securityProfile) {
            [$securityUser] = $this->createUserWithRole('SECURITY_GUARD');
            $dept = Department::where('department_code', 'SECURITY')->first();
            $this->securityProfile = StaffProfile::create([
                'id' => (string) Str::uuid(),
                'user_id' => $securityUser->id,
                'employee_code' => 'BV-TEST-'.strtoupper(Str::random(4)),
                'department_id' => $dept->id,
                'job_title' => 'Nhân viên Bảo vệ',
                'hire_date' => now()->toDateString(),
            ]);
        }

        // 4. Lấy các loại ca trực
        $this->morningShift = ShiftType::where('shift_code', 'CA_SANG')->first();
        $this->afternoonShift = ShiftType::where('shift_code', 'CA_CHIEU')->first();
        $this->overnightShift = ShiftType::where('shift_code', 'CA_DEM')->first();
    }

    /**
     * Tạo tài khoản người dùng với vai trò cụ thể
     *
     * @return array{0: User, 1: string}
     */
    protected function createUserWithRole(string $roleCode): array
    {
        $role = Role::where('role_code', $roleCode)->first();
        if (! $role) {
            $role = Role::create([
                'id' => (string) Str::uuid(),
                'role_code' => $roleCode,
                'role_name' => 'Role '.$roleCode,
                'is_system' => 1,
            ]);
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => 'test_'.strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'test_'.strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nhân Viên '.$roleCode.' Test',
            'status' => 'ACTIVE',
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(2),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * 1. Quản lý xem lịch tuần thành công
     */
    public function test_manager_can_view_weekly_schedules(): void
    {
        $start = now()->startOfWeek()->toDateString();
        $end = now()->endOfWeek()->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/management/schedules?start_date={$start}&end_date={$end}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data',
                'summary' => [
                    'total_shifts',
                    'reception_shifts',
                    'security_shifts',
                    'scheduled_count',
                    'completed_count',
                    'cancelled_count',
                ],
            ]);
    }

    /**
     * 2. Quản lý xem lịch tháng thành công
     */
    public function test_manager_can_view_monthly_schedules(): void
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/management/schedules?start_date={$start}&end_date={$end}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 3. Tạo ca trực hợp lệ cho Lễ tân
     */
    public function test_manager_can_create_valid_schedule_for_receptionist(): void
    {
        $targetDate = now()->addDays(rand(100, 200))->toDateString();

        $payload = [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'assigned_location' => 'Sảnh Lễ Tân Tháp A',
            'notes' => 'Trực bàn lễ tân ca sáng',
            'status' => 'SCHEDULED',
        ];

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'staff_profile_id' => $this->receptionProfile->id,
                    'shift_type_id' => $this->morningShift->id,
                    'assigned_location' => 'Sảnh Lễ Tân Tháp A',
                    'status' => 'SCHEDULED',
                ],
            ]);

        $this->assertDatabaseHas('staff_schedules', [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'status' => 'SCHEDULED',
        ]);
    }

    /**
     * 4. Tạo ca trực hợp lệ cho Bảo vệ
     */
    public function test_manager_can_create_valid_schedule_for_security_guard(): void
    {
        $targetDate = now()->addDays(rand(201, 300))->toDateString();

        $payload = [
            'staff_profile_id' => $this->securityProfile->id,
            'shift_type_id' => $this->afternoonShift->id,
            'work_date' => $targetDate,
            'assigned_location' => 'Bốt An Ninh Cổng 1',
            'status' => 'SCHEDULED',
        ];

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', $payload);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('staff_schedules', [
            'staff_profile_id' => $this->securityProfile->id,
            'work_date' => $targetDate,
        ]);
    }

    /**
     * 5. Sửa ca trực hợp lệ
     */
    public function test_manager_can_update_schedule(): void
    {
        $targetDate = now()->addDays(rand(301, 400))->toDateString();

        $schedule = StaffSchedule::create([
            'id' => (string) Str::uuid(),
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'assigned_location' => 'Vị trí cũ',
            'status' => 'SCHEDULED',
            'created_by' => $this->manager->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/management/schedules/{$schedule->id}", [
            'assigned_location' => 'Vị trí mới tại Tháp B',
            'notes' => 'Ghi chú sau cập nhật',
            'status' => 'SCHEDULED',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'assigned_location' => 'Vị trí mới tại Tháp B',
                ],
            ]);

        $this->assertDatabaseHas('staff_schedules', [
            'id' => $schedule->id,
            'assigned_location' => 'Vị trí mới tại Tháp B',
        ]);
    }

    /**
     * 6. Xóa hoặc hủy ca trực
     */
    public function test_manager_can_delete_or_cancel_schedule(): void
    {
        $targetDate = now()->addDays(rand(401, 500))->toDateString();

        $schedule = StaffSchedule::create([
            'id' => (string) Str::uuid(),
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'status' => 'SCHEDULED',
        ]);

        // Thử xóa
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->deleteJson("/api/v1/management/schedules/{$schedule->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('staff_schedules', [
            'id' => $schedule->id,
        ]);
    }

    /**
     * 7. Người dùng không có quyền quản lý (Cư dân) bị từ chối khi tạo ca trực (403 Forbidden)
     */
    public function test_resident_cannot_create_schedule(): void
    {
        $targetDate = now()->addDays(rand(501, 600))->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 8. Yêu cầu chưa xác thực bị từ chối (401 Unauthorized)
     */
    public function test_unauthenticated_user_rejected(): void
    {
        $response = $this->getJson('/api/v1/management/schedules');
        $response->assertStatus(401);
    }

    /**
     * 9. Kiểm tra trùng ca: Không cho phép cùng 1 nhân viên bị xếp 2 ca chồng lấn (trả về 409 Conflict)
     */
    public function test_cannot_schedule_same_staff_two_shifts_on_same_date_returns_409(): void
    {
        $targetDate = now()->addDays(rand(601, 700))->toDateString();

        // Tạo ca đầu tiên thành công
        StaffSchedule::create([
            'id' => (string) Str::uuid(),
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'status' => 'SCHEDULED',
        ]);

        // Tạo ca thứ hai cho chính nhân viên này trong cùng ngày -> phải bị chặn 409 Conflict
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->afternoonShift->id,
            'work_date' => $targetDate,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('Trùng lịch trực', $response->json('message'));
    }

    /**
     * 10. Cho phép 2 nhân viên khác nhau làm cùng một ca và cùng ngày
     */
    public function test_different_staff_can_work_same_shift_concurrently(): void
    {
        $targetDate = now()->addDays(rand(701, 800))->toDateString();

        // Nhân viên 1 (Lễ tân) trực ca sáng
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'assigned_location' => 'Bàn trực 1',
        ]);
        $res1->assertStatus(201);

        // Nhân viên 2 (Bảo vệ) cũng trực ca sáng (hoặc cùng khung giờ)
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->securityProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
            'assigned_location' => 'Cổng chính',
        ]);
        $res2->assertStatus(201);
    }

    /**
     * 11. Kiểm tra phát hiện xung đột ca trực qua đêm (Overnight Shift Conflict)
     */
    public function test_overnight_shift_conflict_detected_across_midnight(): void
    {
        $baseDay = rand(801, 890);
        $day1 = now()->addDays($baseDay)->toDateString();
        $day2 = now()->addDays($baseDay + 1)->toDateString();

        // Nhân viên trực ca đêm ngày 1 (22:00 ngày 1 -> 06:00 sáng ngày 2)
        StaffSchedule::create([
            'id' => (string) Str::uuid(),
            'staff_profile_id' => $this->securityProfile->id,
            'shift_type_id' => $this->overnightShift->id,
            'work_date' => $day1,
            'status' => 'SCHEDULED',
        ]);

        // Giả sử có một ca đặc biệt vào sáng ngày 2 bắt đầu lúc 05:00 sáng (trước 06:00 khi ca đêm kết thúc)
        $earlyMorningShift = ShiftType::firstOrCreate(
            ['shift_code' => 'CA_SOM_TEST'],
            [
                'shift_name' => 'Ca Sớm Đặc Biệt (05:00 - 09:00)',
                'start_time' => '05:00:00',
                'end_time' => '09:00:00',
                'break_minutes' => 30,
                'is_overnight' => 0,
            ]
        );

        // Cố tình xếp nhân viên này vào ca sớm bắt đầu lúc 05:00 ngày 2 -> Xung đột với ca đêm kết thúc lúc 06:00
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->securityProfile->id,
            'shift_type_id' => $earlyMorningShift->id,
            'work_date' => $day2,
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('Xung đột ca trực', $response->json('message'));
    }

    /**
     * 12. Kiểm tra thao tác ghi nhận sự kiện realtime vào realtime_sync_events
     */
    public function test_creating_schedule_records_realtime_sync_event(): void
    {
        $targetDate = now()->addDays(rand(901, 999))->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/management/schedules', [
            'staff_profile_id' => $this->receptionProfile->id,
            'shift_type_id' => $this->morningShift->id,
            'work_date' => $targetDate,
        ]);

        $response->assertStatus(201);
        $scheduleId = $response->json('data.id');

        // Kiểm tra event store bảng realtime_sync_events
        $this->assertDatabaseHas('realtime_sync_events', [
            'module' => 'schedules',
            'entity' => 'schedule',
            'action' => 'CREATED',
            'entity_id' => $scheduleId,
        ]);
    }

    /**
     * 13. Endpoints danh mục loại ca và danh sách nhân viên trả về dữ liệu chuẩn
     */
    public function test_shift_types_and_staff_candidates_endpoints_work(): void
    {
        $resShifts = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/management/schedules/shift-types');

        $resShifts->assertStatus(200)
            ->assertJson(['success' => true]);

        $resStaff = $this->withHeaders([
            'Authorization' => "Bearer {$this->managerToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/management/schedules/staff-candidates?role=RECEPTIONIST');

        $resStaff->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
