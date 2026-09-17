<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\Drivers\SmartSearchDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tự động sử dụng SQLite khi chạy trên máy trạm host ngoài container Docker
        if (config('database.connections.mysql.host') === 'db' && ! file_exists('/.dockerenv')) {
            config(['database.default' => 'sqlite']);
            config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
            DB::purge();
        }
    }

    /**
     * Lấy hoặc tạo category_id tạm dùng cho test data
     */
    protected function getOrCreateTestCategoryId(): string
    {
        $existing = DB::table('amenity_categories')->first();
        if ($existing) {
            return $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('amenity_categories')->insert([
            'id' => $id,
            'category_name' => 'Test Category '.rand(1000, 9999),
            'category_code' => 'TEST_CAT_'.rand(1000, 9999),
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * Test API gợi ý tìm kiếm Autocomplete / Typeahead dưới 5 kết quả
     */
    public function test_can_get_amenity_autocomplete_suggestions(): void
    {
        $firstAmenity = DB::table('amenities')->whereNull('deleted_at')->first();
        $this->assertNotNull($firstAmenity, 'Cần ít nhất 1 tiện ích trong CSDL');

        $queryPrefix = mb_substr($firstAmenity->amenity_name, 0, 3);

        $response = $this->getJson('/api/v1/search/suggestions?q='.urlencode($queryPrefix).'&type=amenities&limit=5');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'suggestions' => [
                    '*' => [
                        'id',
                        'label',
                        'code',
                        'type',
                    ],
                ],
                'meta' => [
                    'query',
                    'count',
                    'search_time_ms',
                ],
            ]);

        $this->assertLessThanOrEqual(5, count($response->json('suggestions')));
    }

    /**
     * Test tìm kiếm Full-Text danh sách tiện ích có trả về metadata đo lường search_time_ms
     */
    public function test_fulltext_search_amenities_fast(): void
    {
        $firstAmenity = DB::table('amenities')->whereNull('deleted_at')->first();
        $this->assertNotNull($firstAmenity);

        $keyword = explode(' ', $firstAmenity->amenity_name)[0] ?? 'GYM';

        // Warm up
        $this->getJson('/api/v1/admin/amenities?limit=1');

        $startTime = microtime(true);
        $response = $this->getJson('/api/v1/admin/amenities?search='.urlencode($keyword).'&limit=10');
        $durationMs = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200)
            ->assertJsonStructure([
                'items',
                'total',
                'page',
                'limit',
                'total_pages',
                'search_time_ms',
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $this->assertLessThan(50, (float) $response->json('search_time_ms'), 'Search engine execution time must be under 50ms.');
        $this->assertLessThan(1000, $durationMs, 'API search latency must be within reasonable threshold.');
    }

    /**
     * Test toàn bộ các cụm từ tìm kiếm bắt buộc theo Section 18:
     * - ku nghi duon -> Khu Nghỉ Dưỡng
     * - khu nghi duong -> Khu Nghỉ Dưỡng
     * - khu nghi -> Khu Nghỉ Dưỡng
     * - nghi duong -> Khu Nghỉ Dưỡng
     * - phong gim -> Phòng Gym
     * - phong gym -> Phòng Gym
     * - ho boi -> Hồ Bơi
     * - boi -> Hồ Bơi
     * - san tenit -> Sân Tennis
     * - san tennis -> Sân Tennis
     * - bbq -> Khu BBQ
     * - gym toa a -> Phòng Gym Tòa A
     */
    public function test_smart_search_required_phrases_accuracy(): void
    {
        $driver = new SmartSearchDriver;

        $cases = [
            'ku nghi duon' => 'Khu Nghỉ Dưỡng',
            'khu nghi duong' => 'Khu Nghỉ Dưỡng',
            'khu nghi' => 'Khu Nghỉ Dưỡng',
            'nghi duong' => 'Khu Nghỉ Dưỡng',
            'phong gim' => 'Phòng Gym',
            'phong gym' => 'Phòng Gym',
            'ho boi' => 'Hồ Bơi',
            'boi' => 'Hồ Bơi',
            'san tenit' => 'Sân Tennis',
            'san tennis' => 'Sân Tennis',
            'bbq' => 'Khu BBQ',
            'gym toa a' => 'Phòng Gym Tòa A',
        ];

        foreach ($cases as $query => $expectedName) {
            $result = $driver->search('amenities', $query);

            $this->assertNotEmpty($result->items, "Truy vấn '{$query}' phải trả về ít nhất 1 kết quả.");
            $topItem = $result->items[0];
            $this->assertEquals(
                $expectedName,
                $topItem['amenity_name'],
                "Truy vấn '{$query}' phải xếp hạng '{$expectedName}' ở vị trí đầu tiên."
            );
            $this->assertGreaterThanOrEqual(
                0.70,
                $topItem['relevance_score'],
                "Điểm tương quan của '{$expectedName}' cho truy vấn '{$query}' phải >= 0.70."
            );
        }
    }

    /**
     * Test API endpoint GET /api/amenities/search theo chuẩn Section 14
     */
    public function test_smart_search_api_endpoint_response_structure(): void
    {
        $response = $this->getJson('/api/amenities/search?q='.urlencode('ku nghi duon'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'amenity_name',
                        'location_detail',
                        'relevance_score',
                    ],
                ],
                'query',
                'normalized_query',
                'corrected_query',
                'total',
                'search_time_ms',
            ]);

        $this->assertEquals('ku nghi duon', $response->json('query'));
        $this->assertEquals('khu nghi duong', $response->json('corrected_query'));
        $this->assertGreaterThanOrEqual(1, $response->json('total'));

        $firstItem = $response->json('data.0');
        $this->assertEquals('Khu Nghỉ Dưỡng', $firstItem['amenity_name']);
        $this->assertGreaterThanOrEqual(0.85, (float) $firstItem['relevance_score']);
        $this->assertLessThan(50, (float) $response->json('search_time_ms'));
    }

    /**
     * Test các trường hợp xấu theo Section 19 không làm API crash
     */
    public function test_smart_search_edge_cases_do_not_crash(): void
    {
        $edgeQueries = [
            '',
            ' ',
            '   ',
            'xyzabc',
            '123456',
            '!@#$%^&*()_+',
            '   Khu   Nghỉ   Dưỡng   ',
            str_repeat('gym ', 50),
            'KHU NGHỈ DƯỠNG',
            'khu nghi duong',
            'cần tìm tiện ích có ký tự lạ: 𝒯𝑒𝓈𝓉 🇻🇳',
        ];

        foreach ($edgeQueries as $q) {
            $response = $this->getJson('/api/amenities/search?q='.urlencode($q));
            $response->assertStatus(200);
            $this->assertArrayHasKey('data', $response->json());
            $this->assertArrayHasKey('total', $response->json());
            $this->assertArrayHasKey('search_time_ms', $response->json());
        }
    }

    /**
     * Test xóa tiện ích (Soft delete) đồng bộ loại bỏ khỏi kết quả tìm kiếm
     */
    public function test_search_soft_deleted_amenity_not_returned(): void
    {
        $categoryId = $this->getOrCreateTestCategoryId();

        $tempId = (string) Str::uuid();
        DB::table('amenities')->insert([
            'id' => $tempId,
            'category_id' => $categoryId,
            'amenity_name' => 'Khu Vui Choi Xoa Thu Nghiem',
            'amenity_code' => 'TEST_DEL_'.rand(1000, 9999),
            'location_detail' => 'Khu vực test tầng trệt',
            'max_capacity_per_slot' => 10,
            'hourly_rate' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resBefore = $this->getJson('/api/v1/admin/amenities?search=Xoa+Thu+Nghiem');
        $this->assertTrue(collect($resBefore->json('items'))->contains('id', $tempId));

        $delResponse = $this->deleteJson("/api/v1/admin/amenities/{$tempId}");
        $delResponse->assertStatus(200);

        $resAfter = $this->getJson('/api/v1/admin/amenities?search=Xoa+Thu+Nghiem');
        $this->assertFalse(collect($resAfter->json('items'))->contains('id', $tempId));
    }

    /**
     * Test khớp chính xác mã tiện ích (Exact Code Match) luôn đứng đầu danh sách
     */
    public function test_search_exact_code_ranks_first(): void
    {
        $firstAmenity = DB::table('amenities')->whereNull('deleted_at')->first();
        $this->assertNotNull($firstAmenity);

        $response = $this->getJson('/api/v1/admin/amenities?search='.urlencode($firstAmenity->amenity_code));
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertNotEmpty($items);
        $this->assertEquals($firstAmenity->amenity_code, $items[0]['amenity_code'], 'Exact code match must appear as first item.');
    }

    /**
     * Test tìm kiếm tiếng Việt không dấu khớp tiện ích có dấu
     */
    public function test_search_vietnamese_unaccented_finds_accented_amenity(): void
    {
        $categoryId = $this->getOrCreateTestCategoryId();
        $tempId = (string) Str::uuid();
        DB::table('amenities')->insert([
            'id' => $tempId,
            'category_id' => $categoryId,
            'amenity_name' => 'Sân Cầu Lông Độc Bản Tháp C',
            'amenity_code' => 'TEST_SCL_'.rand(1000, 9999),
            'location_detail' => 'Tầng thượng Tháp C',
            'max_capacity_per_slot' => 4,
            'hourly_rate' => 50000,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/amenities?search=cau+long+doc+ban');
        $response->assertStatus(200);

        $matchedIds = collect($response->json('items'))->pluck('id')->all();
        $this->assertContains($tempId, $matchedIds, 'Unaccented search must find accented amenity.');

        DB::table('amenities')->where('id', $tempId)->delete();
    }

    /**
     * Test tìm kiếm nhiều từ không phân biệt thứ tự (Word-order invariant)
     */
    public function test_search_multi_token_word_order_invariant(): void
    {
        $categoryId = $this->getOrCreateTestCategoryId();
        $tempId = (string) Str::uuid();
        DB::table('amenities')->insert([
            'id' => $tempId,
            'category_id' => $categoryId,
            'amenity_name' => 'Hồ Bơi Vô Cực Nước Mặn',
            'amenity_code' => 'TEST_HBVC_'.rand(1000, 9999),
            'location_detail' => 'Khu nghỉ dưỡng tầng 5',
            'max_capacity_per_slot' => 20,
            'hourly_rate' => 30000,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/amenities?search='.urlencode('Nước Mặn Hồ Bơi'));
        $response->assertStatus(200);

        $matchedIds = collect($response->json('items'))->pluck('id')->all();
        $this->assertContains($tempId, $matchedIds, 'Multi-token search must match regardless of word order.');

        DB::table('amenities')->where('id', $tempId)->delete();
    }

    /**
     * Test phân quyền RBAC: Người dùng không có quyền AMENITY:VIEW bị chặn 403
     */
    public function test_search_rbac_permission_enforcement(): void
    {
        // 1. Tạo user giả lập không có vai trò hay quyền gì
        $noPermUserId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $noPermUserId,
            'username' => 'test_no_perm_'.rand(1000, 9999),
            'full_name' => 'Test User No Perm',
            'phone_number' => '0987'.rand(100000, 999999),
            'email' => 'noperm_'.rand(1000, 9999).'@example.com',
            'password_hash' => bcrypt('secret'),
            'created_at' => now(),
        ]);

        $responseNoPerm = $this->withHeaders([
            'X-User-Id' => $noPermUserId,
        ])->getJson('/api/amenities/search?q=gym');

        $responseNoPerm->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Bạn không có quyền xem tiện ích (AMENITY:VIEW)',
            ]);

        // 2. Gán quyền SUPER_ADMIN cho user
        $adminRoleId = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->value('id');
        if (! $adminRoleId) {
            $adminRoleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $adminRoleId,
                'role_code' => 'SUPER_ADMIN',
                'role_name' => 'Quản trị viên cấp cao',
                'created_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $noPermUserId,
            'role_id' => $adminRoleId,
        ]);

        $responseWithPerm = $this->withHeaders([
            'X-User-Id' => $noPermUserId,
        ])->getJson('/api/amenities/search?q=gym');

        $responseWithPerm->assertStatus(200);

        // Dọn dẹp
        DB::table('user_roles')->where('user_id', $noPermUserId)->delete();
        DB::table('users')->where('id', $noPermUserId)->delete();
    }
}
