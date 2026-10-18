<?php

namespace Tests\Feature\ReceptionParcel;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\User;
use App\Services\ReceptionParcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionParcelCheckoutTest extends TestCase
{
    use RefreshDatabase;

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
            'user_id' => $user->id,
            'role_id' => $roleId,
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
            'pickup_pin_code' => '112233',
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
            'pickup_pin_code' => '654321',
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
            'pickup_pin_code' => $pin,
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
}
