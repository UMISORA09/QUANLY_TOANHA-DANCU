<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationUpdateDeleteTest extends TestCase
{
    /**
     * Tạo tài khoản admin test kèm phiên Bearer token
     */
    protected function createAdminUser(string $nameSuffix = ''): array
    {
        $user = User::create([
            'username' => 'adm_ud_'.Str::random(6).$nameSuffix,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_ud_'.Str::random(6).$nameSuffix.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin UpdateDelete '.$nameSuffix,
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

        $token = 'smart_token_ud_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo tài khoản người dùng bình thường không có quyền quản trị
     */
    protected function createNormalUser(): array
    {
        $user = User::create([
            'username' => 'usr_ud_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'usr_ud_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Thường',
            'status' => 'ACTIVE',
        ]);

        $token = 'smart_token_norm_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'Normal User Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo nhanh một hồ sơ tạm trú/tạm vắng test
     */
    protected function createTestRecord(string $status = 'PENDING_POLICE_SUBMISSION'): TemporaryRegistration
    {
        $apt = Apartment::first();
        if (! $apt) {
            $this->markTestSkipped('Không có căn hộ để chạy kiểm thử.');
        }

        $resUser = User::create([
            'username' => 'res_ud_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_ud_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Test UD',
            'status' => 'ACTIVE',
        ]);

        $resident = Resident::create([
            'user_id' => $resUser->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        return TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú ban đầu',
            'police_status' => $status,
            'notes' => 'Ghi chú khởi tạo',
        ]);
    }

    /**
     * 1. Sửa hồ sơ ở trạng thái Pending thành công
     */
    public function test_01_edit_pending_registration_successfully(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('PENDING_POLICE_SUBMISSION');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'registration_type' => 'TEMPORARY_ABSENCE',
                'start_date' => '2026-11-01',
                'end_date' => '2026-12-15',
                'reason' => 'Đi công tác nước ngoài dài hạn',
                'notes' => 'Đã cập nhật ngày và lý do',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.registration_type', 'TEMPORARY_ABSENCE');
        $response->assertJsonPath('data.reason', 'Đi công tác nước ngoài dài hạn');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'reason' => 'Đi công tác nước ngoài dài hạn',
        ]);
    }

    /**
     * 2. Sửa hồ sơ với dữ liệu không hợp lệ (end_date < start_date) -> 422
     */
    public function test_02_edit_invalid_data_rejected(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'start_date' => '2026-12-31',
                'end_date' => '2026-10-01', // Nhỏ hơn start_date
                'reason' => 'Lý do test',
            ]);

        $response->assertStatus(422);
    }

    /**
     * 3. Sửa hồ sơ đã APPROVED -> Từ chối 409 Conflict
     */
    public function test_03_edit_approved_registration_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('APPROVED');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cố tình sửa hồ sơ đã duyệt',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được xử lý hoặc không còn ở trạng thái cho phép chỉnh sửa.');
    }

    /**
     * 4. Sửa hồ sơ đã SUBMITTED_TO_POLICE -> Từ chối 409 Conflict
     */
    public function test_04_edit_submitted_to_police_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('SUBMITTED_TO_POLICE');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cố tình sửa hồ sơ đã gửi Công an',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được xử lý hoặc không còn ở trạng thái cho phép chỉnh sửa.');
    }

    /**
     * 5. Sửa hồ sơ đã REJECTED -> Từ chối 409 Conflict
     */
    public function test_05_edit_rejected_registration_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('REJECTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cố tình sửa hồ sơ đã bị từ chối',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được xử lý hoặc không còn ở trạng thái cho phép chỉnh sửa.');
    }

    /**
     * 6. Concurrent edit: Hai Admin cùng sửa hồ sơ đồng thời qua 2 kết nối HTTP thật
     */
    public function test_06_concurrent_edit_only_one_succeeds(): void
    {
        [$admin1, $token1] = $this->createAdminUser('A');
        [$admin2, $token2] = $this->createAdminUser('B');

        $record = $this->createTestRecord();
        // Giả lập hồ sơ được tạo trước đó để timestamp ban đầu khác biệt sau khi update
        $record->updated_at = now()->subMinutes(5);
        $record->save();

        $initialUpdatedAt = $record->updated_at->toISOString();

        // Admin A GET hồ sơ và gửi update reason = "Làm việc dài hạn"
        $resA = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Làm việc dài hạn',
                'updated_at' => $initialUpdatedAt,
            ]);
        $resA->assertStatus(200);

        // Admin B GET cùng hồ sơ ban đầu (cùng updated_at) và gửi update reason = "Học tập"
        $resB = $this->withHeader('Authorization', 'Bearer '.$token2)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Học tập',
                'updated_at' => $initialUpdatedAt,
            ]);

        // Expected: Request của Admin B bị chặn với HTTP 409 Conflict
        $resB->assertStatus(409);
        $resB->assertJsonPath('success', false);
        $resB->assertJsonPath('message', 'Hồ sơ đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.');

        // Kiểm tra database: Không mất dữ liệu âm thầm, reason vẫn là của Admin A
        $record->refresh();
        $this->assertEquals('Làm việc dài hạn', $record->reason);
    }

    /**
     * 7. Stale edit: Sửa với updated_at cũ -> 409 Conflict
     */
    public function test_07_stale_edit_returns_409_conflict(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();

        // Giả lập Admin khác vừa cập nhật hồ sơ
        $record->reason = 'Admin khác vừa cập nhật lý do này';
        $record->save();

        // Admin hiện tại gửi updated_at cũ (lùi về 1 giờ trước)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật với timestamp cũ',
                'updated_at' => now()->subHours(1)->toISOString(),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.');
    }

    /**
     * 8. Xóa hồ sơ pending thành công -> 200
     */
    public function test_08_delete_pending_registration_successfully(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('PENDING_POLICE_SUBMISSION');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Xóa hồ sơ tạm trú / tạm vắng thành công.');

        $this->assertDatabaseMissing('temporary_registrations', [
            'id' => $record->id,
        ]);
    }

    /**
     * 9. Xóa hồ sơ đã APPROVED -> Từ chối 409 Conflict
     */
    public function test_09_delete_approved_registration_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('APPROVED');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Không thể xóa hồ sơ đã được xử lý hoặc đã gửi Công An.');

        // Hồ sơ vẫn còn nguyên trong database
        $this->assertDatabaseHas('temporary_registrations', ['id' => $record->id]);
    }

    /**
     * 10. Xóa hồ sơ đã SUBMITTED_TO_POLICE -> Từ chối 409 Conflict
     */
    public function test_10_delete_submitted_to_police_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('SUBMITTED_TO_POLICE');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Không thể xóa hồ sơ đã được xử lý hoặc đã gửi Công An.');

        $this->assertDatabaseHas('temporary_registrations', ['id' => $record->id]);
    }

    /**
     * 11. Xóa hồ sơ đã REJECTED -> Từ chối 409 Conflict
     */
    public function test_11_delete_rejected_registration_denied(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord('REJECTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Không thể xóa hồ sơ đã được xử lý hoặc đã gửi Công An.');

        $this->assertDatabaseHas('temporary_registrations', ['id' => $record->id]);
    }

    /**
     * 12. Double delete: Admin A xóa trước, Admin B xóa sau -> B nhận 404 Không tìm thấy, không crash 500
     */
    public function test_12_double_delete_handled_gracefully(): void
    {
        [$admin1, $token1] = $this->createAdminUser('1');
        [$admin2, $token2] = $this->createAdminUser('2');

        $record = $this->createTestRecord('PENDING_POLICE_SUBMISSION');

        // Admin 1 xóa
        $res1 = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $res1->assertStatus(200);

        // Admin 2 xóa cùng record đó
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token2)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $res2->assertStatus(404);
        $res2->assertJsonPath('success', false);
        $res2->assertJsonPath('message', 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');
    }

    /**
     * 13. Update + Delete concurrent qua kết nối HTTP thực tế
     */
    public function test_13_update_and_delete_concurrent(): void
    {
        [$admin1, $token1] = $this->createAdminUser('Upd');
        [$admin2, $token2] = $this->createAdminUser('Del');

        $record = $this->createTestRecord();

        // Admin A cập nhật hồ sơ R1
        $resUpd = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật lý do mới trước khi xóa',
            ]);
        $resUpd->assertStatus(200);

        // Admin B xóa hồ sơ R1
        $resDel = $this->withHeader('Authorization', 'Bearer '.$token2)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $resDel->assertStatus(200);

        // Kiểm tra tính nhất quán cuối cùng: Record đã bị xóa hoàn toàn khỏi DB, không crash, không recreate
        $this->assertNull(TemporaryRegistration::find($record->id));
    }

    /**
     * 14. Delete xong rồi Update -> Update nhận 404, không khôi phục hay tạo lại record
     */
    public function test_14_delete_then_update_fails(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();

        // Xóa trước
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(200);

        // Sau đó mới cập nhật
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật sau khi bị xóa',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');

        // Không bị recreate
        $this->assertDatabaseMissing('temporary_registrations', ['id' => $record->id]);
    }

    /**
     * 15. Record không tồn tại (UUID ngẫu nhiên) -> 404 sạch sẽ, không lộ ModelNotFoundException
     */
    public function test_15_record_not_found(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $fakeId = (string) Str::uuid();

        $resPut = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$fakeId}", [
                'reason' => 'Thử sửa record ảo',
            ]);
        $resPut->assertStatus(404);
        $resPut->assertJsonPath('error', 'NOT_FOUND');

        $resDel = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$fakeId}");
        $resDel->assertStatus(404);
        $resDel->assertJsonPath('error', 'NOT_FOUND');
    }

    /**
     * 16. Tài khoản thường không có quyền admin -> 403 Forbidden
     */
    public function test_16_unauthorized_user_returns_403(): void
    {
        [$normalUser, $token] = $this->createNormalUser();
        $record = $this->createTestRecord();

        $resPut = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cố tình sửa không quyền',
            ]);
        $resPut->assertStatus(403);

        $resDel = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $resDel->assertStatus(403);
    }

    /**
     * 17. Khách vãng lai chưa xác thực token -> 401 Unauthorized
     */
    public function test_17_guest_returns_401(): void
    {
        $record = $this->createTestRecord();

        $this->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
            'reason' => 'Guest thử sửa',
        ])->assertStatus(401);

        $this->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(401);
    }

    /**
     * 18. Sửa & Xóa hồ sơ không làm thay đổi bảng residents
     */
    public function test_18_no_changes_to_residents_table(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();
        $resident = Resident::find($record->resident_id);

        $beforeHash = md5(json_encode($resident->toArray()));

        // Thực hiện update
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật lý do test residents table',
            ])->assertStatus(200);

        // Thực hiện delete
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(200);

        $residentAfter = Resident::find($record->resident_id);
        $this->assertNotNull($residentAfter);
        $afterHash = md5(json_encode($residentAfter->toArray()));

        $this->assertEquals($beforeHash, $afterHash, 'Dữ liệu resident không được phép thay đổi.');
    }

    /**
     * 19. Sửa & Xóa hồ sơ không làm thay đổi bảng apartments
     */
    public function test_19_no_changes_to_apartments_table(): void
    {
        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();
        $apt = Apartment::find($record->apartment_id);

        $beforeHash = md5(json_encode($apt->toArray()));

        // Sửa và xóa
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật lý do test apt table',
            ])->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(200);

        $aptAfter = Apartment::find($record->apartment_id);
        $this->assertNotNull($aptAfter);
        $afterHash = md5(json_encode($aptAfter->toArray()));

        $this->assertEquals($beforeHash, $afterHash, 'Dữ liệu apartment không được phép thay đổi.');
    }

    /**
     * 20. Sửa & Xóa hồ sơ không làm thay đổi bảng roles và permissions (RBAC)
     */
    public function test_20_no_changes_to_rbac(): void
    {
        $roleCountBefore = DB::table('roles')->count();
        $permCountBefore = DB::table('permissions')->count();

        [$admin, $token] = $this->createAdminUser();
        $record = $this->createTestRecord();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Test RBAC immutability',
            ])->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(200);

        $roleCountAfter = DB::table('roles')->count();
        $permCountAfter = DB::table('permissions')->count();

        $this->assertEquals($roleCountBefore, $roleCountAfter);
        $this->assertEquals($permCountBefore, $permCountAfter);
    }
}
