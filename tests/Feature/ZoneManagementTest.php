<?php

namespace Tests\Feature;

use App\Models\Zone;
use Tests\TestCase;

class ZoneManagementTest extends TestCase
{
    protected function tearDown(): void
    {
        // Dọn dẹp các record thử nghiệm sau khi chạy test
        Zone::where('zone_code', 'like', 'TEST-%')->forceDelete();
        parent::tearDown();
    }

    /**
     * Kiểm tra API lấy danh sách khối tòa nhà và thống kê (GET /api/v1/admin/zones)
     */
    public function test_can_get_zones_list_and_stats(): void
    {
        Zone::create([
            'zone_code' => 'TEST-LIST-01',
            'zone_name' => 'Tòa Nhà Test Danh Sách',
            'floor_count' => 15,
            'basement_count' => 2,
            'total_apartments' => 120,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/admin/zones');

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
     * Kiểm tra thêm khối tòa nhà thành công (POST /api/v1/admin/zones)
     */
    public function test_can_create_zone_with_valid_data(): void
    {
        $payload = [
            'zone_code' => 'TEST-BK-NEW',
            'zone_name' => 'Khối Sapphire Mới',
            'floor_count' => 28,
            'basement_count' => 3,
            'total_apartments' => 250,
            'status' => 'active',
            'address_line' => 'Phân khu A',
            'hotline_phone' => '0901234567',
            'description' => 'Tòa nhà mới bàn giao',
        ];

        $response = $this->postJson('/api/v1/admin/zones', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'zone_code' => 'TEST-BK-NEW',
                    'zone_name' => 'Khối Sapphire Mới',
                    'floor_count' => 28,
                ],
            ]);

        $this->assertDatabaseHas('zones', [
            'zone_code' => 'TEST-BK-NEW',
            'zone_name' => 'Khối Sapphire Mới',
        ]);
    }

    /**
     * Kiểm tra Validation 422 khi mã khối tòa nhà bị trùng
     */
    public function test_cannot_create_zone_with_duplicate_code(): void
    {
        Zone::create([
            'zone_code' => 'TEST-DUP',
            'zone_name' => 'Khối Đầu Tiên',
            'floor_count' => 10,
            'status' => 'active',
        ]);

        $payload = [
            'zone_code' => 'TEST-DUP',
            'zone_name' => 'Khối Bị Trùng',
            'floor_count' => 12,
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/admin/zones', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zone_code']);
    }

    /**
     * Kiểm tra Validation 422 khi số tầng (floor_count) <= 0
     */
    public function test_cannot_create_zone_with_invalid_floor_count(): void
    {
        $payload = [
            'zone_code' => 'TEST-INVALID-FL',
            'zone_name' => 'Khối Lỗi Số Tầng',
            'floor_count' => 0, // Phải > 0 theo yêu cầu
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/admin/zones', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['floor_count']);
    }

    /**
     * Kiểm tra lấy thông tin chi tiết một khối (GET /api/v1/admin/zones/{id})
     */
    public function test_can_get_single_zone_details(): void
    {
        $zone = Zone::create([
            'zone_code' => 'TEST-SHOW',
            'zone_name' => 'Khối Chi Tiết',
            'floor_count' => 20,
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/v1/admin/zones/{$zone->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $zone->id,
                    'zone_code' => 'TEST-SHOW',
                ],
            ]);
    }

    /**
     * Kiểm tra trả về 404 khi không tìm thấy khối tòa nhà
     */
    public function test_returns_404_when_zone_not_found(): void
    {
        $response = $this->getJson('/api/v1/admin/zones/99999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Kiểm tra cập nhật thành công khi last_updated_at khớp với updated_at
     */
    public function test_can_update_zone_with_matching_last_updated_at(): void
    {
        $zone = Zone::create([
            'zone_code' => 'TEST-UPDATE-OK',
            'zone_name' => 'Khối Cũ',
            'floor_count' => 15,
            'status' => 'active',
        ]);

        $payload = [
            'zone_code' => 'TEST-UPDATE-OK',
            'zone_name' => 'Khối Đã Cập Nhật',
            'floor_count' => 18,
            'status' => 'maintenance',
            'last_updated_at' => $zone->updated_at->toISOString(),
        ];

        $response = $this->putJson("/api/v1/admin/zones/{$zone->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'zone_name' => 'Khối Đã Cập Nhật',
                    'floor_count' => 18,
                    'status' => 'maintenance',
                ],
            ]);
    }

    /**
     * Kiểm tra Optimistic Locking: Trả về 409 Conflict khi last_updated_at bị lệch
     */
    public function test_update_zone_returns_409_conflict_when_optimistic_lock_fails(): void
    {
        $zone = Zone::create([
            'zone_code' => 'TEST-CONFLICT',
            'zone_name' => 'Khối Thử Nghiệm Xung Đột',
            'floor_count' => 10,
            'status' => 'active',
        ]);

        // Giả lập người khác đã cập nhật bản ghi trước đó
        $staleTimestamp = $zone->updated_at->subMinutes(10)->toISOString();

        $payload = [
            'zone_code' => 'TEST-CONFLICT',
            'zone_name' => 'Cố Tình Ghi Đè',
            'floor_count' => 12,
            'status' => 'active',
            'last_updated_at' => $staleTimestamp, // Timestamp cũ không khớp
        ];

        $response = $this->putJson("/api/v1/admin/zones/{$zone->id}", $payload);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'conflict' => true,
            ]);
    }

    /**
     * Kiểm tra xóa mềm khối tòa nhà (DELETE /api/v1/admin/zones/{id})
     */
    public function test_can_soft_delete_zone(): void
    {
        $zone = Zone::create([
            'zone_code' => 'TEST-DELETE',
            'zone_name' => 'Khối Chuẩn Bị Xóa',
            'floor_count' => 10,
            'status' => 'active',
        ]);

        $response = $this->deleteJson("/api/v1/admin/zones/{$zone->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('zones', [
            'id' => $zone->id,
            'zone_code' => 'TEST-DELETE',
        ]);
    }
}
