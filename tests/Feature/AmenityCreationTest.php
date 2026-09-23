<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AmenityCreationTest extends TestCase
{
    /**
     * Test tạo mới tiện ích thành công và xác minh dữ liệu được lưu thật vào CSDL.
     * Giải quyết triệt để lỗi "Bấm Lưu nhưng bản ghi không được lưu".
     */
    public function test_can_create_amenity_and_persist_to_database(): void
    {
        $category = DB::table('amenity_categories')->first();
        $this->assertNotNull($category, 'Cần ít nhất một danh mục tiện ích trong CSDL.');

        $uniqueSuffix = time();
        $payload = [
            'category_id' => $category->id,
            'amenity_name' => "Sân Thể Thao Tự Động {$uniqueSuffix}",
            'amenity_code' => "AUTO_SPORT_{$uniqueSuffix}",
            'location_detail' => 'Khu liên hợp thể thao tầng 5',
            'max_capacity_per_slot' => 6,
            'hourly_rate' => 150000,
            'security_deposit_required' => 50000,
            'advance_booking_days_limit' => 7,
            'min_cancel_hours_before' => 2,
            'requires_admin_approval' => false,
            'is_active' => true,
        ];

        // 1. Gửi request tạo mới tiện ích
        $response = $this->postJson('/api/v1/admin/amenities', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'amenity_name' => "Sân Thể Thao Tự Động {$uniqueSuffix}",
                'amenity_code' => "AUTO_SPORT_{$uniqueSuffix}",
                'max_capacity_per_slot' => 6,
            ]);

        $createdId = $response->json('id');
        $this->assertNotEmpty($createdId);

        // 2. Xác minh bản ghi thực sự tồn tại trong CSDL
        $this->assertDatabaseHas('amenities', [
            'id' => $createdId,
            'amenity_code' => "AUTO_SPORT_{$uniqueSuffix}",
            'amenity_name' => "Sân Thể Thao Tự Động {$uniqueSuffix}",
        ]);

        // 3. Truy vấn lại qua API chi tiết để kiểm chứng persistence
        $getDetail = $this->getJson("/api/v1/admin/amenities/{$createdId}");
        $getDetail->assertStatus(200)
            ->assertJsonFragment([
                'id' => $createdId,
                'amenity_code' => "AUTO_SPORT_{$uniqueSuffix}",
            ]);

        // Dọn dẹp bản ghi kiểm thử
        DB::table('amenities')->where('id', $createdId)->delete();
    }

    /**
     * Test khi cố tình tạo mã tiện ích trùng lặp sẽ trả về 422 Unprocessable Entity
     * (Đây là nguyên nhân gốc rễ trước đây khi form modal bị hardcode mã readonly gây lỗi lưu).
     */
    public function test_cannot_create_amenity_with_duplicate_code(): void
    {
        $existingAmenity = DB::table('amenities')->whereNull('deleted_at')->first();
        $this->assertNotNull($existingAmenity);

        $category = DB::table('amenity_categories')->first();

        $payload = [
            'category_id' => $category->id,
            'amenity_name' => 'Tiện ích Trùng Mã',
            'amenity_code' => $existingAmenity->amenity_code, // Trùng mã đã có
            'location_detail' => 'Tầng 1',
            'max_capacity_per_slot' => 10,
            'hourly_rate' => 0,
        ];

        $response = $this->postJson('/api/v1/admin/amenities', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'detail' => "Mã tiện ích '{$existingAmenity->amenity_code}' đã tồn tại trong hệ thống. Vui lòng chọn mã khác.",
            ]);
    }
}
