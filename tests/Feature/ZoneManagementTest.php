<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ZoneSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZoneManagementTest extends TestCase
{
    protected function tearDown(): void
    {
        // Dọn dẹp an toàn các record do test tạo bằng prefix đặc trưng
        Apartment::where('apartment_number', 'like', 'APT-TEST-%')->forceDelete();
        Floor::where('floor_code', 'like', 'FL-TEST-%')->forceDelete();
        Block::where('block_code', 'like', 'TEST-%')->forceDelete();

        parent::tearDown();
    }

    /**
     * Helper tạo user kèm Role và Token cho AuthenticateBearer middleware
     */
    protected function createUserWithRole(string $roleCode): array
    {
        $user = User::create([
            'username' => 'test_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'test_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Test User '.$roleCode,
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

        $token = 'smart_test_token_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Runner',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    // ========================================================
    // 1. KIỂM TRA BẢO MẬT & PHÂN QUYỀN (AUTHENTICATION & RBAC)
    // ========================================================

    /**
     * Khách vãng lai (chưa đăng nhập) gọi API quản lý -> 401 Unauthorized
     */
    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/v1/manager/zones')->assertStatus(401);
        $this->postJson('/api/v1/manager/zones', [])->assertStatus(401);
        $this->putJson('/api/v1/manager/zones/'.Str::uuid(), [])->assertStatus(401);
        $this->deleteJson('/api/v1/manager/zones/'.Str::uuid())->assertStatus(401);
    }

    /**
     * Cư dân (RESIDENT) không đủ quyền quản lý khối tòa nhà -> 403 Forbidden
     */
    public function test_unauthorized_resident_cannot_manage_zones_403(): void
    {
        [$residentUser, $token] = $this->createUserWithRole('RESIDENT');
        $headers = ['Authorization' => "Bearer {$token}"];

        // Không thể tạo
        $this->postJson('/api/v1/manager/zones', [
            'zone_code' => 'TEST-RESIDENT-DENIED',
            'zone_name' => 'Tòa Nhà Trái Phép',
            'floor_count' => 10,
        ], $headers)->assertStatus(403);

        // Tạo sẵn một khối thử nghiệm
        $block = Block::create([
            'block_code' => 'TEST-BLOCK-DENY',
            'block_name' => 'Khối Bảo Vệ',
            'total_floors' => 10,
            'status' => 'ACTIVE',
        ]);

        // Không thể sửa
        $this->putJson("/api/v1/manager/zones/{$block->id}", [
            'zone_name' => 'Sửa Trái Phép',
        ], $headers)->assertStatus(403);

        // Không thể xóa
        $this->deleteJson("/api/v1/manager/zones/{$block->id}", [], $headers)
            ->assertStatus(403);
    }

    // ========================================================
    // 2. DANH SÁCH, TÌM KIẾM, BỘ LỌC VÀ THỐNG KÊ
    // ========================================================

    /**
     * Quản lý tòa nhà (BUILDING_MANAGER) lấy danh sách khối tòa nhà và thống kê chính xác
     */
    public function test_building_manager_can_list_zones_and_see_statistics(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        Block::create([
            'block_code' => 'TEST-LIST-01',
            'block_name' => 'Khối Thử Nghiệm Danh Sách',
            'total_floors' => 20,
            'total_basements' => 2,
            'total_apartments' => 150,
            'status' => 'ACTIVE',
        ]);

        $response = $this->getJson('/api/v1/manager/zones', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'zone_code',
                        'zone_name',
                        'floor_count',
                        'basement_count',
                        'total_apartments',
                        'status',
                        'version',
                        'updated_at',
                    ],
                ],
                'stats' => [
                    'total_zones',
                    'active_zones',
                    'maintenance_zones',
                    'total_floors',
                    'total_apartments',
                ],
            ]);
    }

    /**
     * Tìm kiếm theo từ khóa và lọc theo trạng thái
     */
    public function test_can_filter_and_search_zones(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        $headers = ['Authorization' => "Bearer {$token}"];

        Block::create([
            'block_code' => 'TEST-SEARCH-ALPHA',
            'block_name' => 'Tòa Tháp Alpha',
            'total_floors' => 10,
            'status' => 'ACTIVE',
        ]);

        Block::create([
            'block_code' => 'TEST-SEARCH-BETA',
            'block_name' => 'Tòa Tháp Beta Bảo Trì',
            'total_floors' => 12,
            'status' => 'MAINTENANCE',
        ]);

        // Tìm theo mã
        $resSearch = $this->getJson('/api/v1/manager/zones?search=ALPHA', $headers);
        $resSearch->assertStatus(200);
        $this->assertTrue(collect($resSearch->json('data'))->contains('zone_code', 'TEST-SEARCH-ALPHA'));
        $this->assertFalse(collect($resSearch->json('data'))->contains('zone_code', 'TEST-SEARCH-BETA'));

        // Lọc theo trạng thái
        $resStatus = $this->getJson('/api/v1/manager/zones?status=MAINTENANCE', $headers);
        $resStatus->assertStatus(200);
        $this->assertTrue(collect($resStatus->json('data'))->contains('zone_code', 'TEST-SEARCH-BETA'));
    }

    // ========================================================
    // 3. THAO TÁC CRUD TRÊN NGUỒN SỰ THẬT DUY NHẤT (BLOCKS)
    // ========================================================

    /**
     * Tạo khối tòa nhà thành công, lưu trực tiếp vào bảng `blocks`
     */
    public function test_can_create_zone_with_valid_data(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $payload = [
            'zone_code' => 'TEST-CREATE-01',
            'zone_name' => 'Khối Mới Khởi Tạo',
            'floor_count' => 25,
            'basement_count' => 3,
            'total_apartments' => 200,
            'status' => 'ACTIVE',
            'address_line' => 'Phân khu A cao cấp',
            'hotline_phone' => '19001234',
            'description' => 'Tòa nhà mới nghiệm thu',
        ];

        $response = $this->postJson('/api/v1/manager/zones', $payload, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'zone_code' => 'TEST-CREATE-01',
                    'zone_name' => 'Khối Mới Khởi Tạo',
                    'floor_count' => 25,
                    'status' => 'ACTIVE',
                    'version' => 1,
                ],
            ]);

        // Kiểm tra lưu vào bảng blocks duy nhất
        $this->assertDatabaseHas('blocks', [
            'block_code' => 'TEST-CREATE-01',
            'block_name' => 'Khối Mới Khởi Tạo',
            'total_floors' => 25,
            'total_basements' => 3,
            'total_apartments' => 200,
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * Validation 422: Mã khối tòa nhà bị trùng lặp
     */
    public function test_cannot_create_zone_with_duplicate_code(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        $headers = ['Authorization' => "Bearer {$token}"];

        Block::create([
            'block_code' => 'TEST-DUP-01',
            'block_name' => 'Khối Gốc',
            'total_floors' => 10,
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'zone_code' => 'TEST-DUP-01',
            'zone_name' => 'Khối Trùng Mã',
            'floor_count' => 15,
        ];

        $response = $this->postJson('/api/v1/manager/zones', $payload, $headers);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zone_code']);
    }

    /**
     * Validation 422: Không được tái sử dụng mã của bản ghi đã bị soft-delete
     * (bảo vệ toàn vẹn UNIQUE index trên MySQL và trả thông báo lỗi thân thiện thay vì SQL exception)
     */
    public function test_cannot_create_zone_with_code_of_soft_deleted_block(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        $headers = ['Authorization' => "Bearer {$token}"];

        $deletedBlock = Block::create([
            'block_code' => 'TEST-SOFT-DUP',
            'block_name' => 'Khối Đã Bị Xóa Mềm',
            'total_floors' => 10,
            'status' => 'INACTIVE',
        ]);
        $deletedBlock->delete(); // Soft delete

        $this->assertSoftDeleted('blocks', [
            'id' => $deletedBlock->id,
            'block_code' => 'TEST-SOFT-DUP',
        ]);

        // Thử tạo mới với cùng mã đó
        $payload = [
            'zone_code' => 'TEST-SOFT-DUP',
            'zone_name' => 'Khối Trùng Mã Đã Xóa',
            'floor_count' => 12,
        ];

        $response = $this->postJson('/api/v1/manager/zones', $payload, $headers);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zone_code']);
    }

    /**
     * Validation 422: Kiểm tra dữ liệu đầu vào không hợp lệ (số tầng <= 0, trạng thái sai)
     */
    public function test_validation_rejects_invalid_inputs(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        $headers = ['Authorization' => "Bearer {$token}"];

        $payload = [
            'zone_code' => '',
            'zone_name' => '',
            'floor_count' => 0,
            'status' => 'INVALID_STATUS',
        ];

        $response = $this->postJson('/api/v1/manager/zones', $payload, $headers);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zone_code', 'zone_name', 'floor_count', 'status']);
    }

    /**
     * Lấy chi tiết một khối tòa nhà qua API
     */
    public function test_can_get_single_zone_details(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $block = Block::create([
            'block_code' => 'TEST-SHOW-01',
            'block_name' => 'Khối Cần Xem Chi Tiết',
            'total_floors' => 18,
            'status' => 'ACTIVE',
        ]);

        $response = $this->getJson("/api/v1/manager/zones/{$block->id}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $block->id,
                    'zone_code' => 'TEST-SHOW-01',
                    'zone_name' => 'Khối Cần Xem Chi Tiết',
                    'floor_count' => 18,
                ],
            ]);
    }

    /**
     * Trả về 404 khi không tìm thấy khối tòa nhà
     */
    public function test_returns_404_when_zone_not_found(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $nonExistentId = (string) Str::uuid();
        $response = $this->getJson("/api/v1/manager/zones/{$nonExistentId}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    // ========================================================
    // 4. ATOMIC OPTIMISTIC LOCKING (COMPARE-AND-SWAP)
    // ========================================================

    /**
     * Cập nhật thành công khi version và last_updated_at khớp; version tăng lên 2
     */
    public function test_can_update_zone_with_valid_version(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $block = Block::create([
            'block_code' => 'TEST-UPDATE-OK',
            'block_name' => 'Khối Ban Đầu',
            'total_floors' => 10,
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $payload = [
            'zone_name' => 'Khối Đã Cập Nhật Thành Công',
            'floor_count' => 15,
            'status' => 'MAINTENANCE',
            'version' => 1,
            'last_updated_at' => $block->updated_at->toISOString(),
        ];

        $response = $this->putJson("/api/v1/manager/zones/{$block->id}", $payload, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'zone_name' => 'Khối Đã Cập Nhật Thành Công',
                    'floor_count' => 15,
                    'status' => 'MAINTENANCE',
                    'version' => 2,
                ],
            ]);

        $this->assertDatabaseHas('blocks', [
            'id' => $block->id,
            'block_name' => 'Khối Đã Cập Nhật Thành Công',
            'version' => 2,
            'status' => 'MAINTENANCE',
        ]);
    }

    /**
     * Optimistic Locking: Trả về HTTP 409 Conflict khi client gửi version cũ (bị stale)
     */
    public function test_optimistic_locking_returns_409_when_version_is_stale(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $block = Block::create([
            'block_code' => 'TEST-STALE-VER',
            'block_name' => 'Khối Thử Version',
            'total_floors' => 10,
            'status' => 'ACTIVE',
            'version' => 3, // Phiên bản hiện tại trên DB là 3
        ]);

        $payload = [
            'zone_name' => 'Ghi Đè Version Cũ',
            'floor_count' => 12,
            'version' => 2, // Client vẫn gửi version cũ = 2
        ];

        $response = $this->putJson("/api/v1/manager/zones/{$block->id}", $payload, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'conflict' => true,
            ])
            ->assertJsonStructure([
                'message',
                'conflict',
                'current_data' => [
                    'id',
                    'zone_code',
                    'version',
                    'updated_at',
                ],
            ]);
    }

    /**
     * Optimistic Locking: Trả về HTTP 409 Conflict khi timestamp last_updated_at bị lệch
     */
    public function test_optimistic_locking_returns_409_when_timestamp_is_stale(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $block = Block::create([
            'block_code' => 'TEST-STALE-TIME',
            'block_name' => 'Khối Thử Timestamp',
            'total_floors' => 10,
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $staleTimestamp = $block->updated_at->subMinutes(15)->toISOString();

        $payload = [
            'zone_name' => 'Cố Tình Ghi Đè',
            'floor_count' => 12,
            'last_updated_at' => $staleTimestamp,
        ];

        $response = $this->putJson("/api/v1/manager/zones/{$block->id}", $payload, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'conflict' => true,
            ]);
    }

    /**
     * Giả lập 2 Request đồng thời (Race Condition): Request 1 thành công, Request 2 nhận 409 Conflict
     */
    public function test_concurrent_updates_simulate_race_condition(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        $headers = ['Authorization' => "Bearer {$token}"];

        $block = Block::create([
            'block_code' => 'TEST-CONCURRENT',
            'block_name' => 'Khối Tranh Chấp',
            'total_floors' => 10,
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        // Cả 2 request A và B cùng tải dữ liệu ở version = 1
        $payloadA = [
            'zone_name' => 'Người A Cập Nhật',
            'version' => 1,
        ];

        $payloadB = [
            'zone_name' => 'Người B Cập Nhật Đồng Thời',
            'version' => 1,
        ];

        // Request A đến trước -> thành công
        $resA = $this->putJson("/api/v1/manager/zones/{$block->id}", $payloadA, $headers);
        $resA->assertStatus(200)
            ->assertJsonPath('data.version', 2);

        // Request B đến sau với version = 1 (nay đã cũ) -> nhận HTTP 409
        $resB = $this->putJson("/api/v1/manager/zones/{$block->id}", $payloadB, $headers);
        $resB->assertStatus(409)
            ->assertJson([
                'conflict' => true,
            ])
            ->assertJsonPath('current_data.version', 2);
    }

    // ========================================================
    // 5. XÓA MỀM (SOFT DELETE)
    // ========================================================

    /**
     * Xóa mềm khối tòa nhà
     */
    public function test_can_soft_delete_zone(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $block = Block::create([
            'block_code' => 'TEST-DELETE-01',
            'block_name' => 'Khối Chuẩn Bị Xóa',
            'total_floors' => 10,
            'status' => 'ACTIVE',
        ]);

        $response = $this->deleteJson("/api/v1/manager/zones/{$block->id}", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('blocks', [
            'id' => $block->id,
            'block_code' => 'TEST-DELETE-01',
        ]);
    }

    // ========================================================
    // 6. QUAN HỆ BLOCK / FLOOR / APARTMENT
    // ========================================================

    /**
     * Kiểm tra quan hệ toàn vẹn giữa Block, Floor và Apartment
     */
    public function test_block_zone_relation_with_floors_and_apartments(): void
    {
        $block = Block::create([
            'block_code' => 'TEST-REL-BLOCK',
            'block_name' => 'Khối Liên Kết Tầng Căn Hộ',
            'total_floors' => 5,
            'status' => 'ACTIVE',
        ]);

        $floor = Floor::create([
            'block_id' => $block->id,
            'floor_number' => 2,
            'floor_code' => 'FL-TEST-02',
            'floor_name' => 'Tầng 2',
            'total_units' => 8,
        ]);

        $apartment = Apartment::create([
            'block_id' => $block->id,
            'floor_id' => $floor->id,
            'apartment_number' => 'APT-TEST-201',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 75.5,
            'net_usable_area_sqm' => 68.2,
            'status' => 'VACANT',
        ]);

        // Kiểm tra quan hệ từ Block
        $this->assertCount(1, $block->floors);
        $this->assertSame('FL-TEST-02', $block->floors->first()->floor_code);

        $this->assertCount(1, $block->apartments);
        $this->assertSame('APT-TEST-201', $block->apartments->first()->apartment_number);

        // Kiểm tra quan hệ ngược từ Floor và Apartment
        $this->assertSame($block->id, $floor->block->id);
        $this->assertSame($block->id, $apartment->block->id);
    }

    // ========================================================
    // 7. SEEDER IDEMPOTENCY & PRODUCTION GUARD
    // ========================================================

    /**
     * Chạy ZoneSeeder nhiều lần không nhân đôi bản ghi (idempotent)
     */
    public function test_zone_seeder_is_idempotent_and_does_not_duplicate_records(): void
    {
        $seeder = new ZoneSeeder;

        // Chạy lần 1
        $seeder->run();
        $countAfterFirstRun = Block::whereIn('block_code', ['BLOCK_A', 'BLOCK_B', 'BLOCK_C', 'BLOCK_D'])->count();
        $this->assertSame(4, $countAfterFirstRun);

        // Cập nhật giá trị tùy chỉnh của người dùng trên BLOCK_A
        $blockA = Block::where('block_code', 'BLOCK_A')->first();
        $blockA->description = 'Người dùng tự chỉnh sửa mô tả đặc thù';
        $blockA->save();

        // Chạy lần 2
        $seeder->run();
        $countAfterSecondRun = Block::whereIn('block_code', ['BLOCK_A', 'BLOCK_B', 'BLOCK_C', 'BLOCK_D'])->count();
        $this->assertSame(4, $countAfterSecondRun, 'Số lượng khối tòa nhà không được nhân bản khi seed lại');

        // Giá trị người dùng chỉnh sửa không bị ghi đè mất
        $blockARefreshed = Block::where('block_code', 'BLOCK_A')->first();
        $this->assertSame('Người dùng tự chỉnh sửa mô tả đặc thù', $blockARefreshed->description);
    }

    /**
     * ZoneSeeder bị chặn hoàn toàn trong môi trường Production
     */
    public function test_zone_seeder_is_blocked_in_production_environment(): void
    {
        // Giả lập môi trường production
        App::detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->environment('production'));

        $initialCount = Block::count();

        $seeder = new ZoneSeeder;
        $seeder->run();

        // Số lượng không thay đổi vì seeder tự động ngắt ngay
        $this->assertSame($initialCount, Block::count());

        // Khôi phục môi trường testing
        App::detectEnvironment(fn () => 'testing');
    }
}
