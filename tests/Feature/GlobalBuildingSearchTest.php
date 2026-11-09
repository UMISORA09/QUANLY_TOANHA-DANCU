<?php

namespace Tests\Feature;

use App\Models\AccessCard;
use App\Models\Block;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Tests\TestCase;

class GlobalBuildingSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected string $adminToken;

    protected User $residentUser;

    protected string $residentToken;

    protected User $otherResidentUser;

    protected string $otherResidentToken;

    protected string $blockId;

    protected string $apartmentIdA;

    protected string $apartmentIdB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo Block & Apartments
        $this->blockId = (string) Str::uuid();
        $floorId = (string) Str::uuid();
        $this->apartmentIdA = (string) Str::uuid();
        $this->apartmentIdB = (string) Str::uuid();

        DB::table('blocks')->insert([
            'id' => $this->blockId,
            'block_code' => 'BLK-TST-'.Str::random(6),
            'block_name' => 'Toà Kiểm Thử A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $this->blockId,
            'floor_number' => 10,
            'floor_code' => 'FL-10',
            'floor_name' => 'Tầng 10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('apartments')->insert([
            'id' => $this->apartmentIdA,
            'block_id' => $this->blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'A-1001',
            'gross_floor_area_sqm' => 85.5,
            'net_usable_area_sqm' => 78.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('apartments')->insert([
            'id' => $this->apartmentIdB,
            'block_id' => $this->blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'B-1002',
            'gross_floor_area_sqm' => 92.0,
            'net_usable_area_sqm' => 84.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Tạo Admin User
        $this->adminUser = User::factory()->create([
            'full_name' => 'Quản Trị Viên Trưởng',
            'email' => 'admin_test_'.Str::random(8).'@smartcassava.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'status' => 'ACTIVE',
        ]);
        $adminRoleId = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->value('id');
        if (! $adminRoleId) {
            $adminRoleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $adminRoleId, 'role_code' => 'SUPER_ADMIN', 'role_name' => 'Super Administrator']);
        }
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->adminUser->id,
            'role_id' => $adminRoleId,
            'assigned_at' => now(),
        ]);
        $this->adminUser->load('roles');
        $this->adminToken = $this->createBearerToken($this->adminUser);

        // 3. Tạo Resident A (căn hộ A-1001)
        $this->residentUser = User::factory()->create([
            'full_name' => 'Nguyễn Văn Minh Anh',
            'email' => 'minhanh_'.Str::random(8).'@smartcassava.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);
        Resident::factory()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'apartment_id' => $this->apartmentIdA,
            'resident_type' => 'OWNER',
            'is_active' => true,
        ]);
        $this->residentToken = $this->createBearerToken($this->residentUser);

        // 4. Tạo Resident B (căn hộ B-1002)
        $this->otherResidentUser = User::factory()->create([
            'full_name' => 'Trần Thị Mỹ Duyên',
            'email' => 'myduyen_'.Str::random(8).'@smartcassava.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);
        Resident::factory()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->otherResidentUser->id,
            'apartment_id' => $this->apartmentIdB,
            'resident_type' => 'TENANT',
            'is_active' => true,
        ]);
        $this->otherResidentToken = $this->createBearerToken($this->otherResidentUser);
    }

    protected function createBearerToken(User $user): string
    {
        $token = 'test_token_'.$user->id.'_'.Str::random(40);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Test 1: Khách vãng lai chưa đăng nhập bị chặn 401 Unauthorized
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/search?q=test');
        $response->assertStatus(401);
    }

    /**
     * Test 2: Endpoint Health Check trả về trạng thái Meilisearch
     */
    public function test_search_health_check_endpoint(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'engine',
                'available',
                'supported_types',
            ]);
    }

    /**
     * Test 3: Truy vấn rỗng trả về cấu trúc mảng rỗng hợp lệ
     */
    public function test_empty_query_returns_empty_data(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q=');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [],
                'meta' => [
                    'query' => '',
                    'total' => 0,
                ],
            ]);
    }

    /**
     * Test 4: Truy vấn quá dài (> 100 ký tự) bị từ chối 422 Unprocessable Entity
     */
    public function test_query_exceeding_max_length_is_rejected(): void
    {
        $longQuery = str_repeat('a', 101);
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.$longQuery);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    /**
     * Test 5: Tìm kiếm cư dân bằng tên tiếng Việt có dấu
     */
    public function test_search_resident_by_vietnamese_diacritics(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.urlencode('Minh Anh').'&type=resident');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $items = $response->json('data');
        $this->assertNotEmpty($items, 'Phải tìm thấy ít nhất 1 kết quả cư dân');
        $first = $items[0];
        $this->assertEquals('resident', $first['type']);
        $this->assertStringContainsString('Minh Anh', $first['title']);
    }

    /**
     * Test 6: Tra cứu chính xác biển số xe
     */
    public function test_exact_lookup_vehicle_license_plate(): void
    {
        $existing = Vehicle::whereNotNull('license_plate')->first();
        $this->assertNotNull($existing, 'Cần ít nhất 1 phương tiện trong CSDL');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.$existing->license_plate.'&type=vehicle');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Phải tìm thấy phương tiện với biển số chính xác');
        $this->assertEquals($existing->license_plate, $data[0]['identifier']);
    }

    /**
     * Test 7: Tra cứu chính xác mã thẻ RFID
     */
    public function test_exact_lookup_rfid_card(): void
    {
        $existing = AccessCard::whereNotNull('card_number')->first();
        $this->assertNotNull($existing, 'Cần ít nhất 1 thẻ RFID trong CSDL');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.$existing->card_number.'&type=access_card');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Phải tìm thấy thẻ RFID');
        $this->assertEquals($existing->card_number, $data[0]['identifier']);
    }

    /**
     * Test 8: Tra cứu bưu phẩm bằng mã vận đơn (tracking number) & đảm bảo an toàn mã PIN/QR
     */
    public function test_lookup_parcel_by_tracking_number(): void
    {
        $existing = Parcel::whereNotNull('tracking_number')->first();
        $this->assertNotNull($existing, 'Cần ít nhất 1 bưu phẩm trong CSDL');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.$existing->tracking_number.'&type=parcel');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Phải tìm thấy bưu phẩm');
        $this->assertEquals($existing->tracking_number, $data[0]['identifier']);

        // BẢO MẬT: Đảm bảo mã PIN và mã QR bí mật KHÔNG bị rò rỉ trong payload
        $rawResponse = json_encode($data);
        if (! empty($existing->pickup_pin_code)) {
            $this->assertStringNotContainsString($existing->pickup_pin_code, $rawResponse, 'Mã PIN bưu phẩm không được phép xuất hiện');
        }
        if (! empty($existing->pickup_qr_code)) {
            $this->assertStringNotContainsString($existing->pickup_qr_code, $rawResponse, 'Mã QR bưu phẩm không được phép xuất hiện');
        }
    }

    /**
     * Test 9: Phân quyền RBAC - Cư dân chỉ tìm thấy dữ liệu trong căn hộ của mình
     */
    public function test_resident_scope_isolation_between_apartments(): void
    {
        // Tạo bưu phẩm cho Resident B ở căn hộ B-1002
        $trackingNumberB = 'SPX_TEST_'.rand(10000000, 99999999);
        $parcel = Parcel::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => $trackingNumberB,
            'courier_company' => 'Shopee Express',
            'apartment_id' => $this->apartmentIdB,
            'recipient_user_id' => $this->otherResidentUser->id,
            'recipient_name' => 'Trần Thị Mỹ Duyên',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://via.placeholder.com/150',
            'received_by_staff_id' => $this->adminUser->id,
            'status' => 'RECEIVED_AT_RECEPTIO',
            'pickup_pin_code' => '112233',
            'pickup_qr_code' => 'SECRET_QR_'.Str::random(16),
        ]);
        $meili = new Client(
            config('scout.meilisearch.host', 'http://meilisearch:7700'),
            config('scout.meilisearch.key', 'masterKey123456')
        );
        $searchableArray = $parcel->toSearchableArray();
        $task = $meili->index('parcels')->addDocuments([$searchableArray]);
        $taskStatus = $meili->waitForTask($task['taskUid'], 5000);
        if (($taskStatus['status'] ?? '') !== 'succeeded') {
            dump(['task_failed' => $taskStatus]);
        }

        // Resident A tìm kiếm bưu phẩm của Resident B -> KHÔNG được thấy (bị chặn server-side)
        $responseA = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/search?q='.$trackingNumberB.'&type=parcel');

        $responseA->assertStatus(200);
        $this->assertEmpty($responseA->json('data'), 'Cư dân A tuyệt đối không được thấy bưu phẩm của căn hộ khác');

        // Admin tìm kiếm -> THẤY ĐẦY ĐỦ
        $responseAdmin = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q='.$trackingNumberB.'&type=parcel');

        $responseAdmin->assertStatus(200);
        $this->assertNotEmpty($responseAdmin->json('data'), 'Quản trị viên phải thấy bưu phẩm');
    }

    /**
     * Test 10: Che thông tin cá nhân (SĐT, CCCD) cho người không đủ quyền quản lý
     */
    public function test_sensitive_personal_data_masking(): void
    {
        // Khi Resident A tìm thấy thông tin một người khác trong căn hộ, SĐT phải được che
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/search?q='.urlencode('Minh Anh').'&type=resident');

        $response->assertStatus(200);
        $items = $response->json('data');
        if (! empty($items)) {
            $phone = $items[0]['metadata']['phone_number'] ?? '';
            // Resident không có quyền RESIDENT:VIEW toàn quyền thì SĐT phải được che dạng 098****321
            if ($phone !== null && $phone !== '') {
                $this->assertMatchesRegularExpression('/^0\d{2}\*{4}\d{3}$/', $phone, 'SĐT của cư dân phải được che mặt định');
            }
        }
    }

    /**
     * Test 11: Lọc theo trạng thái và loại dữ liệu
     */
    public function test_filter_by_type_and_status(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->getJson('/api/v1/search?q=A&type=vehicle&status=ACTIVE');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals('vehicle', $item['type']);
            if (isset($item['status'])) {
                $this->assertEquals('ACTIVE', $item['status']);
            }
        }
    }

    /**
     * Test 12: Kiểm tra tính minh bạch của cơ chế Fallback
     */
    public function test_database_fallback_is_transparent(): void
    {
        // Khởi tạo GlobalSearchService với meiliAvailable = false để giả lập Meilisearch offline
        $service = new class extends GlobalSearchService
        {
            public function __construct()
            {
                parent::__construct();
                $this->meiliAvailable = false;
                $this->meiliError = 'Connection refused (Simulated Downtime)';
            }
        };

        $result = $service->search($this->adminUser, ['q' => 'Minh Anh']);

        $this->assertEquals('database_fallback', $result['meta']['engine']);
        $this->assertTrue($result['meta']['is_fallback']);
        $this->assertIsArray($result['data']);
    }
}
