<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_tr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_tr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Temp Reg User',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Temp Reg Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo người dùng bình thường (không có quyền Admin)
     */
    protected function createNormalUser(): array
    {
        $user = User::create([
            'username' => 'norm_tr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'norm_tr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Normal Temp Reg Member',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'RESIDENT_MEMBER')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Normal Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test độc lập
     */
    protected function createApartment(string $code = 'TR'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.rand(1000, 9999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Tạo cư dân test cho căn hộ
     */
    protected function createResident(Apartment $apt, string $name = 'Nguyen Van Cu Dan'): Resident
    {
        $user = User::create([
            'username' => 'u_tr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $name,
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);

        return Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);
    }

    /**
     * 1. Test tạo hồ sơ Tạm trú (TEMPORARY_STAY)
     */
    public function test_01_create_temporary_stay_successfully(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC01');
        $resident = $this->createResident($apt, 'Nguyen Van Tam Tru');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú làm việc tại công ty công nghệ',
            'identity_card_front_url' => 'https://example.com/cccd_front.jpg',
            'identity_card_back_url' => 'https://example.com/cccd_back.jpg',
            'notes' => 'Hồ sơ đầy đủ',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Tạo hồ sơ đăng ký tạm trú / tạm vắng thành công.',
            ])
            ->assertJsonPath('data.registration_type', 'TEMPORARY_STAY')
            ->assertJsonPath('data.police_status', 'PENDING_POLICE_SUBMISSION')
            ->assertJsonPath('data.resident_id', $resident->id)
            ->assertJsonPath('data.apartment_id', $apt->id);

        $this->assertDatabaseHas('temporary_registrations', [
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);
    }

    /**
     * 2. Test tạo hồ sơ Tạm vắng (TEMPORARY_ABSENCE)
     */
    public function test_02_create_temporary_absence_successfully(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC02');
        $resident = $this->createResident($apt, 'Tran Van Tam Vang');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-15',
            'reason' => 'Về quê thăm gia đình và nghỉ phép',
            'notes' => 'Báo trước 1 tuần',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('data.registration_type', 'TEMPORARY_ABSENCE')
            ->assertJsonPath('data.police_status', 'PENDING_POLICE_SUBMISSION');

        $this->assertDatabaseHas('temporary_registrations', [
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
        ]);
    }

    /**
     * 3. Test Invalid Resident
     */
    public function test_03_invalid_resident_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC03');
        $fakeResidentId = (string) Str::uuid();

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $fakeResidentId,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['resident_id']);
    }

    /**
     * 4. Test Invalid Apartment
     */
    public function test_04_invalid_apartment_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC04');
        $resident = $this->createResident($apt, 'Nguyen Van TC04');
        $fakeApartmentId = (string) Str::uuid();

        $payload = [
            'apartment_id' => $fakeApartmentId,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['apartment_id']);
    }

    /**
     * 5. Test Invalid Registration Type
     */
    public function test_05_invalid_registration_type_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC05');
        $resident = $this->createResident($apt, 'Nguyen Van TC05');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'INVALID_TYPE',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['registration_type']);
    }

    /**
     * 6. Test Invalid Start/End Date (end_date < start_date)
     */
    public function test_06_invalid_dates_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC06');
        $resident = $this->createResident($apt, 'Nguyen Van TC06');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-10', // Bé hơn start_date
            'reason' => 'Đăng ký tạm trú',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    /**
     * 7. Test Missing Reason
     */
    public function test_07_missing_reason_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC07');
        $resident = $this->createResident($apt, 'Nguyen Van TC07');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-30',
            'reason' => '', // Thiếu lý do
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * 8 & 9. Test Upload CCCD Mặt trước & Mặt sau
     */
    public function test_08_09_upload_cccd_front_and_back(): void
    {
        Storage::fake('public');
        [, $token] = $this->createAdminUser();

        $fileFront = UploadedFile::fake()->image('cccd_front.jpg', 800, 600);
        $resFront = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $fileFront,
                'side' => 'front',
            ], ['Accept' => 'application/json']);

        $resFront->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertNotEmpty($resFront->json('url'));

        $fileBack = UploadedFile::fake()->image('cccd_back.png', 800, 600);
        $resBack = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $fileBack,
                'side' => 'back',
            ], ['Accept' => 'application/json']);

        $resBack->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertNotEmpty($resBack->json('url'));
    }

    /**
     * 10 & 11. Test Get List & Get Detail
     */
    public function test_10_11_get_list_and_detail(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC10');
        $resident = $this->createResident($apt, 'Nguyen Van TC10');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Test chi tiết và danh sách',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        // Get list
        $resList = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents/temporary-registrations');
        $resList->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // Get detail
        $resDetail = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}");
        $resDetail->assertStatus(200)
            ->assertJsonPath('data.id', $reg->id)
            ->assertJsonPath('data.reason', 'Test chi tiết và danh sách');
    }

    /**
     * 12, 13, 14. Test Search & Filters (Filter by type & status)
     */
    public function test_12_13_14_search_and_filters(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC12');
        $resident = $this->createResident($apt, 'Vuong Van Tim Kiem');

        TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-20',
            'reason' => 'Di cong tac tai Ha Noi',
            'police_status' => 'SUBMITTED_TO_POLICE',
            'police_reference_code' => 'CA-HN-8899',
        ]);

        // Search theo tên cư dân
        $resSearch = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents/temporary-registrations?search=Vuong+Van+Tim+Kiem');
        $resSearch->assertStatus(200);
        $this->assertNotEmpty($resSearch->json('data'));

        // Filter by registration_type
        $resFilterType = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents/temporary-registrations?registration_type=TEMPORARY_ABSENCE');
        $resFilterType->assertStatus(200);
        $this->assertNotEmpty($resFilterType->json('data'));

        // Filter by police_status
        $resFilterStatus = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents/temporary-registrations?police_status=SUBMITTED_TO_POLICE');
        $resFilterStatus->assertStatus(200);
        $this->assertNotEmpty($resFilterStatus->json('data'));
    }

    /**
     * 15, 17, 18. Test Phê Duyệt (Approve) & reviewed_by lấy từ auth user & reviewed_at do backend sinh
     */
    public function test_15_17_18_approve_temporary_registration(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC15');
        $resident = $this->createResident($apt, 'Nguyen Van TC15');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú cần duyệt',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$reg->id}/approve", [
                'police_reference_code' => 'CA-PHUONG-1234',
                'notes' => 'Đã đối soát CCCD hợp lệ',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.police_status', 'APPROVED')
            ->assertJsonPath('data.reviewed_by', $admin->id)
            ->assertJsonPath('data.police_reference_code', 'CA-PHUONG-1234');

        $fresh = TemporaryRegistration::find($reg->id);
        $this->assertEquals('APPROVED', $fresh->police_status);
        $this->assertEquals($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at, 'reviewed_at phải do backend tự động sinh');
    }

    /**
     * 16. Test Từ Chối (Reject)
     */
    public function test_16_reject_temporary_registration(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC16');
        $resident = $this->createResident($apt, 'Nguyen Van TC16');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$reg->id}/reject", [
                'reason' => 'Thiếu ảnh CCCD mặt sau',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.police_status', 'REJECTED')
            ->assertJsonPath('data.reviewed_by', $admin->id)
            ->assertJsonPath('data.notes', 'Thiếu ảnh CCCD mặt sau');

        $fresh = TemporaryRegistration::find($reg->id);
        $this->assertEquals('REJECTED', $fresh->police_status);
    }

    /**
     * 19. Unauthorized Approve → 403 Forbidden
     */
    public function test_19_unauthorized_approve_returns_403(): void
    {
        [, $normalToken] = $this->createNormalUser();
        $apt = $this->createApartment('TC19');
        $resident = $this->createResident($apt, 'Nguyen Van TC19');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$normalToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$reg->id}/approve", [
                'notes' => 'Cố ý duyệt không có quyền',
            ]);

        $res->assertStatus(403);
    }

    /**
     * 20. Guest (không token) → 401 Unauthorized
     */
    public function test_20_guest_returns_401(): void
    {
        $res = $this->getJson('/api/v1/residents/temporary-registrations');
        $res->assertStatus(401);
    }

    /**
     * 21. Invalid State Transition (Không cho duyệt lại hồ sơ đã từ chối hoặc đã duyệt)
     */
    public function test_21_invalid_state_transition_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC21');
        $resident = $this->createResident($apt, 'Nguyen Van TC21');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú',
            'police_status' => 'REJECTED',
            'notes' => 'Đã từ chối trước đó',
        ]);

        // Cố duyệt hồ sơ đã bị từ chối -> Phải trả về 409 Conflict
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$reg->id}/approve");

        $res->assertStatus(409)
            ->assertJsonPath('error', 'CONFLICT');
    }

    /**
     * 22 & 23. Test Export Form CT01 & Download Form
     */
    public function test_22_23_export_and_download_form(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC22');
        $resident = $this->createResident($apt, 'Nguyen Van Export');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú xuất biểu mẫu',
            'police_status' => 'APPROVED',
            'police_reference_code' => 'CT01-EXPORT-999',
        ]);

        // 22. Export form JSON
        $resExport = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}/export");

        $resExport->assertStatus(200)
            ->assertJsonPath('data.form_code', 'CT01')
            ->assertJsonPath('data.police_reference_code', 'CT01-EXPORT-999');
        $this->assertStringContainsString('TỜ KHAI THAY ĐỔI THÔNG TIN CƯ TRÚ', $resExport->json('data.html_content'));

        // 23. Download form HTML file
        $resDownload = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/residents/temporary-registrations/{$reg->id}/download");

        $resDownload->assertStatus(200);
        $this->assertStringContainsString('text/html', $resDownload->headers->get('Content-Type'));
    }

    /**
     * 26, 27, 28. Kiểm tra KHÔNG thay đổi Resident, KHÔNG thay đổi Apartment, KHÔNG thay đổi RBAC
     */
    public function test_26_27_28_no_changes_to_resident_apartment_rbac(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('TC26');
        $resident = $this->createResident($apt, 'Nguyen Van An Toan');

        $initialResidentUpdatedAt = (string) $resident->updated_at;
        $initialApartmentUpdatedAt = (string) $apt->updated_at;
        $initialRoleCount = DB::table('roles')->count();
        $initialPermissionCount = DB::table('permissions')->count();

        // Tạo hồ sơ tạm trú
        $resCreate = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $apt->id,
                'resident_id' => $resident->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-10-01',
                'end_date' => '2026-12-31',
                'reason' => 'Đăng ký tạm trú kiểm tra an toàn dữ liệu',
            ]);
        $resCreate->assertStatus(201);
        $regId = $resCreate->json('data.id');

        // Phê duyệt hồ sơ
        $resApprove = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$regId}/approve", [
                'police_reference_code' => 'CA-ANTOAN-001',
            ]);
        $resApprove->assertStatus(200);

        // Kiểm tra cư dân không bị thay đổi
        $freshResident = Resident::find($resident->id);
        $this->assertEquals($resident->resident_type, $freshResident->resident_type);
        $this->assertEquals($resident->is_head_of_household, $freshResident->is_head_of_household);
        $this->assertEquals($resident->relationship_to_head, $freshResident->relationship_to_head);

        // Kiểm tra căn hộ không bị thay đổi
        $freshApartment = Apartment::find($apt->id);
        $this->assertEquals($apt->status, $freshApartment->status);
        $this->assertEquals($apt->apartment_number, $freshApartment->apartment_number);

        // Kiểm tra RBAC không bị thay đổi
        $this->assertEquals($initialRoleCount, DB::table('roles')->count());
        $this->assertEquals($initialPermissionCount, DB::table('permissions')->count());
    }
}
