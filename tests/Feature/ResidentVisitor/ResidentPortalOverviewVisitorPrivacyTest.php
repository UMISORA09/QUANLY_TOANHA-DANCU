<?php

namespace Tests\Feature\ResidentVisitor;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentPortalOverviewVisitorPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $sharedApartment;

    protected Apartment $otherApartment;

    protected User $residentA;

    protected string $tokenA;

    protected User $residentB;

    protected string $tokenB;

    protected User $residentC;

    protected string $tokenC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $blockId = (string) Str::uuid();
        DB::table('blocks')->insert([
            'id' => $blockId,
            'block_code' => 'BLK_M604_'.Str::random(4),
            'block_name' => 'Tháp M6-04 Privacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $floorId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $blockId,
            'floor_number' => 10,
            'floor_code' => 'FL-10',
            'floor_name' => 'Tầng 10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Căn hộ dùng chung bởi Cư dân A và Cư dân B
        $this->sharedApartment = Apartment::create([
            'apartment_number' => 'APT-M604-SHARED-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
        ]);

        // Căn hộ khác của Cư dân C
        $this->otherApartment = Apartment::create([
            'apartment_number' => 'APT-M604-OTHER-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
        ]);

        // Cư dân A (Chủ hộ Căn chung)
        [$this->residentA, $this->tokenA] = $this->createResidentUser('res_a_m604_');
        Resident::create([
            'user_id' => $this->residentA->id,
            'apartment_id' => $this->sharedApartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // Cư dân B (Thành viên cùng căn hộ chung)
        [$this->residentB, $this->tokenB] = $this->createResidentUser('res_b_m604_');
        Resident::create([
            'user_id' => $this->residentB->id,
            'apartment_id' => $this->sharedApartment->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'OTHER',
            'is_active' => true,
        ]);

        // Cư dân C (Căn hộ riêng khác)
        [$this->residentC, $this->tokenC] = $this->createResidentUser('res_c_m604_');
        Resident::create([
            'user_id' => $this->residentC->id,
            'apartment_id' => $this->otherApartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);
    }

    protected function createResidentUser(string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'User '.strtoupper(substr($prefix, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        $token = 'token_m604_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Runner M6-04',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function authHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    protected function insertVisitor(string $hostUserId, string $apartmentId, string $name, string $nationalId, string $qrPassCode): string
    {
        $id = (string) Str::uuid();
        $now = Carbon::now();

        DB::table('visitor_registrations')->insert([
            'id' => $id,
            'registration_code' => 'VIS-'.date('Ymd').'-'.strtoupper(Str::random(4)),
            'host_resident_user_id' => $hostUserId,
            'apartment_id' => $apartmentId,
            'visitor_name' => $name,
            'visitor_phone' => '0988'.rand(100000, 999999),
            'visitor_national_id' => $nationalId,
            'expected_arrival_time' => $now->copy()->addHours(2),
            'expected_departure_time' => $now->copy()->addHours(5),
            'visit_purpose' => 'Thăm bạn bè',
            'visitor_count' => 1,
            'vehicle_license_plate' => '29A-'.rand(10000, 99999),
            'qr_access_pass_code' => $qrPassCode,
            'qr_pass_status' => 'ACTIVE',
            'is_pre_approved_by_resident' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Test 1: BUG-M6-04 - Cư dân B xem overview căn hộ KHÔNG được thấy CCCD và QR Pass của khách do A đăng ký.
     */
    public function test_01_overview_masks_cccd_and_qr_pass_code_for_visitors_created_by_co_resident(): void
    {
        $secretCccd = '001099123456';
        $secretQrPass = 'PASS_SECRET_OF_A_9999';

        $visitorId = $this->insertVisitor(
            $this->residentA->id,
            $this->sharedApartment->id,
            'Khách Riêng Của A',
            $secretCccd,
            $secretQrPass
        );

        // Cư dân B cùng căn hộ gọi GET /api/v1/resident/overview
        $response = $this->getJson('/api/v1/resident/overview', $this->authHeaders($this->tokenB));

        $response->assertStatus(200);
        $visitors = $response->json('visitors');
        $this->assertIsArray($visitors);
        $this->assertNotEmpty($visitors);

        $targetVisitor = collect($visitors)->firstWhere('id', $visitorId);
        $this->assertNotNull($targetVisitor);
        $this->assertEquals('Khách Riêng Của A', $targetVisitor['visitor_name']);

        // BUG-M6-04: id_card_number và qr_pass_code PHẢI là NULL đối với thành viên khác cùng căn hộ
        $this->assertNull($targetVisitor['id_card_number'], 'CCCD của khách riêng phải bị ẩn đối với thành viên khác');
        $this->assertNull($targetVisitor['qr_pass_code'], 'Mã QR Pass của khách riêng phải bị ẩn đối với thành viên khác');

        // Đảm bảo không để lộ trường nhạy cảm host_resident_user_id
        $this->assertArrayNotHasKey('host_resident_user_id', $targetVisitor);
    }

    /**
     * Test 2: BUG-M6-04 - Cư dân A (người tạo lượt khách) xem overview căn hộ VẪN THẤY ĐẦY ĐỦ CCCD và QR Pass của khách mình.
     */
    public function test_02_overview_reveals_cccd_and_qr_pass_code_to_the_creator_themselves(): void
    {
        $secretCccd = '001099123456';
        $secretQrPass = 'PASS_SECRET_OF_A_8888';

        $visitorId = $this->insertVisitor(
            $this->residentA->id,
            $this->sharedApartment->id,
            'Khách Hợp Lệ Của A',
            $secretCccd,
            $secretQrPass
        );

        // Cư dân A (chính chủ tạo khách) gọi GET /api/v1/resident/overview
        $response = $this->getJson('/api/v1/resident/overview', $this->authHeaders($this->tokenA));

        $response->assertStatus(200);
        $visitors = $response->json('visitors');
        $targetVisitor = collect($visitors)->firstWhere('id', $visitorId);

        $this->assertNotNull($targetVisitor);
        $this->assertEquals($secretCccd, $targetVisitor['id_card_number'], 'Chính chủ phải thấy CCCD khách của mình');
        $this->assertEquals($secretQrPass, $targetVisitor['qr_pass_code'], 'Chính chủ phải thấy QR Pass khách của mình');
    }

    /**
     * Test 3: Cả A và B cùng tạo khách riêng trong cùng căn hộ:
     * A chỉ thấy CCCD/QR của khách A, B chỉ thấy CCCD/QR của khách B.
     */
    public function test_03_overview_with_multiple_co_residents_each_only_sees_their_own_sensitive_data(): void
    {
        $idA = $this->insertVisitor($this->residentA->id, $this->sharedApartment->id, 'Khách A', '001099111111', 'PASS_A_111');
        $idB = $this->insertVisitor($this->residentB->id, $this->sharedApartment->id, 'Khách B', '001099222222', 'PASS_B_222');

        // 1. Phía A gọi overview
        $resA = $this->getJson('/api/v1/resident/overview', $this->authHeaders($this->tokenA));
        $resA->assertStatus(200);
        $visitorsForA = $resA->json('visitors');

        $itemAFromA = collect($visitorsForA)->firstWhere('id', $idA);
        $itemBFromA = collect($visitorsForA)->firstWhere('id', $idB);

        $this->assertEquals('001099111111', $itemAFromA['id_card_number']);
        $this->assertEquals('PASS_A_111', $itemAFromA['qr_pass_code']);
        $this->assertNull($itemBFromA['id_card_number']);
        $this->assertNull($itemBFromA['qr_pass_code']);

        // 2. Phía B gọi overview
        $resB = $this->getJson('/api/v1/resident/overview', $this->authHeaders($this->tokenB));
        $resB->assertStatus(200);
        $visitorsForB = $resB->json('visitors');

        $itemAFromB = collect($visitorsForB)->firstWhere('id', $idA);
        $itemBFromB = collect($visitorsForB)->firstWhere('id', $idB);

        $this->assertEquals('001099222222', $itemBFromB['id_card_number']);
        $this->assertEquals('PASS_B_222', $itemBFromB['qr_pass_code']);
        $this->assertNull($itemAFromB['id_card_number']);
        $this->assertNull($itemAFromB['qr_pass_code']);
    }

    /**
     * Test 4: Cư dân căn hộ khác không nhìn thấy khách của căn hộ này trong overview
     */
    public function test_04_resident_of_different_apartment_cannot_see_visitors_from_another_apartment(): void
    {
        $idA = $this->insertVisitor($this->residentA->id, $this->sharedApartment->id, 'Khách Của Căn Chung', '001099333333', 'PASS_SHARED_333');

        $resC = $this->getJson('/api/v1/resident/overview', $this->authHeaders($this->tokenC));
        $resC->assertStatus(200);

        $visitorsForC = $resC->json('visitors');
        $item = collect($visitorsForC)->firstWhere('id', $idA);
        $this->assertNull($item, 'Cư dân Căn hộ khác không được nhìn thấy khách của căn hộ khác');
    }

    /**
     * Test 5: Yêu cầu không có xác thực bị trả về 401 Unauthorized
     */
    public function test_05_unauthenticated_request_to_overview_returns_401(): void
    {
        $response = $this->getJson('/api/v1/resident/overview');
        $response->assertStatus(401);
    }
}
