<?php

namespace Tests\Feature\ReceptionParcel;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use App\Services\ReceptionParcelService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionParcelTest extends TestCase
{
    protected ?User $receptionist = null;

    protected ?string $receptionistToken = null;

    protected ?User $resident = null;

    protected ?Apartment $apartment = null;

    protected ReceptionParcelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->service = app(ReceptionParcelService::class);

        // Tạo nhân viên lễ tân
        [$this->receptionist, $this->receptionistToken] = $this->createStaffUser('RECEPTIONIST');

        // Tạo cư dân & căn hộ
        $this->apartment = $this->getOrCreateApartment('APT-PARCEL-'.Str::random(4));
        $this->resident = $this->createResidentUser($this->apartment);
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_PARCEL_'.Str::random(4),
                'block_name' => 'Tòa Tháp Parcel',
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
                'floor_number' => 8,
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
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);
    }

    protected function createStaffUser(string $roleCode): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Test",
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
            'user_id' => $user->id,
            'role_id' => $roleId,
            'assigned_at' => now(),
        ]);

        return [$user, 'token_'.$user->id];
    }

    protected function createResidentUser(Apartment $apartment): User
    {
        $user = User::create([
            'username' => 'resident_'.Str::random(6),
            'phone_number' => '098'.rand(1000000, 9999999),
            'email' => 'resident_'.Str::random(6).'@gmail.com',
            'password_hash' => Hash::make('secret123'),
            'full_name' => 'Nguyễn Cư Dân Test',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apartment->id,
            'resident_type' => 'PRIMARY_OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        return $user;
    }

    /**
     * Test 1: Lễ tân tiếp nhận kiện hàng thành công với upload file ảnh (multipart/form-data)
     */
    public function test_receptionist_receives_parcel_with_image_upload_successfully(): void
    {
        $photo = UploadedFile::fake()->image('parcel_shopee.jpg', 640, 480);
        $trackingNumber = 'SPX'.rand(100000000, 999999999);

        $response = $this->actingAs($this->receptionist)
            ->post('/api/v1/reception/parcels', [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'Shopee Express',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Nguyễn Cư Dân Test',
                'recipient_phone' => $this->resident->phone_number,
                'stored_location_type' => 'RECEPTION_DESK',
                'notes' => 'Kiện hàng Shopee quần áo',
                'photo' => $photo,
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'Shopee Express',
                'recipient_name' => 'Nguyễn Cư Dân Test',
                'status' => 'RECEIVED_AT_RECEPTION',
            ],
        ]);

        $this->assertDatabaseHas('parcels', [
            'tracking_number' => $trackingNumber,
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân Test',
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // Kiểm tra file ảnh đã được lưu vào Storage public
        $parcel = Parcel::where('tracking_number', $trackingNumber)->first();
        $this->assertNotNull($parcel);
        $this->assertNotEmpty($parcel->parcel_photo_url);
        $this->assertNotEmpty($parcel->pickup_pin_code);
        $this->assertEquals(6, strlen($parcel->pickup_pin_code));
        $this->assertStringStartsWith('PKG-', $parcel->pickup_qr_code);
    }

    /**
     * Test 2: Tiếp nhận kiện hàng thành công với parcel_photo_url có sẵn
     */
    public function test_receive_parcel_with_existing_photo_url(): void
    {
        $trackingNumber = 'GHTK'.rand(100000000, 999999999);

        $response = $this->actingAs($this->receptionist)
            ->post('/api/v1/reception/parcels', [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'Giao Hàng Tiết Kiệm',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Trần Thị B',
                'recipient_phone' => '0912345678',
                'parcel_photo_url' => 'https://storage.cassavas.vn/parcels/sample.jpg',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('parcels', [
            'tracking_number' => $trackingNumber,
            'parcel_photo_url' => 'https://storage.cassavas.vn/parcels/sample.jpg',
        ]);
    }

    /**
     * Test 3: Validation fail khi thiếu mã vận đơn hoặc thiếu căn hộ hoặc thiếu ảnh
     */
    public function test_receive_parcel_validation_fails_when_missing_required_fields(): void
    {
        // Thiếu tracking_number & photo
        $response = $this->actingAs($this->receptionist)
            ->postJson('/api/v1/reception/parcels', [
                'apartment_id' => $this->apartment->id,
                'courier_company' => 'SPX',
                'recipient_name' => 'Lê Văn C',
                'recipient_phone' => '0901234567',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tracking_number']);
    }

    /**
     * Test 4: Từ chối file upload không phải ảnh hợp lệ (ví dụ txt, php)
     */
    public function test_reject_non_image_file_upload(): void
    {
        $fakeScript = UploadedFile::fake()->create('malicious.php', 100, 'text/x-php');

        $response = $this->actingAs($this->receptionist)
            ->post('/api/v1/reception/parcels', [
                'tracking_number' => 'TEST_MAL_'.rand(100, 999),
                'courier_company' => 'Shopee',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Tester',
                'recipient_phone' => '0900000000',
                'photo' => $fakeScript,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 5: Từ chối file upload vượt quá giới hạn 10MB
     */
    public function test_reject_oversized_image_upload(): void
    {
        // Fake file > 10MB (11MB)
        $largePhoto = UploadedFile::fake()->image('huge_parcel.jpg')->size(11 * 1024);

        $response = $this->actingAs($this->receptionist)
            ->post('/api/v1/reception/parcels', [
                'tracking_number' => 'TEST_HUGE_'.rand(100, 999),
                'courier_company' => 'SPX',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Tester',
                'recipient_phone' => '0900000000',
                'photo' => $largePhoto,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 6: Từ chối khi căn hộ không tồn tại
     */
    public function test_reject_when_apartment_does_not_exist(): void
    {
        $nonExistentAptId = (string) Str::uuid();

        $response = $this->actingAs($this->receptionist)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => 'SPX_NOT_EXIST',
                'courier_company' => 'SPX',
                'apartment_id' => $nonExistentAptId,
                'recipient_name' => 'Tester',
                'recipient_phone' => '0900000000',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 7: Nhân viên tiếp nhận được lấy từ phiên đăng nhập server, không tin ID do frontend gửi
     */
    public function test_received_by_staff_id_is_derived_from_authenticated_user(): void
    {
        $fakeStaffId = (string) Str::uuid();
        $trackingNumber = 'SPX_AUTH_'.rand(10000, 99999);

        $this->actingAs($this->receptionist)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'SPX',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận',
                'recipient_phone' => '0911223344',
                'received_by_staff_id' => $fakeStaffId, // Frontend cố tình gửi fake ID
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ]);

        $parcel = Parcel::where('tracking_number', $trackingNumber)->first();
        $this->assertNotNull($parcel);
        // ID nhân viên thực tế phải là lễ tân đang đăng nhập
        $this->assertEquals($this->receptionist->id, $parcel->received_by_staff_id);
        $this->assertNotEquals($fakeStaffId, $parcel->received_by_staff_id);
    }

    /**
     * Test 8: Chống tạo duplicate do double-submit cùng mã vận đơn trong 24 giờ
     */
    public function test_prevent_duplicate_parcels_on_double_submit(): void
    {
        $trackingNumber = 'SPX_DUP_'.rand(10000, 99999);

        // Lần 1: Thành công
        $res1 = $this->actingAs($this->receptionist)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'Shopee',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận A',
                'recipient_phone' => '0911223344',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ]);
        $res1->assertStatus(201);

        // Lần 2 (double-submit cùng mã vận đơn và căn hộ): Bị từ chối
        $res2 = $this->actingAs($this->receptionist)
            ->postJson('/api/v1/reception/parcels', [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'Shopee',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => 'Người Nhận A',
                'recipient_phone' => '0911223344',
                'parcel_photo_url' => 'https://sample.com/photo.jpg',
            ]);
        $res2->assertStatus(422);
    }

    /**
     * Test 9: Hai kiện hàng độc lập có thể được tiếp nhận liên tiếp (không bị cooldown lock 120s)
     */
    public function test_multiple_independent_parcels_can_be_received_consecutively_without_cooldown(): void
    {
        $tracking1 = 'CONSEC_1_'.rand(10000, 99999);
        $tracking2 = 'CONSEC_2_'.rand(10000, 99999);

        $res1 = $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => $tracking1,
            'courier_company' => 'SPX',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận 1',
            'recipient_phone' => '0911111111',
            'parcel_photo_url' => 'https://sample.com/p1.jpg',
        ]);
        $res1->assertStatus(201);

        // Tiếp nhận kiện hàng thứ hai ngay lập tức
        $res2 = $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => $tracking2,
            'courier_company' => 'GHTK',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận 2',
            'recipient_phone' => '0922222222',
            'parcel_photo_url' => 'https://sample.com/p2.jpg',
        ]);
        $res2->assertStatus(201);

        // Module parcels không được rơi vào trạng thái lock cooldown
        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('parcels'));
    }

    /**
     * Test 10: Tải danh sách bưu phẩm có phân trang, KPIs và bộ lọc
     */
    public function test_get_parcels_list_with_pagination_and_kpis(): void
    {
        $tracking = 'LIST_'.rand(10000, 99999);
        $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => $tracking,
            'courier_company' => 'ViettelPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Cư dân List',
            'recipient_phone' => '0933333333',
            'parcel_photo_url' => 'https://sample.com/list.jpg',
        ]);

        $response = $this->actingAs($this->receptionist)->getJson('/api/v1/reception/parcels?search='.$tracking);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data',
            'kpis' => [
                'pending_parcels',
                'overdue_parcels',
                'received_today',
                'total_parcels',
            ],
            'pagination' => [
                'current_page',
                'per_page',
                'total_items',
                'total_pages',
                'has_more',
            ],
        ]);
        $response->assertJsonFragment(['tracking_number' => $tracking]);
    }

    /**
     * Test 11: Realtime event được lưu vào realtime_sync_events sau khi tiếp nhận
     */
    public function test_realtime_event_is_emitted_upon_parcel_reception(): void
    {
        $tracking = 'RT_'.rand(10000, 99999);

        $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => $tracking,
            'courier_company' => 'Shopee',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Cư dân RT',
            'recipient_phone' => '0944444444',
            'parcel_photo_url' => 'https://sample.com/rt.jpg',
        ]);

        $this->assertDatabaseHas('realtime_sync_events', [
            'channel' => 'quoc-tin.parcels',
            'module' => 'parcels',
            'action' => 'CREATED',
        ]);
    }

    /**
     * Test 12: Cập nhật thông tin kiện hàng bưu phẩm và thay thế ảnh an toàn
     */
    public function test_update_parcel_information_and_replace_photo(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'OLD_TRACK_'.rand(100, 999).'_'.Str::random(4),
            'courier_company' => 'Old Courier',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Old Name',
            'recipient_phone' => '0900000001',
            'parcel_photo_url' => 'https://storage.cassavas.vn/parcels/old.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'PKG-OLD-'.Str::random(10),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->putJson("/api/v1/reception/parcels/{$parcel->id}", [
            'recipient_name' => 'New Updated Name',
            'notes' => 'Cập nhật ghi chú mới',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'recipient_name' => 'New Updated Name',
            'notes' => 'Cập nhật ghi chú mới',
        ]);
    }

    /**
     * Test 13: Không cho sửa kiện hàng đã bàn giao (bảo vệ module checkout)
     */
    public function test_cannot_update_delivered_parcel(): void
    {
        $deliveredParcel = Parcel::create([
            'tracking_number' => 'DELIVERED_'.rand(100, 999).'_'.Str::random(4),
            'courier_company' => 'Shopee',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Đã Nhận',
            'recipient_phone' => '0900000002',
            'parcel_photo_url' => 'https://sample.com/del.jpg',
            'pickup_pin_code' => '654321',
            'pickup_qr_code' => 'PKG-DEL-'.Str::random(10),
            'status' => 'DELIVERED', // Đã bàn giao
            'received_at' => now()->subDays(2),
            'received_by_staff_id' => $this->receptionist->id,
            'collected_at' => now(),
        ]);

        $response = $this->actingAs($this->receptionist)->putJson("/api/v1/reception/parcels/{$deliveredParcel->id}", [
            'recipient_name' => 'Tên Sửa Đổi Trái Phép',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test 14: Xóa kiện hàng bưu phẩm chưa bàn giao thành công
     */
    public function test_delete_parcel_successfully(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'DEL_TRACK_'.rand(100, 999).'_'.Str::random(4),
            'courier_company' => 'GrabExpress',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận Cần Xóa',
            'recipient_phone' => '0901234567',
            'parcel_photo_url' => 'https://sample.com/to_delete.jpg',
            'pickup_pin_code' => '999888',
            'pickup_qr_code' => 'PKG-DEL-'.Str::random(10),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->deleteJson("/api/v1/reception/parcels/{$parcel->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('parcels', [
            'id' => $parcel->id,
        ]);
        $this->assertDatabaseHas('realtime_sync_events', [
            'channel' => 'quoc-tin.parcels',
            'module' => 'parcels',
            'action' => 'DELETED',
        ]);
    }

    /**
     * Test 15: Không cho phép xóa kiện hàng đã bàn giao
     */
    public function test_cannot_delete_delivered_parcel(): void
    {
        $deliveredParcel = Parcel::create([
            'tracking_number' => 'CANT_DEL_'.rand(100, 999).'_'.Str::random(4),
            'courier_company' => 'Shopee',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Đã Lấy Hàng',
            'recipient_phone' => '0909999888',
            'parcel_photo_url' => 'https://sample.com/delivered.jpg',
            'pickup_pin_code' => '112233',
            'pickup_qr_code' => 'PKG-CANT-'.Str::random(10),
            'status' => 'DELIVERED',
            'received_at' => now()->subDays(1),
            'received_by_staff_id' => $this->receptionist->id,
            'collected_at' => now(),
        ]);

        $response = $this->actingAs($this->receptionist)->deleteJson("/api/v1/reception/parcels/{$deliveredParcel->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('parcels', [
            'id' => $deliveredParcel->id,
        ]);
    }

    /**
     * Test 16: Lọc bưu phẩm bằng mã PIN chính xác và tra cứu O(log N) bằng idx_parcels_pin_code
     */
    public function test_16_can_filter_and_lookup_parcels_by_exact_pin_code(): void
    {
        $uniquePin1 = (string) rand(100000, 899999);
        $uniquePin2 = (string) rand(900000, 999999);

        $parcel1 = Parcel::create([
            'tracking_number' => 'PIN_TEST_1_'.Str::random(6),
            'courier_company' => 'SPX',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận PIN 1',
            'recipient_phone' => '0901234567',
            'parcel_photo_url' => 'https://sample.com/p1.jpg',
            'pickup_pin_code' => $uniquePin1,
            'pickup_qr_code' => 'PKG-PIN-1-'.Str::random(8),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $parcel2 = Parcel::create([
            'tracking_number' => 'PIN_TEST_2_'.Str::random(6),
            'courier_company' => 'GHN',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận PIN 2',
            'recipient_phone' => '0907654321',
            'parcel_photo_url' => 'https://sample.com/p2.jpg',
            'pickup_pin_code' => $uniquePin2,
            'pickup_qr_code' => 'PKG-PIN-2-'.Str::random(8),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // 1. Kiểm tra qua API với bộ lọc pin_code
        $response = $this->actingAs($this->receptionist)->getJson("/api/v1/reception/parcels?pin_code={$uniquePin1}");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($parcel1->id, $data[0]['id']);
        $this->assertEquals($uniquePin1, $data[0]['pickup_pin_code']);

        // 2. Kiểm tra phương thức tra cứu trực tiếp findParcelByPin
        $foundParcel = $this->service->findParcelByPin($uniquePin2);
        $this->assertNotNull($foundParcel);
        $this->assertEquals($parcel2->id, $foundParcel->id);

        // 3. Tra cứu mã PIN không tồn tại trả về null
        $notFound = $this->service->findParcelByPin('000000');
        $this->assertNull($notFound);
    }
}
