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

class ResidentTemporaryRegistrationTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên (SUPER_ADMIN hoặc BUILDING_MANAGER) kèm Bearer token
     */
    protected function createAdminOrManagerUser(string $roleCode = 'SUPER_ADMIN'): array
    {
        $user = User::create([
            'username' => 'adm_tr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_tr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $roleCode === 'SUPER_ADMIN' ? 'Super Admin User' : 'Building Manager User',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', $roleCode)->first();
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
            'device_name' => 'PHPUnit Admin Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test
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
     * Tạo tài khoản cư dân hợp lệ thuộc một căn hộ
     */
    protected function createResidentUser(Apartment $apt, bool $isHead = true, string $residentType = 'OWNER'): array
    {
        $user = User::create([
            'username' => 'res_tr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_tr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cu Dan '.($isHead ? 'Chu Ho ' : 'Thanh Vien ').Str::random(4),
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);

        $roleCode = $residentType === 'OWNER' ? 'RESIDENT_OWNER' : 'RESIDENT_MEMBER';
        $role = Role::where('role_code', $roleCode)->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $resident = Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt->id,
            'resident_type' => $residentType,
            'is_head_of_household' => $isHead,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => $isHead ? 'SELF' : 'MEMBER',
            'is_active' => true,
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $resident, $token];
    }

    // ==========================================
    // NHÓM A – ĐĂNG KÝ HỢP LỆ
    // ==========================================

    /**
     * 1. Cư dân hợp lệ đăng ký tạm trú thành công (TEMPORARY_STAY)
     */
    public function test_01_resident_creates_temporary_stay_successfully(): void
    {
        $apt = $this->createApartment('TC01');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Đăng ký tạm trú làm việc tại công nghệ',
            'notes' => 'Hồ sơ cư dân tự khai báo',
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
            'reviewed_by' => null,
        ]);
    }

    /**
     * 2. Cư dân hợp lệ đăng ký tạm vắng thành công (TEMPORARY_ABSENCE)
     */
    public function test_02_resident_creates_temporary_absence_successfully(): void
    {
        $apt = $this->createApartment('TC02');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-25',
            'reason' => 'Đi công tác nước ngoài dài ngày',
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
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);
    }

    /**
     * 3. Hồ sơ được tạo đúng người đăng ký và đúng căn hộ sở hữu
     */
    public function test_03_record_created_with_exact_resident_and_apartment(): void
    {
        $apt = $this->createApartment('TC03');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-20',
            'reason' => 'Xác nhận chính xác căn hộ và nhân khẩu',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(201);
        $recordId = $res->json('data.id');

        $dbRecord = TemporaryRegistration::find($recordId);
        $this->assertNotNull($dbRecord);
        $this->assertEquals($resident->id, $dbRecord->resident_id);
        $this->assertEquals($apt->id, $dbRecord->apartment_id);
    }

    /**
     * 4. Trạng thái ban đầu bắt buộc là PENDING_POLICE_SUBMISSION
     */
    public function test_04_initial_status_remains_pending_police_submission(): void
    {
        $apt = $this->createApartment('TC04');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-20',
            'reason' => 'Kiểm tra trạng thái ban đầu',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(201);
        $this->assertEquals('PENDING_POLICE_SUBMISSION', $res->json('data.police_status'));
    }

    // ==========================================
    // NHÓM B – BẢO MẬT VÀ IDOR
    // ==========================================

    /**
     * 5. Cư dân không thể giả mạo user_id để chiếm đoạt danh tính hoặc tự duyệt
     */
    public function test_05_resident_cannot_spoof_user_id(): void
    {
        $apt = $this->createApartment('TC05');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');
        [$adminUser] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $payload = [
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Thử nghiệm giả mạo user_id',
            'user_id' => $adminUser->id,
            'reviewed_by' => $adminUser->id,
            'police_status' => 'APPROVED',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(201);
        $recordId = $res->json('data.id');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordId,
            'police_status' => 'PENDING_POLICE_SUBMISSION',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    /**
     * 6. Cư dân không thể giả mạo resident_id của người khác ở căn hộ khác (Anti-IDOR)
     */
    public function test_06_resident_cannot_spoof_resident_id(): void
    {
        $aptA = $this->createApartment('TC06A');
        $aptB = $this->createApartment('TC06B');

        [, , $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $payload = [
            'apartment_id' => $aptA->id,
            'resident_id' => $residentB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Cố tình đăng ký mạo danh resident_id',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $this->assertTrue(in_array($res->status(), [403, 422], true));

        $this->assertDatabaseMissing('temporary_registrations', [
            'apartment_id' => $aptA->id,
            'resident_id' => $residentB->id,
        ]);
    }

    /**
     * 7. Cư dân không thể đăng ký vào căn hộ không có quan hệ cư trú hợp lệ
     */
    public function test_07_resident_cannot_register_for_unauthorized_apartment(): void
    {
        $aptA = $this->createApartment('TC07A');
        $aptB = $this->createApartment('TC07B');

        [, $residentA, $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');

        $payload = [
            'apartment_id' => $aptB->id,
            'resident_id' => $residentA->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Khai báo gian lận căn hộ khác',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/residents/temporary-registrations', $payload);

        $res->assertStatus(403);

        $this->assertDatabaseMissing('temporary_registrations', [
            'apartment_id' => $aptB->id,
            'resident_id' => $residentA->id,
        ]);
    }

    /**
     * 8. Cư dân xem danh sách được Data Scoping: Không thể xem hồ sơ căn hộ khác
     */
    public function test_08_resident_cannot_see_records_of_other_apartments(): void
    {
        $aptA = $this->createApartment('TC08A');
        $aptB = $this->createApartment('TC08B');

        [, $residentA, $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $recordA = TemporaryRegistration::create([
            'resident_id' => $residentA->id,
            'apartment_id' => $aptA->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ căn hộ A',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ căn hộ B bí mật',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/residents/temporary-registrations');

        $res->assertStatus(200);

        $data = $res->json('data');
        $ids = collect($data)->pluck('id')->toArray();

        $this->assertContains($recordA->id, $ids);
        $this->assertNotContains($recordB->id, $ids);
    }

    /**
     * 9. Cư dân không thể xem chi tiết hồ sơ của người khác
     */
    public function test_09_resident_cannot_view_detail_of_other_residents_records(): void
    {
        $aptA = $this->createApartment('TC09A');
        $aptB = $this->createApartment('TC09B');

        [, , $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ căn hộ B bí mật',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $res->assertStatus(403);
    }

    /**
     * 10. Cư dân không thể sửa hồ sơ của người khác
     */
    public function test_10_resident_cannot_update_records_of_other_residents(): void
    {
        $aptA = $this->createApartment('TC10A');
        $aptB = $this->createApartment('TC10B');

        [, , $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Lý do ban đầu căn hộ B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordB->id}", [
                'reason' => 'Cố tình sửa lén hồ sơ căn hộ B',
            ]);

        $res->assertStatus(403);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
            'reason' => 'Lý do ban đầu căn hộ B',
        ]);
    }

    /**
     * 11. Cư dân không thể xóa hồ sơ của người khác
     */
    public function test_11_resident_cannot_delete_records_of_other_residents(): void
    {
        $aptA = $this->createApartment('TC11A');
        $aptB = $this->createApartment('TC11B');

        [, , $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ không được xóa',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $res->assertStatus(403);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
        ]);
    }

    /**
     * 12. Cư dân không thể upload ảnh CCCD vào hồ sơ của người khác
     */
    public function test_12_resident_cannot_upload_cccd_to_other_residents_records(): void
    {
        $aptA = $this->createApartment('TC12A');
        $aptB = $this->createApartment('TC12B');

        [, , $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ của người B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $file = UploadedFile::fake()->image('fake_cccd.jpg', 800, 600);

        $res = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $file,
                'side' => 'front',
                'registration_id' => $recordB->id,
            ]);

        $res->assertStatus(403);
    }

    // ==========================================
    // NHÓM C – BẢO VỆ NGHIỆP VỤ & QUYỀN QUẢN TRỊ
    // ==========================================

    /**
     * 13. Cư dân không thể tự approve hồ sơ
     */
    public function test_13_resident_cannot_approve_registration(): void
    {
        $apt = $this->createApartment('TC13');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $record = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Chờ duyệt',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve");

        $res->assertStatus(403);
    }

    /**
     * 14. Cư dân không thể tự reject hồ sơ
     */
    public function test_14_resident_cannot_reject_registration(): void
    {
        $apt = $this->createApartment('TC14');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $record = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Chờ duyệt',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/reject", [
                'reason' => 'Tự từ chối',
            ]);

        $res->assertStatus(403);
    }

    /**
     * 15. Cư dân không thể tự submit hồ sơ sang Công An
     */
    public function test_15_resident_cannot_submit_to_police(): void
    {
        $apt = $this->createApartment('TC15');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $record = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Chờ nộp CA',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police");

        $res->assertStatus(403);
    }

    /**
     * 16. Cư dân không thể sửa hồ sơ ở trạng thái không được phép (APPROVED/REJECTED)
     */
    public function test_16_resident_cannot_edit_record_in_locked_status(): void
    {
        $apt = $this->createApartment('TC16');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $approvedRecord = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Đã duyệt xong',
            'police_status' => 'APPROVED',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/residents/temporary-registrations/{$approvedRecord->id}", [
                'reason' => 'Sửa hồ sơ đã duyệt',
            ]);

        $res->assertStatus(409);
    }

    /**
     * 17. Cư dân không thể xóa hồ sơ ở trạng thái không được phép (APPROVED/SUBMITTED)
     */
    public function test_17_resident_cannot_delete_record_in_locked_status(): void
    {
        $apt = $this->createApartment('TC17');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');

        $submittedRecord = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Đã gửi CA',
            'police_status' => 'SUBMITTED_TO_POLICE',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$submittedRecord->id}");

        $res->assertStatus(409);
    }

    // ==========================================
    // NHÓM D – HỒI QUY VÀ BẢO TỒN QUẢN TRỊ
    // ==========================================

    /**
     * 18. Người chưa đăng nhập không thể sử dụng API
     */
    public function test_18_unauthenticated_guest_cannot_access_apis(): void
    {
        $fakeUuid = (string) Str::uuid();

        $this->getJson('/api/v1/residents/temporary-registrations')
            ->assertStatus(401);

        $this->postJson('/api/v1/residents/temporary-registrations', [
            'reason' => 'Khách vãng lai gửi',
        ])->assertStatus(401);

        $this->getJson("/api/v1/residents/temporary-registrations/{$fakeUuid}")
            ->assertStatus(401);

        $this->putJson("/api/v1/residents/temporary-registrations/{$fakeUuid}", [
            'reason' => 'Khách vãng lai sửa',
        ])->assertStatus(401);

        $this->deleteJson("/api/v1/residents/temporary-registrations/{$fakeUuid}")
            ->assertStatus(401);
    }

    /**
     * 19. Admin vẫn xem và quản lý hồ sơ bình thường
     */
    public function test_19_admin_retains_full_management_and_approval_rights(): void
    {
        $aptA = $this->createApartment('TC19A');
        $aptB = $this->createApartment('TC19B');

        [, $residentA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($aptB, true, 'OWNER');
        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $recordA = TemporaryRegistration::create([
            'resident_id' => $residentA->id,
            'apartment_id' => $aptA->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ phòng A',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $aptB->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ phòng B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        // Admin thấy toàn bộ danh sách cả 2 căn hộ
        $resList = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/v1/residents/temporary-registrations');
        $resList->assertStatus(200);
        $ids = collect($resList->json('data'))->pluck('id')->toArray();
        $this->assertContains($recordA->id, $ids);
        $this->assertContains($recordB->id, $ids);

        // Admin phê duyệt hồ sơ A
        $resApprove = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$recordA->id}/approve", [
                'notes' => 'Admin phê duyệt thành công',
            ]);
        $resApprove->assertStatus(200)
            ->assertJsonPath('data.police_status', 'APPROVED')
            ->assertJsonPath('data.reviewed_by', $adminUser->id);
    }

    /**
     * 20. Building Manager vẫn xem và quản lý hồ sơ theo quyền hiện tại
     */
    public function test_20_building_manager_retains_management_and_approval_rights(): void
    {
        $apt = $this->createApartment('TC20');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        [$managerUser, $managerToken] = $this->createAdminOrManagerUser('BUILDING_MANAGER');

        $record = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ chờ Manager duyệt',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        // Manager nộp công an
        $resSubmit = $this->withHeader('Authorization', "Bearer {$managerToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police", [
                'police_reference_code' => 'MGR-CA-7788',
                'notes' => 'Manager gửi CA',
            ]);
        $resSubmit->assertStatus(200)
            ->assertJsonPath('data.police_status', 'SUBMITTED_TO_POLICE');

        // Manager phê duyệt
        $resApprove = $this->withHeader('Authorization', "Bearer {$managerToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve", [
                'notes' => 'Manager duyệt hồ sơ',
            ]);
        $resApprove->assertStatus(200)
            ->assertJsonPath('data.police_status', 'APPROVED')
            ->assertJsonPath('data.reviewed_by', $managerUser->id);
    }

    /**
     * 21. Dữ liệu và trạng thái hồ sơ cũ không bị ảnh hưởng (Export biểu mẫu CT01)
     */
    public function test_21_legacy_records_and_ct01_export_remain_intact(): void
    {
        $apt = $this->createApartment('TC21');
        [, $resident, $token] = $this->createResidentUser($apt, true, 'OWNER');
        [$adminUser] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $legacyRecord = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'reason' => 'Hồ sơ tạm trú từ đợt cũ',
            'police_status' => 'APPROVED',
            'reviewed_by' => $adminUser->id,
            'reviewed_at' => now(),
        ]);

        $resExport = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$legacyRecord->id}/export");

        $resExport->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * 22. Hộ gia đình nhiều thành viên:
     * Chủ hộ có thể đăng ký cho thành viên khác trong cùng hộ,
     * nhưng thành viên thường không thể đăng ký thay cho chủ hộ
     */
    public function test_22_head_of_household_can_register_for_family_member_in_same_apartment(): void
    {
        $apt = $this->createApartment('TC22');

        // Tạo chủ hộ
        [, $headResident, $headToken] = $this->createResidentUser($apt, true, 'OWNER');

        // Tạo thành viên cùng căn hộ
        [, $memberResident, $memberToken] = $this->createResidentUser($apt, false, 'TENANT');

        // 1. Chủ hộ đăng ký tạm trú cho thành viên trong hộ -> Thành công
        $resHeadAction = $this->withHeader('Authorization', "Bearer {$headToken}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $apt->id,
                'resident_id' => $memberResident->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30',
                'reason' => 'Chủ hộ đăng ký tạm trú cho thành viên gia đình',
            ]);

        $resHeadAction->assertStatus(201)
            ->assertJsonPath('data.resident_id', $memberResident->id)
            ->assertJsonPath('data.apartment_id', $apt->id);

        // 2. Thành viên thường cố đăng ký cho Chủ hộ -> Bị từ chối 403 Forbidden
        $resMemberAction = $this->withHeader('Authorization', "Bearer {$memberToken}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $apt->id,
                'resident_id' => $headResident->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30',
                'reason' => 'Thành viên thường mạo danh đăng ký cho chủ hộ',
            ]);

        $resMemberAction->assertStatus(403);
    }

    // ==========================================
    // NHÓM E – KIỂM THỬ QUAN HỆ CÙNG CĂN HỘ (A, B, C)
    // ==========================================

    /**
     * 23. Thành viên C cùng căn hộ không xem được hồ sơ do B đăng ký trong danh sách
     */
    public function test_23_same_apartment_member_c_cannot_see_member_b_records_in_list(): void
    {
        $apt = $this->createApartment('TC23');

        [, $residentB, $tokenB] = $this->createResidentUser($apt, false, 'TENANT');
        [, $residentC, $tokenC] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ của thành viên B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $recordC = TemporaryRegistration::create([
            'resident_id' => $residentC->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ của thành viên C',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->getJson('/api/v1/residents/temporary-registrations');

        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->toArray();

        $this->assertContains($recordC->id, $ids);
        $this->assertNotContains($recordB->id, $ids);

        $this->assertDatabaseHas('temporary_registrations', ['id' => $recordB->id]);
        $this->assertDatabaseHas('temporary_registrations', ['id' => $recordC->id]);
    }

    /**
     * 24. Thành viên C cùng căn hộ không xem được chi tiết hồ sơ của B (403 Forbidden)
     */
    public function test_24_same_apartment_member_c_cannot_view_detail_of_member_b_record(): void
    {
        $apt = $this->createApartment('TC24');

        [, $residentB] = $this->createResidentUser($apt, false, 'TENANT');
        [, , $tokenC] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ riêng của B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN')
            ->assertJsonPath('message', 'Bạn không có quyền truy cập hồ sơ tạm trú / tạm vắng này.');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
            'reason' => 'Hồ sơ riêng của B',
        ]);
    }

    /**
     * 25. Thành viên C cùng căn hộ không sửa được hồ sơ của B (403 Forbidden)
     */
    public function test_25_same_apartment_member_c_cannot_update_member_b_record(): void
    {
        $apt = $this->createApartment('TC25');

        [, $residentB] = $this->createResidentUser($apt, false, 'TENANT');
        [, , $tokenC] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Lý do ban đầu của B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordB->id}", [
                'reason' => 'Thành viên C cố tình sửa lén',
            ]);

        $res->assertStatus(403);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
            'reason' => 'Lý do ban đầu của B',
        ]);
    }

    /**
     * 26. Thành viên C cùng căn hộ không xóa được hồ sơ của B (403 Forbidden)
     */
    public function test_26_same_apartment_member_c_cannot_delete_member_b_record(): void
    {
        $apt = $this->createApartment('TC26');

        [, $residentB] = $this->createResidentUser($apt, false, 'TENANT');
        [, , $tokenC] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ B cần được bảo vệ',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $res->assertStatus(403);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
        ]);
    }

    /**
     * 27. Thành viên B truy cập và chỉnh sửa được hồ sơ của chính mình
     */
    public function test_27_same_apartment_member_b_can_access_and_update_own_record(): void
    {
        $apt = $this->createApartment('TC27');

        [, $residentB, $tokenB] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Lý do ban đầu của B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        // B xem chi tiết hồ sơ của chính mình
        $resShow = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $resShow->assertStatus(200)
            ->assertJsonPath('data.id', $recordB->id)
            ->assertJsonPath('data.resident_id', $residentB->id);

        // B cập nhật lý do mới
        $resUpdate = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordB->id}", [
                'reason' => 'B tự cập nhật lý do mới hợp lệ',
            ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.reason', 'B tự cập nhật lý do mới hợp lệ');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
            'reason' => 'B tự cập nhật lý do mới hợp lệ',
        ]);
    }

    /**
     * 28. Chủ hộ A quản lý và cập nhật được hồ sơ của thành viên B theo quyền chủ hộ
     */
    public function test_28_head_of_household_a_can_view_and_manage_member_b_record(): void
    {
        $apt = $this->createApartment('TC28');

        [, , $headTokenA] = $this->createResidentUser($apt, true, 'OWNER');
        [, $residentB] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ B tạo',
            'notes' => 'Ghi chú ban đầu',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        // A xem chi tiết hồ sơ B
        $resShow = $this->withHeader('Authorization', "Bearer {$headTokenA}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordB->id}");

        $resShow->assertStatus(200)
            ->assertJsonPath('data.id', $recordB->id);

        // A cập nhật ghi chú hồ sơ B
        $resUpdate = $this->withHeader('Authorization', "Bearer {$headTokenA}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordB->id}", [
                'notes' => 'Chủ hộ A bổ sung ghi chú xác nhận',
            ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.notes', 'Chủ hộ A bổ sung ghi chú xác nhận');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $recordB->id,
            'notes' => 'Chủ hộ A bổ sung ghi chú xác nhận',
        ]);
    }

    // ==========================================
    // NHÓM F – KIỂM THỬ API NHẠY CẢM (CCCD VÀ CT01)
    // ==========================================

    /**
     * 29. Upload CCCD hợp lệ vào hồ sơ thuộc quyền truy cập thành công
     */
    public function test_29_resident_can_upload_cccd_to_own_record(): void
    {
        Storage::fake('public');

        $apt = $this->createApartment('TC29');
        [, $residentB, $tokenB] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Đăng ký tạm trú cần CCCD',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $file = UploadedFile::fake()->image('cccd_front_b.jpg', 800, 600);

        $res = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $file,
                'side' => 'front',
                'registration_id' => $recordB->id,
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tải lên ảnh CCCD thành công.');

        $url = $res->json('url');
        $this->assertNotEmpty($url);
        $this->assertStringStartsWith('/storage/temporary_registrations/cccd/cccd_front_', $url);
    }

    /**
     * 30. Thành viên C cùng căn hộ không được upload CCCD vào hồ sơ của B (403 Forbidden)
     */
    public function test_30_member_c_cannot_upload_cccd_to_member_b_record_in_same_apartment(): void
    {
        Storage::fake('public');

        $apt = $this->createApartment('TC30');
        [, $residentB] = $this->createResidentUser($apt, false, 'TENANT');
        [, , $tokenC] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ B',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $file = UploadedFile::fake()->image('fake_cccd_c.jpg', 800, 600);

        $res = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->postJson('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $file,
                'side' => 'front',
                'registration_id' => $recordB->id,
            ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN')
            ->assertJsonPath('message', 'Bạn không có quyền tải ảnh CCCD cho hồ sơ này.');
    }

    /**
     * 31. Tải trực tiếp file HTML Biểu mẫu CT01 qua endpoint /download cho hồ sơ hợp lệ
     */
    public function test_31_resident_can_download_ct01_html_file_for_own_record(): void
    {
        $apt = $this->createApartment('TC31');
        [$userB, $residentB, $tokenB] = $this->createResidentUser($apt, false, 'TENANT');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Đăng ký tạm trú chính ngạch',
            'police_status' => 'APPROVED',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->get("/api/v1/residents/temporary-registrations/{$recordB->id}/download");

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $res->assertHeader('Content-Disposition', "inline; filename=\"bieu_mau_ct01_{$recordB->id}.html\"");

        $htmlContent = $res->getContent();
        $this->assertStringContainsString('ĐĂNG KÝ TẠM TRÚ', $htmlContent);
        $this->assertStringContainsString($apt->apartment_number, $htmlContent);
        $this->assertStringContainsString($userB->full_name, $htmlContent);
    }

    /**
     * 32. Thành viên C cùng căn hộ và cư dân D khác căn hộ không được tải CT01 của B (403 Forbidden)
     */
    public function test_32_same_apartment_member_c_and_other_resident_d_cannot_download_ct01_of_member_b(): void
    {
        $apt1 = $this->createApartment('TC32A');
        $apt2 = $this->createApartment('TC32B');

        [, $residentB] = $this->createResidentUser($apt1, false, 'TENANT');
        [, , $tokenC] = $this->createResidentUser($apt1, false, 'TENANT');
        [, , $tokenD] = $this->createResidentUser($apt2, true, 'OWNER');

        $recordB = TemporaryRegistration::create([
            'resident_id' => $residentB->id,
            'apartment_id' => $apt1->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Hồ sơ bí mật của B',
            'police_status' => 'APPROVED',
        ]);

        // 1. Thành viên C cùng căn hộ thử tải biểu mẫu của B -> Bị từ chối 403
        $resC = $this->withHeader('Authorization', "Bearer {$tokenC}")
            ->get("/api/v1/residents/temporary-registrations/{$recordB->id}/download");

        $resC->assertStatus(403);
        $this->assertStringContainsString('Bạn không có quyền tải biểu mẫu của hồ sơ này.', $resC->getContent());

        // 2. Cư dân D khác căn hộ thử tải biểu mẫu của B -> Bị từ chối 403
        $resD = $this->withHeader('Authorization', "Bearer {$tokenD}")
            ->get("/api/v1/residents/temporary-registrations/{$recordB->id}/download");

        $resD->assertStatus(403);
        $this->assertStringContainsString('Bạn không có quyền tải biểu mẫu của hồ sơ này.', $resD->getContent());
    }
}
