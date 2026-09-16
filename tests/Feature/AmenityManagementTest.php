<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AmenityManagementTest extends TestCase
{
    /**
     * Kiểm tra lấy danh sách tiện ích có đếm lượt đặt chỗ thật từ database
     */
    public function test_can_list_amenities_with_real_bookings_count(): void
    {
        $response = $this->getJson('/api/v1/admin/amenities');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'items' => [
                    '*' => [
                        'id',
                        'amenity_name',
                        'amenity_code',
                        'max_capacity_per_slot',
                        'active_bookings_count',
                    ],
                ],
                'total',
                'page',
                'limit',
                'total_pages',
            ]);
    }

    /**
     * Nghiệp vụ Slot Capacity (Section 13 & 14):
     * Không cho phép giảm Maximum Slot thấp hơn số lượt đăng ký thực tế hiện tại
     */
    public function test_cannot_reduce_capacity_below_active_bookings(): void
    {
        // Lấy tiện ích đang có lượt đặt chỗ trong database
        $amenityWithBookings = DB::table('amenity_bookings')
            ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($amenityWithBookings, 'Phải có ít nhất 1 tiện ích có booking trong CSDL để kiểm thử.');

        $amenityId = $amenityWithBookings->amenity_id;

        // Thử giảm sức chứa xuống 1 (thấp hơn số người/lượt đặt thực tế)
        $response = $this->putJson("/api/v1/admin/amenities/{$amenityId}", [
            'max_capacity_per_slot' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'detail' => 'Không thể giảm Maximum Slot xuống 1 vì hiện tại đã có 2 lượt đăng ký.',
            ]);
    }

    /**
     * Nghiệp vụ Xóa an toàn (Section 12):
     * Không cho phép xóa tiện ích đang có lượt đặt chỗ hoạt động
     */
    public function test_cannot_delete_amenity_with_active_bookings(): void
    {
        $amenityWithBookings = DB::table('amenity_bookings')
            ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($amenityWithBookings);

        $amenityId = $amenityWithBookings->amenity_id;

        $response = $this->deleteJson("/api/v1/admin/amenities/{$amenityId}");

        $response->assertStatus(409);
        $this->assertStringContainsString('Không thể xóa tiện ích', $response->json('detail'));
    }

    /**
     * Lấy danh sách đặt chỗ thực tế của tiện ích (Section 14)
     */
    public function test_can_get_amenity_bookings_from_database(): void
    {
        $booking = DB::table('amenity_bookings')->first();
        $this->assertNotNull($booking);

        $response = $this->getJson("/api/v1/admin/amenities/{$booking->amenity_id}/bookings");

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
        $this->assertNotEmpty($response->json());

        $firstItem = $response->json()[0];
        $this->assertArrayHasKey('booking_code', $firstItem);
        $this->assertArrayHasKey('resident_name', $firstItem);
        $this->assertArrayHasKey('status', $firstItem);
    }

    /**
     * Nghiệp vụ Tiện ích Tạm ngưng (Section 15):
     * Khi Amenity INACTIVE, người dùng không thể đặt chỗ mới
     */
    public function test_cannot_book_when_amenity_is_inactive(): void
    {
        $amenity = DB::table('amenities')->whereNull('deleted_at')->first();
        $this->assertNotNull($amenity);

        // Chuyển sang tạm ngưng
        DB::table('amenities')->where('id', $amenity->id)->update(['is_active' => 0]);

        $apartment = DB::table('apartments')->first();
        $user = DB::table('users')->first();

        $response = $this->postJson("/api/v1/amenities/{$amenity->id}/bookings", [
            'apartment_id' => $apartment->id,
            'resident_user_id' => $user->id,
            'booking_date' => '2026-09-20',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'attendee_count' => 2,
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment([
                'detail' => 'Tiện ích hiện đang tạm ngưng hoạt động, không thể đặt chỗ mới.',
            ]);

        // Khôi phục lại trạng thái hoạt động
        DB::table('amenities')->where('id', $amenity->id)->update(['is_active' => 1]);
    }

    /**
     * Tìm kiếm tiện ích đa chiều (Section 6 & 7)
     */
    public function test_search_amenities_by_name_and_code(): void
    {
        $response = $this->getJson('/api/v1/admin/amenities?search=tennis');

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertTrue(
            str_contains(strtolower($items[0]['amenity_name']), 'tennis') ||
            str_contains(strtolower($items[0]['amenity_code']), 'tennis')
        );
    }
}
