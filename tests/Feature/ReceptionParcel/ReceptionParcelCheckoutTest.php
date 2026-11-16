<?php

namespace Tests\Feature\ReceptionParcel;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\User;
use App\Services\ReceptionParcelService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionParcelCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    protected ReceptionParcelService $service;

    protected User $receptionist;

    protected Apartment $apartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReceptionParcelService::class);
        $this->receptionist = $this->createStaffUser('RECEPTIONIST');
        $this->apartment = $this->getOrCreateApartment('APT-CHK-101');
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_CHK_'.Str::random(4),
                'block_name' => 'Tòa Tháp Checkout',
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
                'floor_number' => 5,
                'floor_code' => 'FL-05',
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

    protected function createStaffUser(string $roleCode): User
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
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $roleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    /**
     * Bàn giao bưu phẩm thành công và ghi nhận lịch sử kiểm toán của nhân viên
     */
    public function test_can_checkout_parcel_successfully_and_records_staff_audit(): void
    {
        $uniquePin = '789123';
        $parcel = Parcel::create([
            'tracking_number' => 'CHECKOUT_TEST_'.Str::random(6),
            'courier_company' => 'Shopee Express',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => $uniquePin,
            'pickup_qr_code' => 'PKG-CO-'.Str::random(8),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $payload = [
            'collected_by_name' => 'Nguyễn Cư Dân (Chính chủ)',
            'pin_code' => $uniquePin,
            'notes' => 'Cư dân xuất trình căn cước và đối chiếu đúng mã PIN.',
        ];

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => 'DELIVERED',
            'collected_by_name' => 'Nguyễn Cư Dân (Chính chủ)',
            'checkout_staff_id' => $this->receptionist->id,
        ]);

        $refreshed = Parcel::find($parcel->id);
        $this->assertNotNull($refreshed->collected_at);
        $this->assertStringContainsString('Cư dân xuất trình', (string) $refreshed->notes);
    }

    /**
     * Từ chối bàn giao với HTTP 409 Conflict nếu bưu phẩm đã được nhận trước đó
     */
    public function test_checkout_fails_with_409_if_parcel_already_delivered(): void
    {
        $deliveredParcel = Parcel::create([
            'tracking_number' => 'CONFLICT_TEST_'.Str::random(6),
            'courier_company' => 'GHTK',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Trần Thị B',
            'recipient_phone' => '0933333333',
            'parcel_photo_url' => 'https://sample.com/delivered.jpg',
            'pickup_pin_code' => '112233',
            'pickup_qr_code' => 'PKG-CO-'.Str::random(8),
            'status' => 'DELIVERED',
            'received_at' => now()->subDay(),
            'collected_at' => now()->subHours(2),
            'collected_by_name' => 'Trần Thị B',
            'received_by_staff_id' => $this->receptionist->id,
            'checkout_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/reception/parcels/{$deliveredParcel->id}/check-out", [
            'collected_by_name' => 'Người lạ',
        ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['message']);
    }

    /**
     * Từ chối bàn giao với HTTP 422 nếu mã PIN đối chiếu không khớp
     */
    public function test_checkout_fails_with_422_if_pin_code_does_not_match(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'WRONG_PIN_'.Str::random(6),
            'courier_company' => 'VNPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Lê Văn C',
            'recipient_phone' => '0944444444',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '654321',
            'pickup_qr_code' => 'PKG-CO-'.Str::random(8),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
            'pin_code' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pin_code']);
    }

    /**
     * Tra cứu nhanh bằng mã PIN, mã QR hoặc mã vận đơn
     */
    public function test_can_lookup_parcel_by_pin_or_qr_or_tracking_number(): void
    {
        $pin = '998877';
        $qr = 'QR-FAST-'.Str::random(8);
        $tracking = 'VNPOST-FAST-'.Str::random(8);

        $parcel = Parcel::create([
            'tracking_number' => $tracking,
            'courier_company' => 'VNPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Phạm Văn D',
            'recipient_phone' => '0988888888',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => $pin,
            'pickup_qr_code' => $qr,
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // 1. Tra cứu bằng mã PIN
        $resPin = $this->actingAs($this->receptionist)->getJson("/api/v1/reception/parcels/lookup?code={$pin}");
        $resPin->assertStatus(200);
        $resPin->assertJson([
            'success' => true,
            'can_checkout' => true,
            'data' => [
                'id' => $parcel->id,
                'tracking_number' => $tracking,
            ],
        ]);

        // 2. Tra cứu bằng mã QR
        $resQr = $this->actingAs($this->receptionist)->getJson("/api/v1/reception/parcels/lookup?code={$qr}");
        $resQr->assertStatus(200);
        $resQr->assertJson([
            'success' => true,
            'can_checkout' => true,
            'data' => [
                'id' => $parcel->id,
            ],
        ]);

        // 3. Tra cứu bằng mã vận đơn
        $resTrk = $this->actingAs($this->receptionist)->getJson("/api/v1/reception/parcels/lookup?code={$tracking}");
        $resTrk->assertStatus(200);
        $resTrk->assertJson([
            'success' => true,
            'can_checkout' => true,
        ]);
    }

    /**
     * Tra cứu kiện hàng đã bàn giao trả về can_checkout = false
     */
    public function test_lookup_returns_can_checkout_false_if_already_delivered(): void
    {
        $pin = '554433';
        $deliveredParcel = Parcel::create([
            'tracking_number' => 'DELIVERED_LOOKUP_'.Str::random(6),
            'courier_company' => 'J&T Express',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Hoàng Văn E',
            'recipient_phone' => '0977777777',
            'parcel_photo_url' => 'https://sample.com/delivered.jpg',
            'pickup_pin_code' => $pin,
            'pickup_qr_code' => 'PKG-CO-'.Str::random(8),
            'status' => 'DELIVERED',
            'received_at' => now()->subDay(),
            'collected_at' => now()->subHours(1),
            'collected_by_name' => 'Hoàng Văn E',
            'received_by_staff_id' => $this->receptionist->id,
            'checkout_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->getJson("/api/v1/reception/parcels/lookup?code={$pin}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'can_checkout' => false,
            'data' => [
                'id' => $deliveredParcel->id,
                'status' => 'DELIVERED',
            ],
        ]);
    }

    /**
     * Helper tạo phiên đăng nhập hợp lệ cho người dùng
     */
    protected function createSessionForUser(User $user): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * P0 TEST: Từ chối bàn giao với HTTP 401 nếu request chưa xác thực (Guest)
     */
    public function test_checkout_unauthenticated_returns_401(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'UNAUTH_TEST_'.Str::random(6),
            'courier_company' => 'Shopee Express',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR_UNAUTH_'.Str::random(6),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
            'pin_code' => '123456',
        ]);

        $response->assertStatus(401);
    }

    /**
     * P0 TEST: Từ chối token tự chế chứa UUID người dùng mà không có session hợp lệ -> 401
     */
    public function test_checkout_with_forged_uuid_token_returns_401(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'FORGED_TEST_'.Str::random(6),
            'courier_company' => 'GHTK',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '654321',
            'pickup_qr_code' => 'QR_FORGED_'.Str::random(6),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        // Token tự tạo chứa UUID của receptionist nhưng không có bản ghi trong user_sessions
        $fakeToken = 'smart_token_'.$this->receptionist->id.'_'.Str::random(40);

        $response = $this->withHeader('Authorization', 'Bearer '.$fakeToken)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
                'pin_code' => '654321',
            ]);

        $response->assertStatus(401);
    }

    /**
     * P0 TEST: Người dùng đã đăng nhập nhưng không có quyền (Cư dân) gọi bàn giao -> 403
     */
    public function test_checkout_unauthorized_role_returns_403(): void
    {
        $resident = $this->createStaffUser('RESIDENT_OWNER');
        $parcel = Parcel::create([
            'tracking_number' => 'FORBIDDEN_TEST_'.Str::random(6),
            'courier_company' => 'VNPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '112233',
            'pickup_qr_code' => 'QR_FORBIDDEN_'.Str::random(6),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($resident)->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
            'pin_code' => '112233',
        ]);

        $response->assertStatus(403);
    }

    /**
     * P0 TEST: Bắt buộc cung cấp ít nhất một phương thức xác minh (PIN hoặc QR); từ chối nếu bỏ trống cả hai -> 422
     */
    public function test_checkout_fails_with_422_if_both_pin_and_qr_are_empty(): void
    {
        $parcel = Parcel::create([
            'tracking_number' => 'NO_CODE_TEST_'.Str::random(6),
            'courier_company' => 'SPX',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '998877',
            'pickup_qr_code' => 'QR_NO_CODE_'.Str::random(6),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
            'collected_by_name' => 'Nguyễn Cư Dân',
            'notes' => 'Không nhập mã PIN hay mã QR',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['verification']);
    }

    /**
     * P0 TEST: Bàn giao bưu phẩm thành công khi xác minh bằng mã QR hợp lệ
     */
    public function test_checkout_succeeds_with_valid_qr_code(): void
    {
        $qrCode = 'QR_VERIFY_'.Str::random(10);
        $parcel = Parcel::create([
            'tracking_number' => 'QR_TEST_'.Str::random(6),
            'courier_company' => 'Ninja Van',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Nguyễn Cư Dân',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => '334455',
            'pickup_qr_code' => $qrCode,
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/reception/parcels/{$parcel->id}/check-out", [
            'qr_code' => $qrCode,
            'collected_by_name' => 'Người nhận xuất trình QR',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => 'DELIVERED',
            'checkout_staff_id' => $this->receptionist->id,
        ]);
    }

    /**
     * P0 TEST: Kiểm tra route handover (/api/v1/reception/parcels/{id}/handover) với Bearer token hợp lệ
     */
    public function test_handover_route_with_valid_bearer_token_succeeds(): void
    {
        $token = $this->createSessionForUser($this->receptionist);
        $pin = '889900';

        $parcel = Parcel::create([
            'tracking_number' => 'HANDOVER_ROUTE_'.Str::random(6),
            'courier_company' => 'VNPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Lê Thị F',
            'recipient_phone' => '0922222222',
            'parcel_photo_url' => 'https://sample.com/parcel.jpg',
            'pickup_pin_code' => $pin,
            'pickup_qr_code' => 'QR_HANDOVER_'.Str::random(8),
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
            'received_by_staff_id' => $this->receptionist->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/reception/parcels/{$parcel->id}/handover", [
                'pin_code' => $pin,
                'collected_by_name' => 'Lê Thị F',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => 'DELIVERED',
            'checkout_staff_id' => $this->receptionist->id,
        ]);
    }
}
