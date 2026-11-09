<?php

namespace Tests\Feature\Invoices;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceAndBuildingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Block $blockA;

    protected Floor $floor1;

    protected Apartment $apt101;

    protected Apartment $apt102;

    protected Invoice $invoice101;

    protected Invoice $invoice102;

    protected User $residentUser101;

    protected User $residentUser102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        // Chuẩn bị dữ liệu cơ sở tòa nhà & căn hộ
        $this->blockA = Block::create([
            'block_code' => 'BLK-A',
            'block_name' => 'Tòa Tháp A',
            'total_floors' => 20,
            'total_apartments' => 80,
        ]);

        $this->floor1 = Floor::create([
            'block_id' => $this->blockA->id,
            'floor_code' => 'FL-01',
            'floor_number' => 1,
            'floor_name' => 'Tầng 1',
        ]);

        $this->residentUser101 = User::create([
            'full_name' => 'Cư Dân A101 Mẫu',
            'email' => 'resident_a101_fixture@cassavas.com',
            'phone' => '0901111111',
            'password' => bcrypt('password123'),
            'status' => 'ACTIVE',
        ]);

        $this->residentUser102 = User::create([
            'full_name' => 'Cư Dân A102 Mẫu',
            'email' => 'resident_a102_fixture@cassavas.com',
            'phone' => '0902222222',
            'password' => bcrypt('password123'),
            'status' => 'ACTIVE',
        ]);

        $this->apt101 = Apartment::create([
            'block_id' => $this->blockA->id,
            'floor_id' => $this->floor1->id,
            'apartment_number' => 'A-101',
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'current_resident_user_id' => $this->residentUser101->id,
            'status' => 'OCCUPIED',
        ]);

        $this->apt102 = Apartment::create([
            'block_id' => $this->blockA->id,
            'floor_id' => $this->floor1->id,
            'apartment_number' => 'A-102',
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 78.0,
            'current_resident_user_id' => $this->residentUser102->id,
            'status' => 'OCCUPIED',
        ]);

        // Chuẩn bị hóa đơn mẫu
        $this->invoice101 = Invoice::create([
            'invoice_number' => 'HD-202611-A-101',
            'apartment_id' => $this->apt101->id,
            'resident_user_id' => $this->residentUser101->id,
            'billing_period' => '2026-11',
            'issue_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'subtotal_amount' => 1000000,
            'tax_amount' => 100000,
            'total_amount' => 1100000,
            'paid_amount' => 0,
            'remaining_balance' => 1100000,
            'status' => 'ISSUED',
        ]);

        $this->invoice102 = Invoice::create([
            'invoice_number' => 'HD-202611-A-102',
            'apartment_id' => $this->apt102->id,
            'resident_user_id' => $this->residentUser102->id,
            'billing_period' => '2026-11',
            'issue_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'subtotal_amount' => 2000000,
            'tax_amount' => 200000,
            'total_amount' => 2200000,
            'paid_amount' => 0,
            'remaining_balance' => 2200000,
            'status' => 'ISSUED',
        ]);
    }

    /**
     * Tạo tài khoản người dùng test kèm vai trò, danh sách permission tùy biến và phiên token hợp lệ
     *
     * @param  array<string>  $permissions
     */
    protected function createTestUserWithPermissions(string $roleCode, array $permissions = []): array
    {
        $user = User::create([
            'username' => 'auth_user_'.Str::random(8),
            'email' => 'user_'.Str::random(8).'@test.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'password_hash' => Hash::make('Password@123'),
            'full_name' => 'Tester '.$roleCode,
            'status' => 'ACTIVE',
        ]);

        $role = Role::firstOrCreate(['role_code' => $roleCode], ['role_name' => 'Role '.$roleCode, 'is_system_role' => false]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        // Gán các permission chỉ định cho vai trò riêng của user
        if (! empty($permissions)) {
            $customRoleCode = 'CUSTOM_USER_'.substr(str_replace('-', '', $user->id), 0, 12);
            $customRole = Role::create([
                'role_code' => $customRoleCode,
                'role_name' => 'Custom Role '.$user->username,
                'is_system_role' => false,
            ]);

            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $customRole->id,
                'is_primary' => 0,
                'assigned_at' => now(),
            ]);

            foreach ($permissions as $code) {
                $perm = Permission::firstOrCreate(
                    ['permission_code' => $code],
                    [
                        'id' => (string) Str::uuid(),
                        'module' => explode(':', $code)[0],
                        'permission_name' => 'Quyền '.$code,
                        'created_at' => now(),
                    ]
                );

                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $customRole->id,
                    'permission_id' => $perm->id,
                    'created_at' => now(),
                ]);
            }
        }

        // Tạo phiên hợp lệ trong user_sessions
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
     * Tạo tài khoản cư dân liên kết với căn hộ
     *
     * @param  array<string>  $permissions
     */
    protected function createResidentWithApartment(string $apartmentId, array $permissions = ['INVOICE:VIEW']): array
    {
        [$user, $token] = $this->createTestUserWithPermissions('RESIDENT_OWNER', $permissions);

        Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apartmentId,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'is_active' => true,
            'stay_start_date' => '2026-01-01',
        ]);

        return [$user, $token];
    }

    /**
     * 1. Token giả hoặc chưa đăng nhập bị từ chối 401 Unauthorized
     */
    public function test_01_unauthenticated_or_fake_token_returns_401(): void
    {
        // Không có token
        $this->getJson('/api/v1/invoices')->assertStatus(401);
        $this->getJson('/api/v1/blocks')->assertStatus(401);

        // Token giả
        $this->withHeader('Authorization', 'Bearer fake_invalid_token_12345')
            ->getJson('/api/v1/invoices')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer fake_invalid_token_12345')
            ->getJson('/api/v1/blocks')
            ->assertStatus(401);
    }

    /**
     * 2. Quản trị viên cấp cao (SUPER_ADMIN) luôn có toàn quyền truy cập
     */
    public function test_02_super_admin_has_full_bypass_access(): void
    {
        [$admin, $token] = $this->createTestUserWithPermissions('SUPER_ADMIN');

        // Xem danh sách hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(200);

        // Xem tổng kết KPI
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices/summary')
            ->assertStatus(200);

        // Xem hạ tầng tòa nhà
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/blocks')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apartments')
            ->assertStatus(200);
    }

    /**
     * 3. Người có permission INVOICE:VIEW xem được dữ liệu hóa đơn
     */
    public function test_03_user_with_invoice_view_can_view_invoices(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:VIEW']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices');

        $res->assertStatus(200)
            ->assertJson(['success' => true]);

        $detailRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice101->id}");

        $detailRes->assertStatus(200);
    }

    /**
     * 4. Người có role nhưng KHÔNG có permission VIEW bị từ chối 403
     */
    public function test_04_user_with_role_but_without_view_permission_is_rejected(): void
    {
        // Tạo tài khoản có role BUILDING_MANAGER nhưng không có quyền INVOICE:VIEW (bị thu hồi)
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['USER:VIEW']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice101->id}")
            ->assertStatus(403);
    }

    /**
     * 5. Người chỉ có VIEW nhưng KHÔNG có CREATE không tạo được dữ liệu (403)
     */
    public function test_05_user_with_view_only_cannot_generate_or_preview_batch_invoices(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:VIEW']);

        // Gọi preview sinh hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/preview', [
                'billing_period' => '2026-12',
            ])
            ->assertStatus(403);

        // Gọi sinh hóa đơn hàng loạt
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/generate', [
                'billing_period' => '2026-12',
            ])
            ->assertStatus(403);
    }

    /**
     * 6. Người có permission INVOICE:CREATE thực hiện được preview và generate hóa đơn
     */
    public function test_06_user_with_invoice_create_can_preview_and_generate_invoices(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:VIEW', 'INVOICE:CREATE']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/preview', [
                'billing_period' => '2026-12',
            ]);

        $res->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 7. Người không có permission INVOICE:DELETE không hủy được hóa đơn (403)
     */
    public function test_07_user_without_invoice_delete_cannot_cancel_invoices(): void
    {
        // Có role ACCOUNTANT và quyền VIEW, UPDATE, APPROVE nhưng KHÔNG có DELETE
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:VIEW', 'INVOICE:UPDATE', 'INVOICE:APPROVE']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel", [
                'reason' => 'Thử hủy hóa đơn trái phép',
            ])
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/bulk-cancel', [
                'invoice_ids' => [$this->invoice101->id],
            ])
            ->assertStatus(403);

        // Đảm bảo trạng thái hóa đơn trong database không bị thay đổi
        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice101->id,
            'status' => 'ISSUED',
        ]);
    }

    /**
     * 8. Người không có INVOICE:UPDATE / INVOICE:APPROVE không gửi được nhắc nợ (403)
     */
    public function test_08_user_without_invoice_update_or_approve_cannot_send_debt_reminders(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('RECEPTIONIST', ['INVOICE:VIEW']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/bulk-send-reminders', [
                'invoice_ids' => [$this->invoice101->id],
            ])
            ->assertStatus(403);
    }

    /**
     * 9. Data Scoping: Cư dân căn hộ A-101 không thể xem hóa đơn căn hộ A-102 (403)
     */
    public function test_09_resident_cannot_view_another_apartment_invoice_detail_or_statement(): void
    {
        // Cư dân chỉ ở căn hộ A-101
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['INVOICE:VIEW']);

        // Xem hóa đơn của chính mình A-101 -> 200 OK
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice101->id}")
            ->assertStatus(200);

        // Xem sao kê của chính mình A-101 -> 200 OK
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice101->id}/statement")
            ->assertStatus(200);

        // Thử xem hóa đơn căn hộ A-102 -> 403 FORBIDDEN
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice102->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                'error' => 'FORBIDDEN',
            ]);

        // Thử xem sao kê căn hộ A-102 -> 403 FORBIDDEN
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice102->id}/statement")
            ->assertStatus(403);
    }

    /**
     * 10. Data Scoping: Cư dân không thể gọi current-month với apartment_id của căn hộ khác (403)
     */
    public function test_10_resident_cannot_access_another_apartment_current_month_invoice(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['INVOICE:VIEW']);

        // Gọi với căn hộ A-101 của mình -> 200 OK
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/current-month?apartment_id={$this->apt101->id}")
            ->assertStatus(200);

        // Gọi với căn hộ A-102 của người khác -> 403 FORBIDDEN
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/current-month?apartment_id={$this->apt102->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 11. Cư dân tuyệt đối không thể hủy hoặc sinh hóa đơn (403)
     */
    public function test_11_resident_cannot_cancel_or_generate_invoices(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['INVOICE:VIEW', 'INVOICE:DELETE', 'INVOICE:CREATE']);

        // Thử hủy hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel", ['reason' => 'Test'])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền hủy hóa đơn.',
                'error' => 'FORBIDDEN',
            ]);

        // Thử sinh hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/generate', ['billing_period' => '2026-12'])
            ->assertStatus(403);
    }

    /**
     * 12. Hạ tầng tòa nhà: Người có BUILDING:VIEW xem được danh sách tòa nhà và tầng
     */
    public function test_12_building_structure_view_permission_can_view_floors_and_blocks(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:VIEW']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/blocks')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/blocks/{$this->blockA->id}/floors")
            ->assertStatus(200);
    }

    /**
     * 13. Hạ tầng tòa nhà: Người chỉ có BUILDING:VIEW không được tạo, sửa hoặc xóa tầng (403)
     */
    public function test_13_user_with_building_view_only_cannot_create_update_or_delete_floors(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:VIEW']);

        // Tạo tầng -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 2,
                'floor_name' => 'Tầng 2',
            ])
            ->assertStatus(403);

        // Sửa tầng -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/floors/{$this->floor1->id}", [
                'floor_name' => 'Tầng 1 Updated',
            ])
            ->assertStatus(403);

        // Xóa tầng -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/floors/{$this->floor1->id}")
            ->assertStatus(403);
    }

    /**
     * 14. Hạ tầng tòa nhà: Người có BUILDING:MANAGE được tạo, sửa và xóa tầng
     */
    public function test_14_user_with_building_manage_permission_can_create_update_delete_floors(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:MANAGE']);

        // Tạo tầng mới
        $createRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 99,
                'floor_name' => 'Tầng 99 Test',
            ]);

        $createRes->assertStatus(201);
        $newFloorId = $createRes->json('data.id');

        // Sửa tầng
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/floors/{$newFloorId}", [
                'floor_name' => 'Tầng 99 Updated',
            ])
            ->assertStatus(200);

        // Xóa tầng
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/floors/{$newFloorId}?force=true")
            ->assertStatus(200);
    }

    /**
     * 15. Căn hộ: Người chỉ có APARTMENT:VIEW không thể tạo hoặc xóa căn hộ (403)
     */
    public function test_15_user_with_apartment_view_only_cannot_create_or_delete_apartments(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['APARTMENT:VIEW']);

        // Xem danh sách căn hộ -> 200 OK
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apartments')
            ->assertStatus(200);

        // Tạo căn hộ -> 403 FORBIDDEN
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-999',
                'floor_id' => $this->floor1->id,
            ])
            ->assertStatus(403);

        // Xóa căn hộ -> 403 FORBIDDEN
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$this->apt102->id}")
            ->assertStatus(403);
    }

    /**
     * 16. Căn hộ: Người có APARTMENT:MANAGE tạo và xóa căn hộ thành công
     */
    public function test_16_user_with_apartment_manage_can_create_and_delete_apartments(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['APARTMENT:MANAGE']);

        // Tạo căn hộ mới
        $createRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-888',
                'floor_id' => $this->floor1->id,
                'block_id' => $this->blockA->id,
            ]);

        $createRes->assertStatus(201);
        $newAptId = $createRes->json('data.id');

        // Xóa căn hộ
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$newAptId}")
            ->assertStatus(200);
    }

    /**
     * 17. Database Integrity: Dữ liệu database không bị thay đổi khi request bị từ chối
     */
    public function test_17_rejected_requests_do_not_modify_database_state(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['INVOICE:VIEW']);

        // Thử hủy hóa đơn A-102
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice102->id}/cancel", ['reason' => 'Hack'])
            ->assertStatus(403);

        // Xác nhận trong database hóa đơn A-102 vẫn còn nguyên
        $freshInvoice = Invoice::find($this->invoice102->id);
        $this->assertEquals('ISSUED', $freshInvoice->status);
        $this->assertEquals(2200000, $freshInvoice->remaining_balance);
        $this->assertStringNotContainsString('Hack', (string) $freshInvoice->notes);
    }

    /**
     * 18. Logic OR: Nhân viên chỉ có INVOICE:UPDATE có thể gửi nhắc nợ thành công (202 Accepted)
     */
    public function test_18_staff_with_only_invoice_update_can_send_debt_reminder(): void
    {
        Queue::fake();
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:UPDATE']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder");

        $res->assertStatus(202)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('debt_reminder_logs', [
            'invoice_id' => $this->invoice101->id,
            'sent_by_user_id' => $user->id,
            'channel_status' => 'QUEUED',
        ]);
    }

    /**
     * 19. Logic OR: Nhân viên chỉ có INVOICE:APPROVE có thể gửi nhắc nợ thành công (202 Accepted)
     */
    public function test_19_staff_with_only_invoice_approve_can_send_debt_reminder(): void
    {
        Queue::fake();
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:APPROVE']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder");

        $res->assertStatus(202)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('debt_reminder_logs', [
            'invoice_id' => $this->invoice101->id,
            'sent_by_user_id' => $user->id,
            'channel_status' => 'QUEUED',
        ]);
    }

    /**
     * 20. Logic OR: Nhân viên có INVOICE:VIEW nhưng không có UPDATE/APPROVE bị từ chối 403
     */
    public function test_20_staff_with_view_only_cannot_send_debt_reminder(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:VIEW']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder");

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 21. RBAC Non-bypass: Có role nhân viên nhưng thiếu quyền tương ứng vẫn bị từ chối 403
     */
    public function test_21_staff_role_without_required_permissions_cannot_bypass(): void
    {
        // Nhân viên kế toán nhưng chỉ được gán quyền USER:VIEW
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['USER:VIEW']);

        // Thử gửi nhắc nợ -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(403);

        // Thử hủy hóa đơn -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel")
            ->assertStatus(403);
    }

    /**
     * 22. INVOICE:SPECIAL: Nhân viên có INVOICE:SPECIAL thực hiện được các nghiệp vụ hóa đơn
     */
    public function test_22_staff_with_invoice_special_can_perform_invoice_operations(): void
    {
        Queue::fake();
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:SPECIAL']);

        // Xem danh sách hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(200);

        // Xem chi tiết hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice101->id}")
            ->assertStatus(200);

        // Mô phỏng dự toán sinh hóa đơn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/preview', ['billing_period' => '2026-12'])
            ->assertStatus(200);

        // Gửi nhắc nợ
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(202);

        // Hủy hóa đơn mẫu riêng
        $cancelInv = Invoice::create([
            'invoice_number' => 'HD-SPEC-'.Str::random(6),
            'apartment_id' => $this->apt101->id,
            'resident_user_id' => $this->residentUser101->id,
            'billing_period' => '2026-10',
            'issue_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'subtotal_amount' => 300000,
            'tax_amount' => 30000,
            'total_amount' => 330000,
            'paid_amount' => 0,
            'remaining_balance' => 330000,
            'status' => 'ISSUED',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$cancelInv->id}/cancel", ['reason' => 'Special Cancel'])
            ->assertStatus(200);

        $this->assertEquals('CANCELLED', $cancelInv->fresh()->status);
    }

    /**
     * 23. INVOICE:SPECIAL: Cư dân có INVOICE:SPECIAL vẫn bị chặn thao tác quản trị và bảo toàn Data Scoping
     */
    public function test_23_resident_with_invoice_special_is_blocked_from_staff_actions_and_data_scoping(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['INVOICE:SPECIAL']);

        // Cư dân bị chặn hủy hóa đơn (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel", ['reason' => 'Test'])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền hủy hóa đơn.',
                'error' => 'FORBIDDEN',
            ]);

        // Cư dân bị chặn sinh hóa đơn hàng loạt (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/generate', ['billing_period' => '2026-12'])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền sinh hóa đơn hàng loạt.',
                'error' => 'FORBIDDEN',
            ]);

        // Cư dân bị chặn gửi nhắc nợ (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền gửi nhắc nợ hóa đơn.',
                'error' => 'FORBIDDEN',
            ]);

        // Data Scoping: Cư dân có INVOICE:SPECIAL không thể xem hóa đơn căn hộ khác (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice102->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 24. INVOICE:SPECIAL: Không cấp quyền chéo sang phân hệ Hạ tầng tòa nhà & Căn hộ
     */
    public function test_24_invoice_special_does_not_grant_access_to_building_structure(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:SPECIAL']);

        // Xem danh sách khối tòa nhà -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/blocks')
            ->assertStatus(403);

        // Tạo tầng mới -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 88,
                'floor_name' => 'Tầng 88',
            ])
            ->assertStatus(403);

        // Tạo căn hộ mới -> 403
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-777',
                'floor_id' => $this->floor1->id,
            ])
            ->assertStatus(403);
    }

    /**
     * 25. SYSTEM:SPECIAL: Nhân viên có SYSTEM:SPECIAL bị từ chối ở cả Hóa đơn và Hạ tầng vì không có permission tương ứng
     */
    public function test_25_staff_with_system_special_is_rejected_from_invoices_and_building(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['SYSTEM:SPECIAL']);

        // Xem khối tòa nhà (Hạ tầng) -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/blocks')
            ->assertStatus(403);

        // Tạo tầng (Hạ tầng) -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 77,
                'floor_name' => 'Tầng 77 Special',
            ])
            ->assertStatus(403);

        // Xem danh sách hóa đơn (Tài chính) -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403);

        // Dự toán sinh hóa đơn -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/batch/preview', ['billing_period' => '2026-12'])
            ->assertStatus(403);
    }

    /**
     * 26. SYSTEM:SPECIAL: Cư dân có SYSTEM:SPECIAL không thể can thiệp hạ tầng, tài chính và bị chặn bởi Data Scoping
     */
    public function test_26_resident_with_system_special_does_not_bypass_staff_authorization_or_scoping(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['SYSTEM:SPECIAL']);

        // Bị chặn tạo tầng tòa nhà (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 78,
                'floor_name' => 'Tầng 78 Hack',
            ])
            ->assertStatus(403);

        // Bị chặn xóa căn hộ (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$this->apt102->id}")
            ->assertStatus(403);

        // Bị chặn hủy hóa đơn (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel", ['reason' => 'Test'])
            ->assertStatus(403);

        // Bị chặn gửi nhắc nợ (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(403);

        // Data Scoping: Không thể xem hóa đơn căn hộ khác (403)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/invoices/{$this->invoice102->id}")
            ->assertStatus(403);
    }

    /**
     * 27. Business Integrity: Không thể bypass quy tắc nghiệp vụ và ràng buộc dữ liệu
     */
    public function test_27_special_permissions_do_not_bypass_business_validation(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:SPECIAL']);

        // Thử hủy hóa đơn với ID không tồn tại -> 404
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/invoices/non-existent-invoice-id/cancel')
            ->assertStatus(404);

        [$manager, $mgrToken] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:SPECIAL']);

        // Thử tạo tầng với dữ liệu không hợp lệ (thiếu floor_name) -> 422 Unprocessable Entity
        $this->withHeader('Authorization', 'Bearer '.$mgrToken)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['floor_name']);
    }

    /**
     * 28. BUILDING:SPECIAL: Nhân viên có BUILDING:SPECIAL không thể truy cập hoặc thao tác Hóa đơn
     */
    public function test_28_staff_with_building_special_cannot_access_invoices(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:SPECIAL']);

        // Xem danh sách hóa đơn -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403);

        // Thử hủy hóa đơn -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/cancel")
            ->assertStatus(403);

        // Thử gửi nhắc nợ -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$this->invoice101->id}/send-reminder")
            ->assertStatus(403);

        // Nhưng trong phân hệ Hạ tầng thì thao tác thành công (201 Created)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 79,
                'floor_name' => 'Tầng 79 Valid',
            ])
            ->assertStatus(201);
    }

    /**
     * 29. BUILDING:SPECIAL trên Cư dân: Không thể tạo, sửa hoặc xóa cấu trúc tầng và căn hộ
     */
    public function test_29_resident_with_building_special_cannot_modify_building_structure(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['BUILDING:SPECIAL']);

        // Cư dân cố tạo tầng -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 91,
                'floor_name' => 'Tầng 91 Hack',
            ])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền quản lý cấu trúc tầng tòa nhà.',
                'error' => 'FORBIDDEN',
            ]);

        // Cư dân cố xóa căn hộ -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$this->apt102->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền quản trị căn hộ.',
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 30. Thu hồi quyền SPECIAL có hiệu lực ngay ở request tiếp theo
     */
    public function test_30_revoking_special_permission_takes_immediate_effect(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('ACCOUNTANT', ['INVOICE:SPECIAL']);

        // Request 1: Có quyền INVOICE:SPECIAL -> Xem hóa đơn thành công (200 OK)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(200);

        // Thu hồi quyền: Xóa liên kết role_permissions của role tùy chỉnh
        $customRole = $user->roles()->where('role_code', 'LIKE', 'CUSTOM_USER_%')->first();
        $this->assertNotNull($customRole);
        DB::table('role_permissions')->where('role_id', $customRole->id)->delete();
        $user->clearPermissionCache();

        // Request 2 (dùng cùng token): Ngay lập tức bị từ chối 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403);
    }

    /**
     * 31. Tách biệt quyền: Người chỉ có APARTMENT:SPECIAL không thể tạo tầng tòa nhà (403 Forbidden)
     */
    public function test_31_staff_with_only_apartment_special_cannot_create_floor(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['APARTMENT:SPECIAL']);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 81,
                'floor_name' => 'Tầng 81 Apartment Special Attempt',
            ]);

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);

        // Đảm bảo không có tầng nào được tạo trong database
        $this->assertDatabaseMissing('floors', [
            'block_id' => $this->blockA->id,
            'floor_number' => 81,
        ]);
    }

    /**
     * 32. Tách biệt quyền: Người chỉ có APARTMENT:SPECIAL không thể sửa hoặc xóa tầng tòa nhà (403 Forbidden)
     */
    public function test_32_staff_with_only_apartment_special_cannot_update_or_delete_floor(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['APARTMENT:SPECIAL']);

        // Thử sửa tầng -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/floors/{$this->floor1->id}", [
                'floor_name' => 'Tầng 1 Hacked',
            ])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);

        // Thử xóa tầng -> 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/floors/{$this->floor1->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);

        // Đảm bảo dữ liệu tầng không bị thay đổi trong database
        $freshFloor = Floor::find($this->floor1->id);
        $this->assertNotNull($freshFloor);
        $this->assertEquals('Tầng 1', $freshFloor->floor_name);
    }

    /**
     * 33. Quản lý căn hộ: Người có APARTMENT:SPECIAL có thể quản trị căn hộ thành công
     */
    public function test_33_staff_with_apartment_special_can_manage_apartments(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['APARTMENT:SPECIAL']);

        // Tạo căn hộ mới
        $createRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-333',
                'floor_id' => $this->floor1->id,
                'block_id' => $this->blockA->id,
            ]);

        $createRes->assertStatus(201)
            ->assertJson(['success' => true]);
        $newAptId = $createRes->json('data.id');

        // Cập nhật căn hộ
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/apartments/{$newAptId}", [
                'room_type' => '2_BEDROOM',
            ])
            ->assertStatus(200);

        // Đổi trạng thái căn hộ
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/apartments/{$newAptId}/status", [
                'status' => 'RENTED',
            ])
            ->assertStatus(200);

        // Xóa căn hộ
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$newAptId}")
            ->assertStatus(200);
    }

    /**
     * 34. Phân cấp quản lý: Người có BUILDING:SPECIAL quản trị được cả tầng và căn hộ
     */
    public function test_34_staff_with_building_special_can_manage_both_floors_and_apartments(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['BUILDING:SPECIAL']);

        // 1. Quản lý tầng: Tạo và xóa tầng thành công
        $floorRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 84,
                'floor_name' => 'Tầng 84 Building Special',
            ]);
        $floorRes->assertStatus(201);
        $newFloorId = $floorRes->json('data.id');

        // 2. Quản lý căn hộ: Tạo và xóa căn hộ thành công
        $aptRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-344',
                'floor_id' => $this->floor1->id,
                'block_id' => $this->blockA->id,
            ]);
        $aptRes->assertStatus(201);
        $newAptId = $aptRes->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$newAptId}")
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/floors/{$newFloorId}?force=true")
            ->assertStatus(200);
    }

    /**
     * 35. Staff Gate: Cư dân có APARTMENT:SPECIAL vẫn bị chặn tạo, sửa, xóa căn hộ và tầng
     */
    public function test_35_resident_with_apartment_special_is_blocked_by_staff_gate(): void
    {
        [$resident, $token] = $this->createResidentWithApartment($this->apt101->id, ['APARTMENT:SPECIAL']);

        // Cư dân cố tạo căn hộ -> 403 Forbidden từ Staff Gate
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apartments', [
                'apartment_number' => 'A-355',
                'floor_id' => $this->floor1->id,
            ])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền quản trị căn hộ.',
                'error' => 'FORBIDDEN',
            ]);

        // Cư dân cố xóa căn hộ -> 403 Forbidden từ Staff Gate
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/apartments/{$this->apt102->id}")
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Bạn không có quyền quản trị căn hộ.',
                'error' => 'FORBIDDEN',
            ]);

        // Cư dân cố tạo tầng -> 403 Forbidden (chặn cả ở Permission Gate lẫn Staff Gate)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 85,
                'floor_name' => 'Tầng 85 Resident Hack',
            ])
            ->assertStatus(403);
    }

    /**
     * 36. Độc lập phân hệ: Thu hồi SPECIAL phân hệ này không ảnh hưởng đến SPECIAL phân hệ khác
     */
    public function test_36_revoking_one_module_special_preserves_other_module_special(): void
    {
        [$user, $token] = $this->createTestUserWithPermissions('BUILDING_MANAGER', ['INVOICE:SPECIAL', 'BUILDING:SPECIAL']);

        // Request 1: Truy cập hóa đơn -> 200 OK (nhờ INVOICE:SPECIAL)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(200);

        // Request 2: Tạo tầng -> 201 Created (nhờ BUILDING:SPECIAL)
        $floorRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/blocks/{$this->blockA->id}/floors", [
                'floor_number' => 86,
                'floor_name' => 'Tầng 86 Dual Special',
            ]);
        $floorRes->assertStatus(201);
        $floorId = $floorRes->json('data.id');

        // Thu hồi CHỈ quyền INVOICE:SPECIAL
        $customRole = $user->roles()->where('role_code', 'LIKE', 'CUSTOM_USER_%')->first();
        $this->assertNotNull($customRole);
        $invSpecialPerm = Permission::where('permission_code', 'INVOICE:SPECIAL')->first();
        $this->assertNotNull($invSpecialPerm);

        DB::table('role_permissions')
            ->where('role_id', $customRole->id)
            ->where('permission_id', $invSpecialPerm->id)
            ->delete();
        $user->clearPermissionCache();

        // Request 3 (cùng token): Thao tác hóa đơn ngay lập tức bị từ chối 403 Forbidden
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403);

        // Request 4 (cùng token): Thao tác hạ tầng VẪN THÀNH CÔNG (200 OK) vì BUILDING:SPECIAL còn nguyên
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/floors/{$floorId}?force=true")
            ->assertStatus(200);
    }
}
