<?php

namespace Tests\Feature\ReceptionParcel;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use App\Services\ReceptionParcelService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReceptionParcelRbacPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected ReceptionParcelService $service;

    protected ?Apartment $apartment = null;

    protected ?User $resident = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->service = app(ReceptionParcelService::class);

        // 1. Tạo căn hộ mẫu
        $this->apartment = $this->createApartment('APT-PARCEL-RBAC-'.Str::random(4));

        // 2. Tạo cư dân mẫu
        $this->resident = $this->createResidentUser();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'apartment_id' => $this->apartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subMonths(3)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_'.Str::random(4),
                'block_name' => 'Tòa Tháp Test',
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
                'floor_number' => 3,
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

    protected function createResidentUser(): User
    {
        return User::create([
            'username' => 'resident_parcel_rbac_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'resident_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân RBAC Parcel Test',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo tài khoản nhân viên với vai trò và danh sách permissions cụ thể
     *
     * @return array{0: User, 1: string, 2: string} [User, Token, CustomRoleId]
     */
    protected function createStaffWithPermissions(string $roleCode, array $permissions = []): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Parcel RBAC Test",
            'status' => 'ACTIVE',
        ]);

        // 1. Gán vai trò nhân viên gốc (để nhận diện thuộc nhóm ALLOWED_STAFF_ROLES)
        $origRoleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $origRoleId) {
            $origRoleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $origRoleId,
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
            'role_id' => $origRoleId,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        // 2. Tạo vai trò CUSTOM_USER_... để override quyền hạn chính xác của user này
        // (RbacService & User model ưu tiên CUSTOM_USER_ role trên mọi role khác)
        $customRoleCode = 'CUSTOM_USER_'.strtoupper(Str::random(8));
        $customRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $customRoleId,
            'role_code' => $customRoleCode,
            'role_name' => "Custom Role {$customRoleCode}",
            'is_system_role' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRoleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        // Gán permissions vào role riêng
        foreach ($permissions as $permCode) {
            $permId = DB::table('permissions')->where('permission_code', $permCode)->value('id');
            if (! $permId) {
                $permId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permId,
                    'module' => 'PARCEL',
                    'permission_code' => $permCode,
                    'permission_name' => $permCode,
                    'created_at' => now(),
                ]);
            }
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $customRoleId,
                'permission_id' => $permId,
                'created_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Parcel RBAC Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token, $customRoleId];
    }

    /**
     * Tạo tài khoản nhân viên sử dụng vai trò thực tế từ RbacSeeder (không dùng custom role ghi đè)
     *
     * @return array{0: User, 1: string, 2: string} [User, Token, RoleId]
     */
    protected function createStaffWithSeededRole(string $roleCode): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_seeded_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_seeded_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Seeded Test",
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

        $token = 'smart_token_seeded_'.$user->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token, $roleId];
    }

    /**
     * Tạo một kiện hàng bưu phẩm mẫu
     */
    protected function createSampleParcel(array $overrides = []): Parcel
    {
        return Parcel::create(array_merge([
            'tracking_number' => 'TRK-RBAC-'.Str::random(8),
            'courier_company' => 'Shopee Express',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận Test',
            'recipient_phone' => '0912345678',
            'stored_location_type' => 'RECEPTION_DESK',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-PARCEL-'.Str::random(10),
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->resident->id,
        ], $overrides));
    }

    /**
     * Test 01: Yêu cầu không xác thực hoặc token không hợp lệ bị từ chối HTTP 401
     */
    public function test_01_unauthenticated_requests_are_rejected_with_401(): void
    {
        $parcel = $this->createSampleParcel();

        // 1. Không có token
        $this->getJson('/api/v1/reception/parcels')->assertStatus(401);
        $this->getJson("/api/v1/reception/parcels/{$parcel->id}")->assertStatus(401);
        $this->getJson("/api/v1/reception/parcels/lookup?code={$parcel->pickup_pin_code}")->assertStatus(401);
        $this->postJson('/api/v1/reception/parcels', [])->assertStatus(401);
        $this->putJson("/api/v1/reception/parcels/{$parcel->id}", [])->assertStatus(401);
        $this->deleteJson("/api/v1/reception/parcels/{$parcel->id}")->assertStatus(401);
        $this->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [])->assertStatus(401);

        // 2. Token không hợp lệ
        $this->withHeaders(['Authorization' => 'Bearer fake_invalid_token'])
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(401);

        // 3. Token bị thu hồi (is_revoked = 1)
        $revokedToken = 'revoked_token_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'refresh_token_hash' => hash('sha256', $revokedToken),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 1,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$revokedToken}"])
            ->postJson('/api/v1/reception/parcels', [])
            ->assertStatus(401);

        // 4. Token hết hạn
        $expiredToken = 'expired_token_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'refresh_token_hash' => hash('sha256', $expiredToken),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$expiredToken}"])
            ->postJson('/api/v1/reception/parcels', [])
            ->assertStatus(401);
    }

    /**
     * Test 02: Cư dân thông thường bị từ chối 403 khi thực hiện các thao tác của nhân viên
     */
    public function test_02_resident_user_cannot_perform_staff_parcel_operations(): void
    {
        $parcel = $this->createSampleParcel();

        $token = 'resident_token_'.$this->resident->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Cư dân cố tiếp nhận bưu phẩm -> 403
        $this->withHeaders($headers)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'HACK-TRK-01',
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Hacker Cư Dân',
                'recipient_phone' => '0912345678',
            ])->assertStatus(403);

        // Cư dân cố cập nhật kiện hàng -> 403
        $this->withHeaders($headers)
            ->putJson("/api/v1/reception/parcels/{$parcel->id}", [
                'recipient_name' => 'Tên Mới',
            ])->assertStatus(403);

        // Cư dân cố xóa bưu phẩm -> 403
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        // Cư dân cố check-out bàn giao -> 403
        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(403);
    }

    /**
     * Test 03: Nhân viên chỉ có quyền PARCEL:VIEW chỉ được xem/tra cứu, KHÔNG được nhận/sửa/bàn giao/xóa
     */
    public function test_03_staff_with_only_parcel_view_cannot_mutate_parcels(): void
    {
        $parcel = $this->createSampleParcel();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // 1. Thao tác xem/tra cứu -> THÀNH CÔNG 200
        $this->withHeaders($headers)
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/v1/reception/parcels/lookup?code={$parcel->pickup_pin_code}")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson('/api/v1/reception/parcels/recipients/search?q=test')
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson('/api/v1/reception/parcels/apartments/search?q=apt')
            ->assertStatus(200);

        // 2. Thao tác ghi/thay đổi trạng thái -> BỊ TỪ CHỐI 403
        $this->withHeaders($headers)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'UNAUTH-TRK-01',
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Nguyễn Văn A',
                'recipient_phone' => '0912345678',
            ])->assertStatus(403);

        $this->withHeaders($headers)
            ->putJson("/api/v1/reception/parcels/{$parcel->id}", [
                'recipient_name' => 'Tên Đổi',
            ])->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(403);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);
    }

    /**
     * Test 04: LỖI GỐC BUG-M8-01 — Tên role nhân viên KHÔNG ĐƯỢC bypass permission bị thiếu
     */
    public function test_04_staff_role_name_alone_does_not_bypass_missing_permissions(): void
    {
        $parcel = $this->createSampleParcel();

        // 1. Lễ tân chỉ được cấp quyền tiếp nhận (PARCEL:RECEIVE), KHÔNG có quyền bàn giao hay xóa
        [$recUser, $recToken] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:RECEIVE', 'PARCEL:VIEW']);
        $recHeaders = ['Authorization' => "Bearer {$recToken}", 'Accept' => 'application/json'];

        // Có thể tiếp nhận
        $this->withHeaders($recHeaders)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'OK-TRK-'.Str::random(6),
                'courier_company' => 'GHN',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận B',
                'recipient_phone' => '0987654321',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ])->assertStatus(201);

        // Nhưng KHÔNG THỂ bàn giao (thiếu PARCEL:CHECKOUT) -> 403
        $this->withHeaders($recHeaders)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(403);

        // Và KHÔNG THỂ xóa (thiếu PARCEL:DELETE) -> 403
        $this->withHeaders($recHeaders)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        // 2. Bảo vệ chỉ được cấp quyền bàn giao (PARCEL:CHECKOUT), KHÔNG có quyền tiếp nhận hay xóa
        [$secUser, $secToken] = $this->createStaffWithPermissions('SECURITY_GUARD', ['PARCEL:CHECKOUT', 'PARCEL:VIEW']);
        $secHeaders = ['Authorization' => "Bearer {$secToken}", 'Accept' => 'application/json'];

        // Có thể bàn giao
        $this->withHeaders($secHeaders)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(200);

        // Nhưng KHÔNG THỂ tiếp nhận mới (thiếu PARCEL:RECEIVE) -> 403
        $this->withHeaders($secHeaders)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'SEC-UNAUTH-TRK',
                'courier_company' => 'GHN',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận C',
                'recipient_phone' => '0987654321',
            ])->assertStatus(403);
    }

    /**
     * Test 05: Nhân viên có đầy đủ quyền hạn thực hiện thành công từng hành động tương ứng
     */
    public function test_05_staff_with_specific_permission_executes_each_action_successfully(): void
    {
        // 1. Thao tác Tiếp nhận (PARCEL:RECEIVE)
        [$userRecv, $tokRecv] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:RECEIVE']);
        $resStore = $this->withHeaders(['Authorization' => "Bearer {$tokRecv}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'VALID-TRK-'.Str::random(6),
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận Hợp Lệ',
                'recipient_phone' => '0987654321',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ]);
        $resStore->assertStatus(201);
        $newParcelId = $resStore->json('data.id');

        // 2. Thao tác Cập nhật (PARCEL:UPDATE)
        [$userUpd, $tokUpd] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:UPDATE']);
        $this->withHeaders(['Authorization' => "Bearer {$tokUpd}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/reception/parcels/{$newParcelId}", [
                'recipient_name' => 'Người Nhận Đã Đổi Tên',
            ])->assertStatus(200);

        $this->assertEquals('Người Nhận Đã Đổi Tên', Parcel::find($newParcelId)->recipient_name);

        // 3. Thao tác Bàn giao (PARCEL:CHECKOUT)
        $newParcel = Parcel::find($newParcelId);
        [$userChk, $tokChk] = $this->createStaffWithPermissions('SECURITY_GUARD', ['PARCEL:CHECKOUT']);
        $this->withHeaders(['Authorization' => "Bearer {$tokChk}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/reception/parcels/{$newParcelId}/check-out", [
                'pin_code' => $newParcel->pickup_pin_code,
            ])->assertStatus(200);

        $this->assertEquals('DELIVERED', Parcel::find($newParcelId)->status);

        // 4. Thao tác Xóa (PARCEL:DELETE) trên kiện hàng chưa bàn giao
        $pendingParcel = $this->createSampleParcel();
        [$userDel, $tokDel] = $this->createStaffWithPermissions('BUILDING_MANAGER', ['PARCEL:DELETE']);
        $this->withHeaders(['Authorization' => "Bearer {$tokDel}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/reception/parcels/{$pendingParcel->id}")
            ->assertStatus(200);

        $this->assertNull(Parcel::find($pendingParcel->id));
    }

    /**
     * Test 06: Cách ly phân quyền — VISITOR:VIEW không được mặc nhiên cấp quyền thao tác trên Parcel
     */
    public function test_06_visitor_view_permission_does_not_grant_parcel_access(): void
    {
        $parcel = $this->createSampleParcel();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW', 'VISITOR:CHECKIN']);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Cố tiếp nhận bưu phẩm -> 403
        $this->withHeaders($headers)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'VISITOR-TRK-01',
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Khách Visitor',
                'recipient_phone' => '0912345678',
            ])->assertStatus(403);

        // Cố bàn giao bưu phẩm -> 403
        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(403);

        // Cố xóa bưu phẩm -> 403
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);
    }

    /**
     * Test 07: Thu hồi quyền (Permission Revocation) có hiệu lực ngay lập tức
     */
    public function test_07_permission_revocation_takes_effect_immediately(): void
    {
        $parcel = $this->createSampleParcel();

        // 1. Ban đầu nhân viên có quyền PARCEL:DELETE
        [$user, $token, $customRoleId] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:DELETE']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Kiểm tra quyền trực tiếp trước khi thu hồi
        $this->service->assertStaffAuthorized($user, 'delete'); // Không ném exception

        // 2. Thu hồi quyền PARCEL:DELETE khỏi role của người dùng
        $deletePermId = DB::table('permissions')->where('permission_code', 'PARCEL:DELETE')->value('id');
        DB::table('role_permissions')
            ->where('role_id', $customRoleId)
            ->where('permission_id', $deletePermId)
            ->delete();

        // 3. Yêu cầu xóa qua API lập tức bị chặn 403
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        // Kiện hàng vẫn còn nguyên trong DB
        $this->assertNotNull(Parcel::find($parcel->id));
    }

    /**
     * Test 08: Quản trị viên cấp cao (SUPER_ADMIN) bypass quyền hạn hợp lệ trên toàn bộ thao tác
     */
    public function test_08_super_admin_bypasses_parcel_permissions_successfully(): void
    {
        $parcel = $this->createSampleParcel();

        // Super Admin không cần gán permission cụ thể nào
        [$adminUser, $adminToken] = $this->createStaffWithPermissions('SUPER_ADMIN', []);
        $headers = ['Authorization' => "Bearer {$adminToken}", 'Accept' => 'application/json'];

        // 1. Super Admin có thể xem
        $this->withHeaders($headers)
            ->getJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(200);

        // 2. Super Admin có thể tiếp nhận
        $this->withHeaders($headers)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'ADMIN-TRK-'.Str::random(6),
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận Admin',
                'recipient_phone' => '0912345678',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ])->assertStatus(201);

        // 3. Super Admin có thể bàn giao
        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])->assertStatus(200);
    }

    /**
     * Test 09: Phương thức assertStaffAuthorized() kiểm tra chính xác từng hành động nghiệp vụ
     */
    public function test_09_assert_staff_authorized_direct_method_verification(): void
    {
        // 1. User null -> 401
        try {
            $this->service->assertStaffAuthorized(null, 'view');
            $this->fail('Expected HttpException 401 for null user');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /**
     * Test 10: assertStaffAuthorized() chặn cư dân với HTTP 403
     */
    public function test_10_assert_staff_authorized_rejects_resident_with_403(): void
    {
        try {
            $this->service->assertStaffAuthorized($this->resident, 'receive');
            $this->fail('Expected HttpException 403 for resident');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * Test 11: assertStaffAuthorized() chặn nhân viên thiếu permission với HTTP 403
     */
    public function test_11_assert_staff_authorized_rejects_staff_without_permission_with_403(): void
    {
        [$user] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);

        try {
            $this->service->assertStaffAuthorized($user, 'delete');
            $this->fail('Expected HttpException 403 for missing delete permission');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * Test 12: Tính toàn vẹn dữ liệu — Kiện hàng không bị sửa/xóa khi request bị từ chối
     */
    public function test_12_business_data_integrity_maintained_on_rejected_requests(): void
    {
        $parcel = $this->createSampleParcel([
            'recipient_name' => 'Tên Gốc Không Đổi',
            'status' => 'RECEIVED_AT_RECEPTION',
        ]);

        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Cố cập nhật khi không có quyền
        $this->withHeaders($headers)
            ->putJson("/api/v1/reception/parcels/{$parcel->id}", [
                'recipient_name' => 'Tên Bị Đổi Trái Phép',
            ])->assertStatus(403);

        // Dữ liệu trong DB vẫn giữ nguyên giá trị ban đầu
        $freshParcel = Parcel::find($parcel->id);
        $this->assertEquals('Tên Gốc Không Đổi', $freshParcel->recipient_name);
        $this->assertEquals('RECEIVED_AT_RECEPTION', $freshParcel->status);
    }

    /**
     * Test 13 (Test A): RECEPTION:OPERATE không bypass được quyền xóa (PARCEL:DELETE)
     */
    public function test_13_test_a_reception_operate_does_not_bypass_delete(): void
    {
        $parcel = $this->createSampleParcel();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'PARCEL:VIEW',
            'RECEPTION:OPERATE',
        ]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        $freshParcel = Parcel::find($parcel->id);
        $this->assertNotNull($freshParcel);
        $this->assertEquals('RECEIVED_AT_RECEPTION', $freshParcel->status);
    }

    /**
     * Test 14 (Test B): RECEPTION:OPERATE không bypass được quyền cập nhật (PARCEL:UPDATE)
     */
    public function test_14_test_b_reception_operate_does_not_bypass_update(): void
    {
        $parcel = $this->createSampleParcel(['recipient_name' => 'Tên Gốc B']);
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'PARCEL:VIEW',
            'RECEPTION:OPERATE',
        ]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->putJson("/api/v1/reception/parcels/{$parcel->id}", [
                'recipient_name' => 'Tên Thay Đổi Trái Phép',
            ])
            ->assertStatus(403);

        $freshParcel = Parcel::find($parcel->id);
        $this->assertEquals('Tên Gốc B', $freshParcel->recipient_name);
    }

    /**
     * Test 15 (Test C): RECEPTION:OPERATE không bypass được quyền bàn giao (PARCEL:CHECKOUT)
     */
    public function test_15_test_c_reception_operate_does_not_bypass_checkout(): void
    {
        $parcel = $this->createSampleParcel(['status' => 'RECEIVED_AT_RECEPTION']);
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'PARCEL:VIEW',
            'RECEPTION:OPERATE',
        ]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])
            ->assertStatus(403);

        $freshParcel = Parcel::find($parcel->id);
        $this->assertEquals('RECEIVED_AT_RECEPTION', $freshParcel->status);
        $this->assertNull($freshParcel->checkout_staff_id);
        $this->assertNull($freshParcel->collected_at);
    }

    /**
     * Test 16 (Test D): Unknown Action bị từ chối Fail-Closed với HTTP 403 (không nhận VIEW)
     */
    public function test_16_test_d_unknown_action_is_rejected_fail_closed_with_403(): void
    {
        [$user] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);

        try {
            $this->service->assertStaffAuthorized($user, 'unknown_action');
            $this->fail('Expected HttpException 403 for unknown action');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('không hợp lệ', $e->getMessage());
        }
    }

    /**
     * Test 17 (Test E): Action typo bị từ chối tuyệt đối không có fallback permission
     */
    public function test_17_test_e_action_typo_is_rejected_without_fallback(): void
    {
        [$user] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'PARCEL:VIEW',
            'PARCEL:CHECKOUT',
            'PARCEL:RECEIVE',
            'PARCEL:UPDATE',
            'PARCEL:DELETE',
        ]);

        $typos = ['check_out', 'hand_over', 'view_all', 'delete_parcel', 'checkoutt', 'RECEIVE'];

        foreach ($typos as $typo) {
            try {
                $this->service->assertStaffAuthorized($user, $typo);
                $this->fail("Expected HttpException 403 for action typo '{$typo}'");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertStringContainsString('không hợp lệ', $e->getMessage());
            }
        }
    }

    /**
     * Test 18 (Test F): Vai trò RECEPTIONIST mặc định theo Seeder KHÔNG có quyền DELETE
     */
    public function test_18_test_f_receptionist_role_from_seeder_has_no_delete_permission(): void
    {
        $parcel = $this->createSampleParcel();
        [$user, $token] = $this->createStaffWithSeededRole('RECEPTIONIST');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // 1. Kiểm tra permission thực tế trong DB: role RECEPTIONIST không được cấp PARCEL:DELETE
        $hasDelete = DB::table('user_roles')
            ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->where('permissions.permission_code', 'PARCEL:DELETE')
            ->exists();

        $this->assertFalse($hasDelete, 'Vai trò RECEPTIONIST mặc định không được có quyền PARCEL:DELETE theo Least Privilege.');

        // 2. Thao tác xóa qua API bị từ chối 403
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        $this->assertNotNull(Parcel::find($parcel->id));
    }

    /**
     * Test 19 (Test G): Thu hồi quyền có hiệu lực thực tế ngay cả khi user có RECEPTION:OPERATE
     */
    public function test_19_test_g_permission_revocation_on_seeded_receptionist_takes_real_effect(): void
    {
        $parcel = $this->createSampleParcel(['status' => 'RECEIVED_AT_RECEPTION']);
        [$user, $token, $roleId] = $this->createStaffWithSeededRole('RECEPTIONIST');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // 1. Xác nhận tài khoản được cấp RECEPTION:OPERATE từ Seeder
        $hasOperate = DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.permission_code', 'RECEPTION:OPERATE')
            ->exists();
        $this->assertTrue($hasOperate, 'RECEPTIONIST từ seeder có quyền RECEPTION:OPERATE.');

        // 2. Thu hồi quyền PARCEL:CHECKOUT khỏi role thực tế này
        $checkoutPermId = DB::table('permissions')->where('permission_code', 'PARCEL:CHECKOUT')->value('id');
        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $checkoutPermId)
            ->delete();

        // 3. Dù vẫn còn RECEPTION:OPERATE, thao tác checkout lập tức bị từ chối 403
        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => $parcel->pickup_pin_code,
            ])
            ->assertStatus(403);

        // 4. Kiểm tra dữ liệu kiện hàng vẫn còn nguyên vẹn
        $freshParcel = Parcel::find($parcel->id);
        $this->assertEquals('RECEIVED_AT_RECEPTION', $freshParcel->status);
        $this->assertNull($freshParcel->checkout_staff_id);
    }

    /**
     * Test 20 (Test H): Kiểm tra các permissions độc lập được thực thi nghiêm ngặt
     */
    public function test_20_test_h_independent_permissions_enforced_strictly(): void
    {
        $parcel = $this->createSampleParcel();

        // 1. Chỉ VIEW không được RECEIVE, CREATE, CHECKOUT, UPDATE, DELETE
        [$userView, $tokView] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);
        $hdrView = ['Authorization' => "Bearer {$tokView}", 'Accept' => 'application/json'];

        $this->withHeaders($hdrView)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'V-TRK',
                'courier_company' => 'GHN',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Test',
                'recipient_phone' => '0912345678',
            ])->assertStatus(403);

        try {
            $this->service->assertStaffAuthorized($userView, 'create');
            $this->fail('Expected 403 for action create with only PARCEL:VIEW');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 2. Chỉ RECEIVE không tự động có CREATE
        [$userRecv] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:RECEIVE']);
        try {
            $this->service->assertStaffAuthorized($userRecv, 'create');
            $this->fail('Expected 403 for action create with only PARCEL:RECEIVE');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 3. Chỉ CREATE không tự động có RECEIVE
        [$userCreate, $tokCreate] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:CREATE']);
        try {
            $this->service->assertStaffAuthorized($userCreate, 'receive');
            $this->fail('Expected 403 for action receive with only PARCEL:CREATE');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->withHeaders(['Authorization' => "Bearer {$tokCreate}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'C-TRK',
                'courier_company' => 'GHN',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Test',
                'recipient_phone' => '0912345678',
            ])->assertStatus(403);

        // 4. Chỉ CHECKOUT không được UPDATE hoặc DELETE
        [$userChk, $tokChk] = $this->createStaffWithPermissions('SECURITY_GUARD', ['PARCEL:CHECKOUT']);
        $hdrChk = ['Authorization' => "Bearer {$tokChk}", 'Accept' => 'application/json'];

        $this->withHeaders($hdrChk)
            ->putJson("/api/v1/reception/parcels/{$parcel->id}", ['recipient_name' => 'Bad'])
            ->assertStatus(403);

        $this->withHeaders($hdrChk)
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);

        // 5. Chỉ UPDATE không được DELETE
        [$userUpd, $tokUpd] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:UPDATE']);
        $this->withHeaders(['Authorization' => "Bearer {$tokUpd}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/reception/parcels/{$parcel->id}")
            ->assertStatus(403);
    }

    /**
     * Test 21 (Test I): Super Admin bypass quyền hạn sạch sẽ trên toàn bộ phân hệ
     */
    public function test_21_test_i_super_admin_bypasses_all_parcel_permissions_cleanly(): void
    {
        // 1. SUPER_ADMIN bypasses all
        [$admin] = $this->createStaffWithPermissions('SUPER_ADMIN', []);
        $actions = ['view', 'lookup', 'receive', 'create', 'checkout', 'handover', 'update', 'delete'];
        foreach ($actions as $act) {
            $this->service->assertStaffAuthorized($admin, $act);
        }

        // 2. SUPER_ADMI alias bypasses all
        [$superAdmi] = $this->createStaffWithPermissions('SUPER_ADMI', []);
        foreach ($actions as $act) {
            $this->service->assertStaffAuthorized($superAdmi, $act);
        }

        // 3. User thông thường hoặc staff không có permission không thể bypass
        [$normalStaff] = $this->createStaffWithPermissions('STAFF', []);
        foreach ($actions as $act) {
            try {
                $this->service->assertStaffAuthorized($normalStaff, $act);
                $this->fail("Expected 403 for staff without permissions on action {$act}");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    /**
     * Test 22: Strict Bearer Authentication - Các kịch bản xác thực chặt chẽ trên /api/v1/reception/parcels
     */
    public function test_22_strict_bearer_authentication_matrix_on_parcel_routes(): void
    {
        // 1. Không có token, không có session -> 401
        $this->getJson('/api/v1/reception/parcels')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);

        // 2. Có session cookie hợp lệ nhưng KHÔNG có Bearer token -> BẮT BUỘC TRẢ 401 (Chặn hoàn toàn Session Fallback)
        [$staffUser, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);
        $this->actingAs($staffUser, 'web')
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);

        // 3. Bearer token giả mạo/không tồn tại -> 401
        $this->withHeaders(['Authorization' => 'Bearer fake_invalid_token_xyz', 'Accept' => 'application/json'])
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);

        // 4. Bearer token đã hết hạn (expires_at in past) -> 401 TOKEN_EXPIRED
        $expiredToken = 'expired_token_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $staffUser->id,
            'refresh_token_hash' => hash('sha256', $expiredToken),
            'device_name' => 'Expired Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$expiredToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'TOKEN_EXPIRED',
            ]);

        // 5. Bearer token đã bị thu hồi (is_revoked = 1) -> 401 TOKEN_REVOKED
        $revokedToken = 'revoked_token_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $staffUser->id,
            'refresh_token_hash' => hash('sha256', $revokedToken),
            'device_name' => 'Revoked Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 1,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$revokedToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'TOKEN_REVOKED',
            ]);

        // 6. Bearer token hợp lệ và có quyền PARCEL:VIEW -> 200 Thành công
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/reception/parcels')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // 7. Bearer token hợp lệ nhưng thiếu quyền PARCEL:VIEW -> 403 Forbidden trên các action nghiệp vụ
        [$staffNoPerm, $tokenNoPerm] = $this->createStaffWithPermissions('RECEPTIONIST', []);
        $hdrNoPerm = ['Authorization' => "Bearer {$tokenNoPerm}", 'Accept' => 'application/json'];
        $this->withHeaders($hdrNoPerm)->getJson('/api/v1/reception/parcels/lookup?code=123456')->assertStatus(403);
        $this->withHeaders($hdrNoPerm)->getJson('/api/v1/reception/parcels/recipients/search')->assertStatus(403);
        $this->withHeaders($hdrNoPerm)->getJson('/api/v1/reception/parcels/apartments/search')->assertStatus(403);
        $this->withHeaders($hdrNoPerm)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => 'NO-PERM-TRK',
            'courier_company' => 'GHN',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Test',
            'recipient_phone' => '0912345678',
        ])->assertStatus(403);
    }

    /**
     * Test 23: Realtime Authorization - Kênh quoc-tin.parcels yêu cầu PARCEL:VIEW, từ chối RECEPTION:OPERATE
     */
    public function test_23_realtime_parcel_channel_requires_parcel_view_rejects_reception_operate(): void
    {
        // 1. Nhân viên có PARCEL:VIEW -> Được phép truy cập cả WebSocket và SSE
        [$staffWithView] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW']);
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffWithView, 'quoc-tin.parcels'),
            'Nhân viên có PARCEL:VIEW phải subscribe được WebSocket quoc-tin.parcels'
        );
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($staffWithView, 'quoc-tin.parcels'),
            'Nhân viên có PARCEL:VIEW phải truy cập được SSE quoc-tin.parcels'
        );

        // 2. Nhân viên chỉ có RECEPTION:OPERATE (không có PARCEL:VIEW) -> BỊ TỪ CHỐI CẢ WEBSOCKET VÀ SSE
        [$staffOnlyOperate] = $this->createStaffWithPermissions('RECEPTIONIST', ['RECEPTION:OPERATE']);
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffOnlyOperate, 'quoc-tin.parcels'),
            'Nhân viên chỉ có RECEPTION:OPERATE KHÔNG ĐƯỢC subscribe WebSocket quoc-tin.parcels'
        );
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($staffOnlyOperate, 'quoc-tin.parcels'),
            'Nhân viên chỉ có RECEPTION:OPERATE KHÔNG ĐƯỢC truy cập SSE quoc-tin.parcels'
        );

        // 3. Nhân viên không có cả hai permission -> BỊ TỪ CHỐI
        [$staffNoPerm] = $this->createStaffWithPermissions('RECEPTIONIST', []);
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffNoPerm, 'quoc-tin.parcels'),
            'Nhân viên không có quyền bị từ chối WebSocket quoc-tin.parcels'
        );
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($staffNoPerm, 'quoc-tin.parcels'),
            'Nhân viên không có quyền bị từ chối SSE quoc-tin.parcels'
        );

        // 4. Nhân viên bị thu hồi PARCEL:VIEW nhưng còn RECEPTION:OPERATE -> VẪN BỊ TỪ CHỐI
        [$staffRevoked, , $roleId] = $this->createStaffWithPermissions('RECEPTIONIST', ['PARCEL:VIEW', 'RECEPTION:OPERATE']);
        $this->assertTrue(QuocTinRealtimeService::isUserAuthorizedForChannel($staffRevoked, 'quoc-tin.parcels'));

        // Thu hồi PARCEL:VIEW trong CSDL
        $viewPermId = DB::table('permissions')->where('permission_code', 'PARCEL:VIEW')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $viewPermId)->delete();

        // Nạp lại instance User mới để xóa bộ nhớ cache permissions trên Model
        $staffRevokedFresh = User::find($staffRevoked->id);

        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffRevokedFresh, 'quoc-tin.parcels'),
            'Nhân viên bị thu hồi PARCEL:VIEW phải bị từ chối WebSocket quoc-tin.parcels dù còn RECEPTION:OPERATE'
        );
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($staffRevokedFresh, 'quoc-tin.parcels'),
            'Nhân viên bị thu hồi PARCEL:VIEW phải bị từ chối SSE quoc-tin.parcels dù còn RECEPTION:OPERATE'
        );
    }

    /**
     * Test 24: Realtime Payload Privacy - Không bao giờ phát tán pickup_pin_code hoặc secrets qua Realtime
     */
    public function test_24_realtime_payload_never_broadcasts_pickup_pin_code(): void
    {
        [$staffUser, $token] = $this->createStaffWithPermissions('BUILDING_MANAGER', ['PARCEL:VIEW', 'PARCEL:RECEIVE', 'PARCEL:CREATE', 'PARCEL:UPDATE']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $parcel = $this->createSampleParcel([
            'pickup_pin_code' => '987654',
            'pickup_qr_code' => 'QR-SECRET-9999',
            'received_at' => now()->subDays(6), // Quá hạn để có thể gọi remind
        ]);

        $lastEventIdBefore = (int) (DB::table('realtime_sync_events')->max('id') ?? 0);

        // 1. Gọi remind bưu phẩm
        $this->withHeaders($headers)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/remind")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // 2. Kiểm tra sự kiện được lưu trong realtime_sync_events
        $newEvents = DB::table('realtime_sync_events')
            ->where('id', '>', $lastEventIdBefore)
            ->where('channel', 'quoc-tin.parcels')
            ->get();

        $this->assertNotEmpty($newEvents, 'Phải có sự kiện realtime được phát');

        foreach ($newEvents as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];

            // Xác minh tuyệt đối không rò rỉ mã PIN hoặc QR code
            $this->assertArrayNotHasKey('pickup_pin_code', $payload, 'Payload realtime không được chứa pickup_pin_code');
            $this->assertArrayNotHasKey('pickup_qr_code', $payload, 'Payload realtime không được chứa pickup_qr_code');
            $this->assertArrayNotHasKey('pin_code', $payload, 'Payload realtime không được chứa pin_code');
            $this->assertArrayNotHasKey('qr_code', $payload, 'Payload realtime không được chứa qr_code');

            $rawPayloadString = (string) $row->payload;
            $this->assertStringNotContainsString('987654', $rawPayloadString, 'Raw payload JSON không được chứa giá trị mã PIN bí mật');
            $this->assertStringNotContainsString('QR-SECRET-9999', $rawPayloadString, 'Raw payload JSON không được chứa giá trị mã QR bí mật');
        }
    }

    /**
     * Test 25: Realtime Resident Isolation - Cư dân chỉ nhận event của căn hộ mình, không đọc được căn khác
     */
    public function test_25_realtime_resident_tenant_isolation(): void
    {
        // 1. Tạo 2 căn hộ khác nhau
        $aptA = $this->apartment;
        $aptB = $this->createApartment('APT-ISOLATION-B-'.Str::random(4));

        // 2. Tạo cư dân A thuộc căn A
        $resUserA = $this->createResidentUser();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $resUserA->id,
            'apartment_id' => $aptA->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subMonths(1)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Tạo cư dân B thuộc căn B
        $resUserB = $this->createResidentUser();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $resUserB->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subMonths(1)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cư dân bị từ chối subscribe WebSocket quoc-tin.parcels (chỉ dành cho nhân viên)
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($resUserA, 'quoc-tin.parcels'),
            'Cư dân không được phép kết nối WebSocket quoc-tin.parcels'
        );

        // Nhưng cư dân hợp lệ được phép nghe SSE quoc-tin.parcels (được lọc qua server-side)
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($resUserA, 'quoc-tin.parcels'),
            'Cư dân hợp lệ được phép kết nối SSE quoc-tin.parcels'
        );

        $lastIdBefore = (int) (DB::table('realtime_sync_events')->max('id') ?? 0);

        // Phát 1 event cho căn A và 1 event cho căn B
        QuocTinRealtimeService::emit(
            module: 'parcels',
            entity: 'parcel',
            action: 'CREATED',
            entityId: (string) Str::uuid(),
            extra: [
                'apartment_id' => $aptA->id,
                'apartment_number' => $aptA->apartment_number,
                'tracking_number' => 'TRK-FOR-APTA',
            ]
        );

        QuocTinRealtimeService::emit(
            module: 'parcels',
            entity: 'parcel',
            action: 'CREATED',
            entityId: (string) Str::uuid(),
            extra: [
                'apartment_id' => $aptB->id,
                'apartment_number' => $aptB->apartment_number,
                'tracking_number' => 'TRK-FOR-APTB',
            ]
        );

        // 4. Lấy sự kiện cho cư dân A -> chỉ nhận sự kiện căn A, tuyệt đối không có căn B
        $eventsForA = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $resUserA);
        $this->assertNotEmpty($eventsForA);
        $trackingNumbersA = array_column($eventsForA, 'tracking_number');
        $this->assertContains('TRK-FOR-APTA', $trackingNumbersA);
        $this->assertNotContains('TRK-FOR-APTB', $trackingNumbersA);

        // 5. Lấy sự kiện cho cư dân B -> chỉ nhận sự kiện căn B, tuyệt đối không có căn A
        $eventsForB = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $resUserB);
        $this->assertNotEmpty($eventsForB);
        $trackingNumbersB = array_column($eventsForB, 'tracking_number');
        $this->assertContains('TRK-FOR-APTB', $trackingNumbersB);
        $this->assertNotContains('TRK-FOR-APTA', $trackingNumbersB);
    }

    /**
     * Test 26: ADMIN không có quyền PARCEL:VIEW và không có RECEPTION:OPERATE bị từ chối WebSocket, SSE và Polling
     */
    public function test_26_admin_without_parcel_view_denied_realtime_access(): void
    {
        [$adminNoPerm, $tokenNoPerm] = $this->createStaffWithPermissions('ADMIN', []);
        $headers = ['Authorization' => "Bearer {$tokenNoPerm}", 'Accept' => 'application/json'];

        // 1. WebSocket bị từ chối
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminNoPerm, 'quoc-tin.parcels'),
            'ADMIN không có PARCEL:VIEW phải bị từ chối kết nối WebSocket quoc-tin.parcels'
        );

        // 2. SSE bị từ chối
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminNoPerm, 'quoc-tin.parcels'),
            'ADMIN không có PARCEL:VIEW phải bị từ chối kết nối SSE quoc-tin.parcels'
        );

        // 3. HTTP Polling trả về 403 Forbidden
        $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.parcels')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // 4. SSE Stream endpoint trả về 403 Forbidden
        $this->withHeaders($headers)
            ->get('/api/realtime/stream?channels=quoc-tin.parcels')
            ->assertStatus(403);
    }

    /**
     * Test 27: ADMIN chỉ có RECEPTION:OPERATE (không có PARCEL:VIEW) vẫn bị từ chối WebSocket, SSE và Polling
     */
    public function test_27_admin_only_reception_operate_denied_realtime_access(): void
    {
        [$adminOperate, $tokenOperate] = $this->createStaffWithPermissions('ADMIN', ['RECEPTION:OPERATE']);
        $headers = ['Authorization' => "Bearer {$tokenOperate}", 'Accept' => 'application/json'];

        // 1. WebSocket bị từ chối
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminOperate, 'quoc-tin.parcels'),
            'ADMIN chỉ có RECEPTION:OPERATE không được phép vào WebSocket quoc-tin.parcels'
        );

        // 2. SSE bị từ chối
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminOperate, 'quoc-tin.parcels'),
            'ADMIN chỉ có RECEPTION:OPERATE không được phép vào SSE quoc-tin.parcels'
        );

        // 3. HTTP Polling trả về 403 Forbidden
        $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.parcels')
            ->assertStatus(403);
    }

    /**
     * Test 28: ADMIN có PARCEL:VIEW được phép truy cập WebSocket, SSE và Polling mà không cần RECEPTION:OPERATE
     */
    public function test_28_admin_with_parcel_view_allowed_realtime_access(): void
    {
        [$adminView, $tokenView] = $this->createStaffWithPermissions('ADMIN', ['PARCEL:VIEW']);
        $headers = ['Authorization' => "Bearer {$tokenView}", 'Accept' => 'application/json'];

        // 1. WebSocket được phép
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminView, 'quoc-tin.parcels'),
            'ADMIN có PARCEL:VIEW phải được phép kết nối WebSocket quoc-tin.parcels'
        );

        // 2. SSE được phép
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminView, 'quoc-tin.parcels'),
            'ADMIN có PARCEL:VIEW phải được phép kết nối SSE quoc-tin.parcels'
        );

        // 3. HTTP Polling thành công HTTP 200
        $response = $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.parcels');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Test 29: SUPER_ADMIN không có PARCEL:VIEW trực tiếp vẫn được bypass hợp lệ trên WebSocket, SSE và Polling
     */
    public function test_29_super_admin_bypasses_realtime_parcel_without_parcel_view(): void
    {
        [$superAdmin, $saToken] = $this->createStaffWithPermissions('SUPER_ADMIN', []);
        $headers = ['Authorization' => "Bearer {$saToken}", 'Accept' => 'application/json'];

        // 1. WebSocket được bypass
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($superAdmin, 'quoc-tin.parcels'),
            'SUPER_ADMIN phải được bypass trên WebSocket quoc-tin.parcels'
        );

        // 2. SSE được bypass
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($superAdmin, 'quoc-tin.parcels'),
            'SUPER_ADMIN phải được bypass trên SSE quoc-tin.parcels'
        );

        // 3. HTTP Polling thành công HTTP 200
        $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.parcels')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Test 30: ADMIN đang có PARCEL:VIEW bị thu hồi quyền trong CSDL -> Ngay lập tức bị từ chối WebSocket, SSE và Polling
     */
    public function test_30_admin_permission_revocation_blocks_realtime_parcel_immediately(): void
    {
        [$adminUser, $token, $roleId] = $this->createStaffWithPermissions('ADMIN', ['PARCEL:VIEW']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Ban đầu có quyền
        $this->assertTrue(QuocTinRealtimeService::isUserAuthorizedForChannel($adminUser, 'quoc-tin.parcels'));
        $this->assertTrue(QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminUser, 'quoc-tin.parcels'));

        // Thu hồi PARCEL:VIEW trong CSDL
        $permId = DB::table('permissions')->where('permission_code', 'PARCEL:VIEW')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permId)->delete();

        // Nạp lại instance User mới từ CSDL
        $freshAdmin = User::find($adminUser->id);

        // Ngay lập tức bị từ chối trên toàn bộ các kênh
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($freshAdmin, 'quoc-tin.parcels'),
            'ADMIN bị thu hồi PARCEL:VIEW phải lập tức bị chặn WebSocket quoc-tin.parcels'
        );
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($freshAdmin, 'quoc-tin.parcels'),
            'ADMIN bị thu hồi PARCEL:VIEW phải lập tức bị chặn SSE quoc-tin.parcels'
        );
        $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.parcels')
            ->assertStatus(403);
    }

    /**
     * Test 31: ADMIN không có PARCEL:VIEW vẫn truy cập được các kênh Realtime khác bình thường (Không gây Regression)
     */
    public function test_31_admin_without_parcel_view_can_still_access_other_realtime_channels(): void
    {
        [$adminNoParcel, $token] = $this->createStaffWithPermissions('ADMIN', []);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Các kênh khác vẫn được bypass theo quyền ADMIN toàn cục
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminNoParcel, 'quoc-tin.vehicles'),
            'ADMIN vẫn được phép truy cập WebSocket quoc-tin.vehicles'
        );
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminNoParcel, 'quoc-tin.visitors'),
            'ADMIN vẫn được phép truy cập WebSocket quoc-tin.visitors'
        );
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($adminNoParcel, 'quoc-tin.residents'),
            'ADMIN vẫn được phép truy cập WebSocket quoc-tin.residents'
        );
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminNoParcel, 'quoc-tin.vehicles'),
            'ADMIN vẫn được phép truy cập SSE quoc-tin.vehicles'
        );
        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForSseChannel($adminNoParcel, 'quoc-tin.visitors'),
            'ADMIN vẫn được phép truy cập SSE quoc-tin.visitors'
        );

        // Gọi polling các kênh khác thành công 200
        $this->withHeaders($headers)
            ->getJson('/api/realtime/events?channels=quoc-tin.vehicles')
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
