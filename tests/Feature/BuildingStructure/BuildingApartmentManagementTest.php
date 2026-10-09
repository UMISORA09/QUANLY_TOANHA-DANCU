<?php

namespace Tests\Feature\BuildingStructure;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildingApartmentManagementTest extends TestCase
{
    protected string $adminToken = 'smart_token_admin_test_structure';

    protected function setUp(): void
    {
        parent::setUp();

        $adminUser = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'phone_number' => '0900000001',
                'email' => 'admin@cassavas.vn',
                'password_hash' => bcrypt('password123'),
                'full_name' => 'System Administrator',
                'status' => 'ACTIVE',
            ]
        );
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Quản trị viên cấp cao', 'status' => 'ACTIVE']
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $adminUser->id, 'role_id' => $role->id],
            ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => now()]
        );
        $this->adminToken = 'smart_token_'.$adminUser->id.'_structure';
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Test 01: Lấy danh sách khối tòa nhà kèm thống kê số tầng và căn hộ
     */
    public function test_01_can_get_blocks_list_with_stats(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/blocks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'block_code',
                        'block_name',
                        'total_floors',
                    ],
                ],
                'stats' => [
                    'total_apartments',
                    'vacant',
                    'occupied',
                    'rented',
                    'maintenance',
                    'occupancy_rate',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertNotEmpty($response->json('data'));
    }

    /**
     * Test 02: Lấy danh sách tầng theo khối tòa nhà
     */
    public function test_02_can_get_floors_by_block(): void
    {
        $block = Block::first();
        $this->assertNotNull($block, 'Cần ít nhất 1 block trong CSDL');

        $response = $this->withHeaders($this->authHeaders())->getJson("/api/v1/blocks/{$block->id}/floors");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'block_id',
                        'floor_number',
                        'floor_code',
                        'floor_name',
                    ],
                ],
            ]);
    }

    /**
     * Test 03: Thêm tầng mới cho khối tòa nhà
     */
    public function test_03_can_create_floor_successfully(): void
    {
        $block = Block::first();
        $maxFloor = (int) Floor::where('block_id', $block->id)->max('floor_number');
        $newFloorNum = $maxFloor + 1;

        $payload = [
            'floor_number' => $newFloorNum,
            'floor_code' => "{$block->block_code}_F{$newFloorNum}",
            'floor_name' => "Tầng {$newFloorNum} Thử Nghiệm",
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 8,
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson("/api/v1/blocks/{$block->id}/floors", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.floor_number', $newFloorNum);

        $this->assertDatabaseHas('floors', [
            'block_id' => $block->id,
            'floor_number' => $newFloorNum,
        ]);
    }

    /**
     * Test 04: Báo lỗi khi tạo tầng trùng số hoặc trùng mã
     */
    public function test_04_cannot_create_duplicate_floor_number(): void
    {
        $existingFloor = Floor::first();
        $this->assertNotNull($existingFloor);

        $payload = [
            'floor_number' => $existingFloor->floor_number,
            'floor_name' => 'Tầng trùng lặp',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson("/api/v1/blocks/{$existingFloor->block_id}/floors", $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * Test 05: Lấy danh sách căn hộ có phân trang và bộ lọc
     */
    public function test_05_can_get_apartments_with_filters(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/apartments?per_page=10&status=all');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'apartment_number',
                        'status',
                        'gross_floor_area_sqm',
                        'block',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'last_page',
                    'total',
                ],
            ]);
    }

    /**
     * Test 06: Tạo mới một căn hộ đơn lẻ và kiểm tra tăng đếm
     */
    public function test_06_can_create_single_apartment_successfully(): void
    {
        $block = Block::first();
        $floor = Floor::where('block_id', $block->id)->first();
        $aptNum = 'TEST-UNIT-'.rand(1000, 9999);

        $payload = [
            'block_id' => $block->id,
            'floor_id' => $floor->id,
            'apartment_number' => $aptNum,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 82.5,
            'net_usable_area_sqm' => 76.0,
            'bedroom_count' => 2,
            'bathroom_count' => 2,
            'water_quota_registered' => 4,
            'status' => 'VACANT',
            'monthly_management_fee_fixed' => 990000,
            'furnished_status' => 'BASIC',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/apartments', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.apartment_number', $aptNum)
            ->assertJsonPath('data.status', 'VACANT');

        $this->assertDatabaseHas('apartments', [
            'block_id' => $block->id,
            'apartment_number' => $aptNum,
        ]);
    }

    /**
     * Test 07: Báo lỗi khi thêm căn hộ trùng mã trong cùng khối tòa nhà
     */
    public function test_07_cannot_create_duplicate_apartment_in_same_block(): void
    {
        $existing = Apartment::first();
        $this->assertNotNull($existing);

        $payload = [
            'block_id' => $existing->block_id,
            'floor_id' => $existing->floor_id,
            'apartment_number' => $existing->apartment_number,
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/apartments', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * Test 08: Khởi tạo danh sách căn hộ theo tầng hàng loạt (Batch Generate)
     */
    public function test_08_can_batch_generate_apartments_for_floor(): void
    {
        $block = Block::first();
        // Tạo một tầng test riêng để batch generate
        $maxFloor = (int) Floor::where('block_id', $block->id)->max('floor_number');
        $batchFloorNum = $maxFloor + 1;
        $floor = Floor::create([
            'block_id' => $block->id,
            'floor_number' => $batchFloorNum,
            'floor_code' => "TST_F{$batchFloorNum}",
            'floor_name' => "Tầng {$batchFloorNum} Batch",
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 0,
        ]);

        $payload = [
            'block_id' => $block->id,
            'floor_id' => $floor->id,
            'count' => 5,
            'prefix' => "T{$batchFloorNum}-",
            'start_number' => 1,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'status' => 'VACANT',
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/apartments/batch-generate', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created_count', 5);

        // Kiểm tra database có đúng 5 căn: T{batchFloorNum}-01, T{batchFloorNum}-02,...
        for ($i = 1; $i <= 5; $i++) {
            $num = sprintf('T%d-%02d', $batchFloorNum, $i);
            $this->assertDatabaseHas('apartments', [
                'block_id' => $block->id,
                'floor_id' => $floor->id,
                'apartment_number' => $num,
            ]);
        }
    }

    /**
     * Test 09: Cập nhật thông tin chi tiết căn hộ
     */
    public function test_09_can_update_apartment_details(): void
    {
        $apt = Apartment::first();

        $payload = [
            'gross_floor_area_sqm' => 95.0,
            'room_type' => '3_BEDROOM',
            'bedroom_count' => 3,
        ];

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/apartments/{$apt->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.room_type', '3_BEDROOM');

        $this->assertEquals(95.0, (float) $response->json('data.gross_floor_area_sqm'));
    }

    /**
     * Test 10: Gắn trạng thái nhanh: Đã bán, Đang thuê, Trống
     */
    public function test_10_can_update_apartment_status_fast(): void
    {
        $apt = Apartment::first();

        // 1. Chuyển sang Đã bán (OCCUPIED / SOLD)
        $resSold = $this->withHeaders($this->authHeaders())->patchJson("/api/v1/apartments/{$apt->id}/status", [
            'status' => 'DA_BAN',
        ]);
        $resSold->assertStatus(200)->assertJsonPath('data.status', 'OCCUPIED');

        // 2. Chuyển sang Đang thuê (RENTED)
        $resRented = $this->withHeaders($this->authHeaders())->patchJson("/api/v1/apartments/{$apt->id}/status", [
            'status' => 'DANG_THUE',
        ]);
        $resRented->assertStatus(200)->assertJsonPath('data.status', 'RENTED');

        // 3. Chuyển về Trống (VACANT)
        $resVacant = $this->withHeaders($this->authHeaders())->patchJson("/api/v1/apartments/{$apt->id}/status", [
            'status' => 'TRONG',
        ]);
        $resVacant->assertStatus(200)->assertJsonPath('data.status', 'VACANT');
    }

    /**
     * Test 11: Xóa mềm căn hộ
     */
    public function test_11_can_soft_delete_apartment(): void
    {
        $apt = Apartment::create([
            'block_id' => Block::first()->id,
            'floor_id' => Floor::first()->id,
            'apartment_number' => 'DEL-'.rand(1000, 9999),
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'VACANT',
        ]);

        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/v1/apartments/{$apt->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);

        // Kiểm tra soft delete
        $this->assertSoftDeleted('apartments', [
            'id' => $apt->id,
        ]);
    }
}
