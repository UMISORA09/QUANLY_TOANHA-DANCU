<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\QuocTinRealtimeService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VehicleAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Block $block;

    protected Apartment $apartment;

    protected User $owner;

    protected string $departmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $suffix = Str::upper(Str::random(6));

        $this->block = Block::create([
            'block_code' => 'BLK_AUTH_'.$suffix,
            'block_name' => 'Tòa Kiểm Thử Quyền '.$suffix,
            'total_floors' => 5,
            'total_basements' => 1,
            'total_apartments' => 10,
        ]);

        $floorId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $this->block->id,
            'floor_number' => 1,
            'floor_code' => 'FL-AUTH-'.$suffix,
            'floor_name' => 'Tầng 1 Kiểm Thử',
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->apartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $floorId,
            'apartment_number' => 'VA-101-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);

        $this->departmentId = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $this->departmentId,
            'department_code' => 'DEPT_AUTH_'.$suffix,
            'department_name' => 'Bộ phận Vận hành '.$suffix,
            'created_at' => now(),
        ]);

        [$this->owner] = $this->createUserWithRole('RESIDENT_OWNER', 'veh_owner_');

        Resident::create([
            'user_id' => $this->owner->id,
            'apartment_id' => $this->apartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);
    }

    /**
     * Tạo người dùng kèm vai trò hệ thống và phiên Bearer token hợp lệ.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithRole(string $roleCode, string $prefix, array $attributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Vehicle Auth '.$roleCode.' '.$rand,
            'status' => 'ACTIVE',
        ], $attributes));

        $role = Role::where('role_code', $roleCode)->firstOrFail();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Tạo người dùng chỉ có các mã quyền chỉ định (tùy biến).
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, ?string $baseRoleCode = null): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => 'custom_veh_'.$rand,
            'phone_number' => '08'.rand(10000000, 99999999),
            'email' => 'custom_veh_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Custom Vehicle Tester '.$rand,
            'status' => 'ACTIVE',
        ]);

        if ($baseRoleCode !== null) {
            $baseRole = Role::where('role_code', $baseRoleCode)->firstOrFail();
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $baseRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        // Gắn vai trò CUSTOM_USER_{id} để phản ánh chính xác trường hợp bị thu hồi/giới hạn quyền trên Ma trận RBAC
        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_'.$user->id,
            'role_name' => 'Custom Permission Override '.$user->id,
            'is_system_role' => false,
            'status' => 'ACTIVE',
        ]);

        if ($permissionCodes !== []) {
            $permIds = Permission::whereIn('permission_code', $permissionCodes)->pluck('id');
            foreach ($permIds as $permId) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $customRole->id,
                    'permission_id' => $permId,
                    'created_at' => now(),
                ]);
            }
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Phát hành Bearer token hợp lệ gắn phiên đăng nhập trong user_sessions.
     */
    protected function issueTestBearerToken(User $user): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(32);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit Vehicle Auth',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDay(),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Header JSON kèm Bearer token.
     *
     * @return array<string, string>
     */
    protected function bearerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * Khởi tạo phương tiện mẫu chờ duyệt trong DB phục vụ kiểm thử phân quyền.
     */
    protected function createPendingVehicle(?string $plate = null): Vehicle
    {
        return Vehicle::create([
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $plate ?? '29A-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Honda',
            'model' => 'Vision',
            'color' => 'Đen',
            'monthly_parking_fee' => 120000.00,
            'is_active' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * Khởi tạo phương tiện đã duyệt và đang hoạt động (chưa có hóa đơn).
     */
    protected function createActiveApprovedVehicle(User $approver, ?string $plate = null): Vehicle
    {
        return Vehicle::create([
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $plate ?? '30E-'.rand(10000, 99999),
            'vehicle_category' => 'CAR',
            'brand' => 'Toyota',
            'model' => 'Camry',
            'color' => 'Trắng',
            'monthly_parking_fee' => 1500000.00,
            'is_active' => true,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Tạo payload hợp lệ để đảm bảo request đi qua FormRequest và kiểm tra phân quyền tại Controller.
     *
     * @return array<string, mixed>
     */
    protected function validCreatePayload(?string $plate = null): array
    {
        return [
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $plate ?? '59B1-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Yamaha',
            'model' => 'NVX',
            'color' => 'Xanh',
        ];
    }

    /**
     * Kiểm tra phản hồi bị từ chối với mã HTTP mong muốn và không rò rỉ dữ liệu.
     */
    protected function assertDeniedResponse(TestResponse $response, int $expectedStatus): void
    {
        $response->assertStatus($expectedStatus)
            ->assertJson([
                'success' => false,
            ]);

        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayNotHasKey('data', $json);
    }

    /**
     * Đảm bảo trạng thái phương tiện và hóa đơn trong DB không thay đổi sau yêu cầu bị từ chối.
     */
    protected function assertVehicleAndInvoiceUnchanged(
        Vehicle $vehicle,
        bool $expectedActive,
        ?string $expectedApproverId,
        string $expectedColor,
        int $expectedVehicleCount
    ): void {
        $fresh = Vehicle::withTrashed()->find($vehicle->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($expectedActive, (bool) $fresh->is_active);
        $this->assertSame($expectedApproverId, $fresh->approved_by);
        $this->assertSame($expectedColor, $fresh->color);
        $this->assertSame($expectedVehicleCount, Vehicle::count());

        $invoiceItemsCount = DB::table('invoice_items')
            ->where('item_description', 'like', "%{$vehicle->license_plate}%")
            ->count();
        $this->assertSame(0, $invoiceItemsCount);
    }

    /**
     * 1. Tài khoản chỉ có VEHICLE:VIEW được xem danh sách, chi tiết, biểu phí nhưng bị chặn 403 ở toàn bộ thao tác ghi/duyệt/đồng bộ hóa đơn.
     */
    public function test_view_only_user_can_read_but_cannot_mutate_approve_or_sync_invoice(): void
    {
        [, $viewOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:VIEW']);
        $vehicle = $this->createPendingVehicle('29V1-11111');
        $initialCount = Vehicle::count();

        $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->getJson("/api/v1/vehicles/{$vehicle->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->getJson('/api/v1/vehicles/pricing-config?category=MOTORBIKE')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $blockedPlate = '29V1-99999';
        $createRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->postJson('/api/v1/vehicles', $this->validCreatePayload($blockedPlate));
        $this->assertDeniedResponse($createRes, 403);
        $this->assertNull(Vehicle::withTrashed()->where('license_plate', $blockedPlate)->first());

        $updateRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Vàng']);
        $this->assertDeniedResponse($updateRes, 403);

        $patchRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->patchJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Tím']);
        $this->assertDeniedResponse($patchRes, 403);

        $toggleRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active");
        $this->assertDeniedResponse($toggleRes, 403);

        $deleteRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->deleteJson("/api/v1/vehicles/{$vehicle->id}");
        $this->assertDeniedResponse($deleteRes, 403);

        $approveRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->postJson("/api/v1/vehicles/{$vehicle->id}/approve");
        $this->assertDeniedResponse($approveRes, 403);

        $syncRes = $this->withHeaders($this->bearerHeaders($viewOnlyToken))
            ->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice", [
                'billing_period' => Carbon::now()->format('Y-m'),
            ]);
        $this->assertDeniedResponse($syncRes, 403);

        $this->assertVehicleAndInvoiceUnchanged($vehicle, false, null, 'Đen', $initialCount);
    }

    /**
     * 2. Tài khoản chỉ có VEHICLE:CREATE chỉ tạo được xe mới, không thể xem, sửa, xóa, duyệt hay đồng bộ hóa đơn thủ công.
     */
    public function test_create_only_user_can_store_only_and_is_blocked_from_other_actions(): void
    {
        [, $createOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:CREATE']);
        $existingVehicle = $this->createPendingVehicle('29C1-22222');

        $newPlate = '29C1-33333';
        $this->withHeaders($this->bearerHeaders($createOnlyToken))
            ->postJson('/api/v1/vehicles', $this->validCreatePayload($newPlate))
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertNotNull(Vehicle::where('license_plate', $newPlate)->first());
        $countAfterCreate = Vehicle::count();

        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->getJson("/api/v1/vehicles/{$existingVehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->getJson('/api/v1/vehicles/pricing-config'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->putJson("/api/v1/vehicles/{$existingVehicle->id}", ['color' => 'Đỏ']),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->patchJson("/api/v1/vehicles/{$existingVehicle->id}/toggle-active"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->deleteJson("/api/v1/vehicles/{$existingVehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->postJson("/api/v1/vehicles/{$existingVehicle->id}/approve"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->postJson("/api/v1/vehicles/{$existingVehicle->id}/sync-invoice"),
            403
        );

        $this->assertVehicleAndInvoiceUnchanged($existingVehicle, false, null, 'Đen', $countAfterCreate);
    }

    /**
     * 3. Tài khoản chỉ có VEHICLE:UPDATE được update và toggleActive nhưng bị chặn 403 ở các hành động khác.
     */
    public function test_update_only_user_can_update_and_toggle_active_only(): void
    {
        [, $updateOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:UPDATE']);
        $vehicle = $this->createPendingVehicle('29U1-44444');

        $this->withHeaders($this->bearerHeaders($updateOnlyToken))
            ->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Bạc'])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->withHeaders($this->bearerHeaders($updateOnlyToken))
            ->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_active' => true,
                ],
            ]);

        $fresh = Vehicle::findOrFail($vehicle->id);
        $this->assertSame('Bạc', $fresh->color);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->approved_by);

        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->getJson("/api/v1/vehicles/{$vehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->getJson('/api/v1/vehicles/pricing-config'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29U1-55555')),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->postJson("/api/v1/vehicles/{$vehicle->id}/approve"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($updateOnlyToken))->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice"),
            403
        );

        $this->assertNotNull(Vehicle::find($vehicle->id));
        $this->assertNull(Vehicle::findOrFail($vehicle->id)->approved_by);
    }

    /**
     * 4. Tài khoản chỉ có VEHICLE:DELETE chỉ xóa được xe, bị chặn 403 ở tất cả thao tác khác.
     */
    public function test_delete_only_user_can_destroy_only(): void
    {
        [, $deleteOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:DELETE']);
        $targetForDelete = $this->createPendingVehicle('29D1-66661');
        $protectedVehicle = $this->createPendingVehicle('29D1-66662');

        $this->withHeaders($this->bearerHeaders($deleteOnlyToken))
            ->deleteJson("/api/v1/vehicles/{$targetForDelete->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNull(Vehicle::find($targetForDelete->id));
        $this->assertNotNull(Vehicle::withTrashed()->find($targetForDelete->id)?->deleted_at);

        $countAfterDelete = Vehicle::count();

        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->getJson("/api/v1/vehicles/{$protectedVehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->getJson('/api/v1/vehicles/pricing-config'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29D1-66663')),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->putJson("/api/v1/vehicles/{$protectedVehicle->id}", ['color' => 'Xám']),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->patchJson("/api/v1/vehicles/{$protectedVehicle->id}/toggle-active"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->postJson("/api/v1/vehicles/{$protectedVehicle->id}/approve"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($deleteOnlyToken))->postJson("/api/v1/vehicles/{$protectedVehicle->id}/sync-invoice"),
            403
        );

        $this->assertVehicleAndInvoiceUnchanged($protectedVehicle, false, null, 'Đen', $countAfterDelete);
    }

    /**
     * 5. Tài khoản chỉ có VEHICLE:APPROVE chỉ thực hiện được approve() và syncInvoice(), bị chặn 403 ở các action khác.
     */
    public function test_approve_only_user_can_approve_and_sync_invoice_only(): void
    {
        [$approveUser, $approveOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:APPROVE']);
        $pendingVehicle = $this->createPendingVehicle('29P1-77771');

        $this->withHeaders($this->bearerHeaders($approveOnlyToken))
            ->postJson("/api/v1/vehicles/{$pendingVehicle->id}/approve")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $pendingVehicle->id,
                    'approved_by' => $approveUser->id,
                    'is_active' => true,
                ],
            ]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $nextPeriod = Carbon::now()->addMonth()->format('Y-m');
        $this->withHeaders($this->bearerHeaders($approveOnlyToken))
            ->postJson("/api/v1/vehicles/{$pendingVehicle->id}/sync-invoice", [
                'billing_period' => $nextPeriod,
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'synced' => true,
                ],
            ]);

        $syncedInvoice = DB::table('invoices')
            ->where('apartment_id', $this->apartment->id)
            ->where('billing_period', $nextPeriod)
            ->first();
        $this->assertNotNull($syncedInvoice);

        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->getJson("/api/v1/vehicles/{$pendingVehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->getJson('/api/v1/vehicles/pricing-config'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29P1-77772')),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->putJson("/api/v1/vehicles/{$pendingVehicle->id}", ['color' => 'Xám']),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->patchJson("/api/v1/vehicles/{$pendingVehicle->id}/toggle-active"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($approveOnlyToken))->deleteJson("/api/v1/vehicles/{$pendingVehicle->id}"),
            403
        );

        $fresh = Vehicle::findOrFail($pendingVehicle->id);
        $this->assertSame('Đen', $fresh->color);
        $this->assertTrue((bool) $fresh->is_active);
    }

    /**
     * 6. syncInvoice() chỉ cho phép người có VEHICLE:APPROVE (chặn ACCOUNTANT chỉ có INVOICE:* và các quyền VEHICLE khác).
     */
    public function test_sync_invoice_strictly_requires_vehicle_approve_permission(): void
    {
        [$superAdmin] = $this->createUserWithRole('SUPER_ADMIN', 'sync_sa_');
        $vehicle = $this->createActiveApprovedVehicle($superAdmin, '30S1-88881');

        [, $accountantToken] = $this->createUserWithRole('ACCOUNTANT', 'sync_acc_');
        [, $viewToken] = $this->createUserWithCustomPermissions(['VEHICLE:VIEW']);
        [, $createToken] = $this->createUserWithCustomPermissions(['VEHICLE:CREATE']);
        [, $updateToken] = $this->createUserWithCustomPermissions(['VEHICLE:UPDATE']);
        [, $deleteToken] = $this->createUserWithCustomPermissions(['VEHICLE:DELETE']);
        [, $invoiceUpdateToken] = $this->createUserWithCustomPermissions(['INVOICE:VIEW', 'INVOICE:CREATE', 'INVOICE:UPDATE']);

        $period = Carbon::now()->format('Y-m');
        $deniedTokens = [
            'ACCOUNTANT' => $accountantToken,
            'VEHICLE:VIEW' => $viewToken,
            'VEHICLE:CREATE' => $createToken,
            'VEHICLE:UPDATE' => $updateToken,
            'VEHICLE:DELETE' => $deleteToken,
            'INVOICE:CREATE/UPDATE' => $invoiceUpdateToken,
        ];

        foreach ($deniedTokens as $label => $token) {
            $res = $this->withHeaders($this->bearerHeaders($token))
                ->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice", [
                    'billing_period' => $period,
                ]);
            $res->assertStatus(403, "{$label} không có VEHICLE:APPROVE phải bị chặn 403 khi gọi sync-invoice");
        }

        $itemsBeforeAllow = DB::table('invoice_items')
            ->where('item_description', 'like', "%{$vehicle->license_plate}%")
            ->count();
        $this->assertSame(0, $itemsBeforeAllow);
    }

    /**
     * 7. Không cho phép dựa vào tên role (RECEPTIONIST, SECURITY_GUARD, BUILDING_MANAGER) để vượt qua kiểm tra permission.
     */
    public function test_staff_roles_cannot_bypass_rbac_permission_matrix_when_permissions_are_revoked_or_missing(): void
    {
        $vehicle = $this->createPendingVehicle('29R1-12345');
        $initialCount = Vehicle::count();

        // RECEPTIONIST mặc định không có VEHICLE:DELETE -> phải bị 403 khi xóa xe
        [, $receptionistToken] = $this->createUserWithRole('RECEPTIONIST', 'rec_del_');
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($receptionistToken))->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
            403
        );
        $this->assertNotNull(Vehicle::find($vehicle->id));

        // SECURITY_GUARD bị thu hồi toàn bộ quyền qua CUSTOM_USER_% -> 403 ở tất cả endpoint
        [, $revokedSecurityToken] = $this->createUserWithCustomPermissions([], 'SECURITY_GUARD');
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($revokedSecurityToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($revokedSecurityToken))->getJson('/api/v1/vehicles/pricing-config'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($revokedSecurityToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29R1-99901')),
            403
        );

        // BUILDING_MANAGER bị giới hạn còn duy nhất VEHICLE:VIEW -> không thể tạo/sửa/xóa/duyệt/sync
        [, $narrowedManagerToken] = $this->createUserWithCustomPermissions(['VEHICLE:VIEW'], 'BUILDING_MANAGER');
        $this->withHeaders($this->bearerHeaders($narrowedManagerToken))
            ->getJson('/api/v1/vehicles')
            ->assertStatus(200);

        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29R1-99902')),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Bạc']),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->postJson("/api/v1/vehicles/{$vehicle->id}/approve"),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($narrowedManagerToken))->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice"),
            403
        );

        $this->assertVehicleAndInvoiceUnchanged($vehicle, false, null, 'Đen', $initialCount);
    }

    /**
     * 8. Tài khoản không ở trạng thái ACTIVE bị từ chối ở mọi endpoint và không làm thay đổi dữ liệu.
     */
    public function test_inactive_user_is_rejected_across_all_vehicle_endpoints(): void
    {
        $vehicle = $this->createPendingVehicle('29L1-55551');
        $initialCount = Vehicle::count();

        foreach (['INACTIVE', 'LOCKED', 'SUSPENDED'] as $status) {
            [, $inactiveToken] = $this->createUserWithRole('BUILDING_MANAGER', 'inactive_bm_', [
                'status' => $status,
            ]);

            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->getJson('/api/v1/vehicles'),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->getJson("/api/v1/vehicles/{$vehicle->id}"),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->getJson('/api/v1/vehicles/pricing-config'),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29L1-99999')),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Xám']),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active"),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->postJson("/api/v1/vehicles/{$vehicle->id}/approve"),
                403
            );
            $this->assertDeniedResponse(
                $this->withHeaders($this->bearerHeaders($inactiveToken))->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice"),
                403
            );
        }

        // Kể cả SUPER_ADMIN bị khóa cũng không được phép truy cập
        [, $lockedAdminToken] = $this->createUserWithRole('SUPER_ADMIN', 'locked_sa_', [
            'status' => 'LOCKED',
        ]);
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($lockedAdminToken))->getJson('/api/v1/vehicles'),
            403
        );
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($lockedAdminToken))->postJson('/api/v1/vehicles', $this->validCreatePayload('29L1-99998')),
            403
        );

        $this->assertVehicleAndInvoiceUnchanged($vehicle, false, null, 'Đen', $initialCount);
    }

    /**
     * 9. Người dùng chưa xác thực (401) và Cư dân không có quyền (403) bị chặn ở mọi endpoint, bao gồm pricing-config.
     */
    public function test_unauthenticated_and_resident_users_are_denied_across_all_vehicle_endpoints(): void
    {
        $vehicle = $this->createPendingVehicle('29N1-33331');
        $initialCount = Vehicle::count();
        [, $residentToken] = $this->createUserWithRole('RESIDENT_OWNER', 'res_veh_denied_');

        $unauthRequests = [
            $this->getJson('/api/v1/vehicles'),
            $this->getJson("/api/v1/vehicles/{$vehicle->id}"),
            $this->getJson('/api/v1/vehicles/pricing-config'),
            $this->postJson('/api/v1/vehicles', $this->validCreatePayload('29N1-99901')),
            $this->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Xám']),
            $this->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active"),
            $this->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
            $this->postJson("/api/v1/vehicles/{$vehicle->id}/approve"),
            $this->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice"),
        ];

        foreach ($unauthRequests as $res) {
            $this->assertDeniedResponse($res, 401);
        }

        $residentHeaders = $this->bearerHeaders($residentToken);
        $residentRequests = [
            $this->withHeaders($residentHeaders)->getJson('/api/v1/vehicles'),
            $this->withHeaders($residentHeaders)->getJson("/api/v1/vehicles/{$vehicle->id}"),
            $this->withHeaders($residentHeaders)->getJson('/api/v1/vehicles/pricing-config'),
            $this->withHeaders($residentHeaders)->postJson('/api/v1/vehicles', $this->validCreatePayload('29N1-99902')),
            $this->withHeaders($residentHeaders)->putJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Xám']),
            $this->withHeaders($residentHeaders)->patchJson("/api/v1/vehicles/{$vehicle->id}/toggle-active"),
            $this->withHeaders($residentHeaders)->deleteJson("/api/v1/vehicles/{$vehicle->id}"),
            $this->withHeaders($residentHeaders)->postJson("/api/v1/vehicles/{$vehicle->id}/approve"),
            $this->withHeaders($residentHeaders)->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice"),
        ];

        foreach ($residentRequests as $res) {
            $this->assertDeniedResponse($res, 403);
        }

        $this->assertVehicleAndInvoiceUnchanged($vehicle, false, null, 'Đen', $initialCount);

        // Route công khai /vehicles/pricing-config (ServicePricingController) không bị ảnh hưởng
        $this->getJson('/vehicles/pricing-config')
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 10. SUPER_ADMIN hoạt động bình thường trên toàn bộ luồng CRUD, toggleActive, approve, syncInvoice và pricingConfig.
     */
    public function test_super_admin_retains_full_access_across_all_vehicle_actions(): void
    {
        [$superAdmin, $adminToken] = $this->createUserWithRole('SUPER_ADMIN', 'veh_sa_');
        $headers = $this->bearerHeaders($adminToken);

        $this->withHeaders($headers)
            ->getJson('/api/v1/vehicles/pricing-config?category=MOTORBIKE')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $plate = '29SA-'.rand(10000, 99999);
        $storeRes = $this->withHeaders($headers)
            ->postJson('/api/v1/vehicles', $this->validCreatePayload($plate));
        $storeRes->assertStatus(201)->assertJson(['success' => true]);
        $createdId = (string) $storeRes->json('data.id');

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->withHeaders($headers)
            ->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->withHeaders($headers)
            ->getJson("/api/v1/vehicles/{$createdId}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->withHeaders($headers)
            ->putJson("/api/v1/vehicles/{$createdId}", ['color' => 'Xanh Ngọc'])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/vehicles/{$createdId}/toggle-active")
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['is_active' => false]]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $pending = $this->createPendingVehicle('29SA-88888');
        $this->withHeaders($headers)
            ->postJson("/api/v1/vehicles/{$pending->id}/approve")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'approved_by' => $superAdmin->id,
                    'is_active' => true,
                ],
            ]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->withHeaders($headers)
            ->postJson("/api/v1/vehicles/{$pending->id}/sync-invoice", [
                'billing_period' => Carbon::now()->addMonth()->format('Y-m'),
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/vehicles/{$createdId}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 11. Các endpoint metadata BUG-M4-02 vẫn giữ nguyên phân quyền VEHICLE:VIEW và phạm vi tòa nhà.
     */
    public function test_bug_m4_02_metadata_authorization_and_scoping_remain_intact(): void
    {
        [$scopedUser, $scopedToken] = $this->createUserWithCustomPermissions(['VEHICLE:VIEW']);

        DB::table('staff_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $scopedUser->id,
            'employee_code' => 'EMP-AUTH-'.Str::upper(Str::random(6)),
            'department_id' => $this->departmentId,
            'job_title' => 'Nhân viên Phương tiện',
            'hire_date' => today(),
            'contract_type' => 'FULL_TIME',
            'assigned_block_id' => $this->block->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->bearerHeaders($scopedToken))
            ->getJson('/api/v1/vehicles/meta/apartments')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $resMeta = $this->withHeaders($this->bearerHeaders($scopedToken))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartment->id}/residents");
        $resMeta->assertStatus(200)->assertJson(['success' => true]);

        $residentRows = $resMeta->json('data');
        $this->assertIsArray($residentRows);
        $this->assertNotEmpty($residentRows);
        $this->assertArrayNotHasKey('email', $residentRows[0]);
        $this->assertArrayNotHasKey('national_id_number', $residentRows[0]);
        $this->assertArrayNotHasKey('password_hash', $residentRows[0]);

        [, $createOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:CREATE']);
        $this->assertDeniedResponse(
            $this->withHeaders($this->bearerHeaders($createOnlyToken))->getJson('/api/v1/vehicles/meta/residents'),
            403
        );
    }
}
