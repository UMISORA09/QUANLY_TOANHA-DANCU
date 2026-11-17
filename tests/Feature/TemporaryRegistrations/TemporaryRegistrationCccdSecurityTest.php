<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationCccdSecurityTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm Bearer token
     */
    protected function createAdminOrManagerUser(string $roleCode = 'SUPER_ADMIN'): array
    {
        $user = User::create([
            'username' => 'adm_cccd_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_cccd_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $roleCode === 'SUPER_ADMIN' ? 'Super Admin CCCD' : 'Building Manager CCCD',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', $roleCode)->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Admin Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test
     */
    protected function createApartment(string $code = 'CCCD'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.Str::upper(Str::random(6)),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Tạo tài khoản cư dân hợp lệ thuộc một căn hộ
     */
    protected function createResidentUser(Apartment $apt, bool $isHead = true, string $residentType = 'OWNER'): array
    {
        $user = User::create([
            'username' => 'res_cccd_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_cccd_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cu Dan CCCD '.Str::random(4),
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);

        $roleCode = $residentType === 'OWNER' ? 'RESIDENT_OWNER' : 'RESIDENT_MEMBER';
        $role = Role::where('role_code', $roleCode)->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $resident = Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt->id,
            'resident_type' => $residentType,
            'is_head_of_household' => $isHead,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => $isHead ? 'SELF' : 'MEMBER',
            'is_active' => true,
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $resident, $token];
    }

    /**
     * Test 01: Upload CCCD lưu trên disk 'local' (private), KHÔNG lưu trên disk 'public'
     */
    public function test_01_upload_cccd_stores_on_local_disk_not_public(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $apt = $this->createApartment();
        [$residentUser, $resident, $token] = $this->createResidentUser($apt);

        $fileFront = UploadedFile::fake()->create('cccd_front.jpg', 200, 'image/jpeg');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $fileFront,
                'side' => 'front',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $url = $response->json('url');

        $this->assertNotEmpty($url);
        $this->assertStringStartsWith('/api/v1/residents/temporary-registrations/cccd/', $url);

        $filename = basename($url);

        // Xác nhận file đã được lưu vào disk local (private)
        $this->assertTrue(Storage::disk('local')->exists('temporary_registrations/cccd/'.$filename));

        // Xác nhận file TUYỆT ĐỐI KHÔNG tồn tại trên disk public (tránh nguy cơ lộ PII qua symlink public/storage)
        $this->assertFalse(Storage::disk('public')->exists('temporary_registrations/cccd/'.$filename));
    }

    /**
     * Test 02: Khách vãng lai chưa xác thực (Guest) không thể truy cập ảnh CCCD
     */
    public function test_02_guest_cannot_access_cccd_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('temporary_registrations/cccd/cccd_front_sample.jpg', 'fake-image-content');

        // Truy cập endpoint qua filename mà không có Bearer token
        $res1 = $this->get('/api/v1/residents/temporary-registrations/cccd/cccd_front_sample.jpg', [
            'Accept' => 'application/json',
        ]);
        $res1->assertStatus(401);

        // Truy cập endpoint qua id/side mà không có Bearer token
        $fakeUuid = (string) Str::uuid();
        $res2 = $this->get("/api/v1/residents/temporary-registrations/{$fakeUuid}/cccd/front", [
            'Accept' => 'application/json',
        ]);
        $res2->assertStatus(401);
    }

    /**
     * Test 03: Cư dân khác căn hộ không thể truy cập ảnh CCCD của người khác
     */
    public function test_03_unrelated_resident_cannot_access_other_resident_cccd(): void
    {
        Storage::fake('local');

        $aptA = $this->createApartment('APTA');
        $aptB = $this->createApartment('APTB');

        [$userA, $resA, $tokenA] = $this->createResidentUser($aptA);
        [$userB, $resB, $tokenB] = $this->createResidentUser($aptB);

        $filename = 'cccd_front_u'.$userA->id.'_'.Str::random(16).'.jpg';
        Storage::disk('local')->put('temporary_registrations/cccd/'.$filename, 'cccd-content-user-a');

        $registrationA = TemporaryRegistration::create([
            'resident_id' => $resA->id,
            'apartment_id' => $aptA->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú căn hộ A',
            'police_status' => 'PENDING',
            'identity_card_front_url' => '/api/v1/residents/temporary-registrations/cccd/'.$filename,
        ]);

        // User B cố tình truy cập ảnh CCCD của User A qua endpoint cccd/{filename}
        $response1 = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$filename, [
                'Accept' => 'application/json',
            ]);
        $response1->assertStatus(403);

        // User B cố tình truy cập ảnh CCCD của User A qua endpoint {id}/cccd/front
        $response2 = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->get("/api/v1/residents/temporary-registrations/{$registrationA->id}/cccd/front", [
                'Accept' => 'application/json',
            ]);
        $response2->assertStatus(403);
    }

    /**
     * Test 04: Cư dân chính chủ có thể tải và xem ảnh CCCD của mình
     */
    public function test_04_owner_resident_can_access_own_cccd(): void
    {
        Storage::fake('local');

        $apt = $this->createApartment();
        [$user, $resident, $token] = $this->createResidentUser($apt);

        $filename = 'cccd_front_u'.$user->id.'_'.Str::random(16).'.jpg';
        Storage::disk('local')->put('temporary_registrations/cccd/'.$filename, 'image-binary-data');

        $registration = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú chính chủ',
            'police_status' => 'PENDING',
            'identity_card_front_url' => '/api/v1/residents/temporary-registrations/cccd/'.$filename,
        ]);

        // Truy cập qua filename
        $response1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$filename);

        $response1->assertStatus(200);
        $response1->assertHeader('Cache-Control', 'no-store, private');
        $response1->assertHeader('X-Content-Type-Options', 'nosniff');

        // Truy cập qua id/side
        $response2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/residents/temporary-registrations/{$registration->id}/cccd/front");

        $response2->assertStatus(200);
        $response2->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Test 05: Người upload được xem ảnh trước khi hồ sơ được lưu vào CSDL, người khác bị từ chối
     */
    public function test_05_uploader_can_preview_cccd_before_registration_submission(): void
    {
        Storage::fake('local');

        $aptA = $this->createApartment('UPL1');
        $aptB = $this->createApartment('UPL2');

        [$userA, $resA, $tokenA] = $this->createResidentUser($aptA);
        [$userB, $resB, $tokenB] = $this->createResidentUser($aptB);

        // Upload ảnh bởi User A
        $fileFront = UploadedFile::fake()->create('my_cccd.jpg', 150, 'image/jpeg');
        $uploadRes = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->post('/api/v1/residents/temporary-registrations/upload-cccd', [
                'file' => $fileFront,
                'side' => 'front',
            ], ['Accept' => 'application/json']);

        $uploadRes->assertStatus(200);
        $url = $uploadRes->json('url');
        $filename = basename($url);

        // Hồ sơ CHƯA được tạo trong CSDL
        $this->assertDatabaseMissing('temporary_registrations', [
            'identity_card_front_url' => $url,
        ]);

        // 1. User A (người upload) có thể xem lại ảnh xem trước của mình
        $previewResA = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$filename);
        $previewResA->assertStatus(200);

        // 2. User B cố tình xem ảnh chưa nộp của User A -> Bị từ chối 403
        $previewResB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$filename);
        $previewResB->assertStatus(403);

        // 3. Giả mạo uploader ID không đúng định dạng UUID hoặc sai người -> Bị từ chối 403
        $spoofedFilename = 'cccd_front_u'.$userB->id.'_'.Str::random(16).'.jpg';
        Storage::disk('local')->put('temporary_registrations/cccd/'.$spoofedFilename, 'fake-data');

        $resSpoofed = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$spoofedFilename);
        $resSpoofed->assertStatus(403);
    }

    /**
     * Test 06: Quản trị viên (SUPER_ADMIN và BUILDING_MANAGER) có thể truy cập ảnh CCCD của bất kỳ hồ sơ nào
     */
    public function test_06_admin_and_building_manager_can_access_resident_cccd(): void
    {
        Storage::fake('local');

        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);
        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');
        [$bmUser, $bmToken] = $this->createAdminOrManagerUser('BUILDING_MANAGER');

        $filename = 'cccd_front_u'.$residentUser->id.'_'.Str::random(16).'.jpg';
        Storage::disk('local')->put('temporary_registrations/cccd/'.$filename, 'admin-inspection-data');

        $registration = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký thẩm tra',
            'police_status' => 'PENDING',
            'identity_card_front_url' => '/api/v1/residents/temporary-registrations/cccd/'.$filename,
        ]);

        // SUPER_ADMIN truy cập
        $resAdmin = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$filename);
        $resAdmin->assertStatus(200);

        // BUILDING_MANAGER truy cập qua {id}/cccd/front
        $resBM = $this->withHeader('Authorization', 'Bearer '.$bmToken)
            ->get("/api/v1/residents/temporary-registrations/{$registration->id}/cccd/front");
        $resBM->assertStatus(200);
    }

    /**
     * Test 07: Chặn tấn công Path Traversal, tên file giả mạo hoặc đuôi file không hợp lệ
     */
    public function test_07_path_traversal_and_malicious_filenames_are_blocked(): void
    {
        Storage::fake('local');
        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        // 1. Thử path traversal với ..
        $resTraversal = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/..%2F..%2F.env', [
                'Accept' => 'application/json',
            ]);
        $this->assertContains($resTraversal->getStatusCode(), [400, 404]);

        // 2. Thử truy cập file thực thi nguy hiểm (.php, .exe, .sh)
        $resPhp = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/exploit.php', [
                'Accept' => 'application/json',
            ]);
        $resPhp->assertStatus(400);

        // 3. Thử ký tự cấm trong tên file
        $resInvalidChars = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/cccd<script>.jpg', [
                'Accept' => 'application/json',
            ]);
        $resInvalidChars->assertStatus(400);
    }

    /**
     * Test 08: Bản ghi cũ lưu trên disk public được tự động di chuyển an toàn sang disk local khi truy cập
     */
    public function test_08_backward_compatibility_auto_relocates_legacy_file_from_public_to_local(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $apt = $this->createApartment();
        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $legacyFilename = 'cccd_legacy_front_old.jpg';
        $fileContent = 'legacy-binary-image-data-from-public';

        // Đặt file trên disk public (mô phỏng file cũ lưu trước khi vá)
        Storage::disk('public')->put('temporary_registrations/cccd/'.$legacyFilename, $fileContent);

        $this->assertTrue(Storage::disk('public')->exists('temporary_registrations/cccd/'.$legacyFilename));
        $this->assertFalse(Storage::disk('local')->exists('temporary_registrations/cccd/'.$legacyFilename));

        // Truy cập qua endpoint bảo mật
        $response = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$legacyFilename);

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'no-store, private');

        // Xác nhận file đã được di chuyển an toàn sang disk local (private)
        $this->assertTrue(Storage::disk('local')->exists('temporary_registrations/cccd/'.$legacyFilename));
        $this->assertEquals($fileContent, Storage::disk('local')->get('temporary_registrations/cccd/'.$legacyFilename));
    }

    /**
     * Test 09: Xác nhận bản sao trên disk public bị xóa bỏ sau di chuyển, triệt tiêu nguy cơ tải qua static URL
     */
    public function test_09_static_url_no_longer_serves_cccd_after_relocation(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $legacyFilename = 'cccd_sensitive_document.png';
        $fileContent = 'top-secret-cccd-citizen-content';

        // Ban đầu file nằm ở public
        Storage::disk('public')->put('temporary_registrations/cccd/'.$legacyFilename, $fileContent);
        $this->assertTrue(Storage::disk('public')->exists('temporary_registrations/cccd/'.$legacyFilename));

        // Yêu cầu tải qua endpoint bảo mật
        $response = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/residents/temporary-registrations/cccd/'.$legacyFilename);
        $response->assertStatus(200);

        // Sau khi phục vụ qua endpoint bảo mật, file PHẢI BỊ XÓA khỏi disk public
        $this->assertFalse(
            Storage::disk('public')->exists('temporary_registrations/cccd/'.$legacyFilename),
            'File cũ vẫn còn trên disk public! Nguy cơ lộ lọt qua URL static /storage/... vẫn tồn tại.'
        );

        // Xác nhận file được lưu giữ nguyên vẹn trên private local
        $this->assertTrue(Storage::disk('local')->exists('temporary_registrations/cccd/'.$legacyFilename));
    }

    /**
     * Test 10: Lệnh Artisan di chuyển hàng loạt tệp và chuẩn hóa URL trong CSDL
     */
    public function test_10_migration_artisan_command_migrates_files_and_db_records(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);

        $file1 = 'cccd_front_legacy_batch1.jpg';
        $file2 = 'cccd_back_legacy_batch2.png';

        Storage::disk('public')->put('temporary_registrations/cccd/'.$file1, 'content-batch-1');
        Storage::disk('public')->put('temporary_registrations/cccd/'.$file2, 'content-batch-2');

        // Tạo bản ghi CSDL với đường dẫn tĩnh cũ /storage/...
        $regId = (string) Str::uuid();
        DB::table('temporary_registrations')->insert([
            'id' => $regId,
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký test batch',
            'police_status' => 'PENDING',
            'identity_card_front_url' => '/storage/temporary_registrations/cccd/'.$file1,
            'identity_card_back_url' => '/storage/temporary_registrations/cccd/'.$file2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Chạy ở chế độ dry-run
        $this->artisan('temporary-registrations:migrate-cccd')
            ->assertExitCode(0);

        // File vẫn còn trên public disk sau dry-run
        $this->assertTrue(Storage::disk('public')->exists('temporary_registrations/cccd/'.$file1));

        // 2. Chạy thật với --apply và --remove-public
        $this->artisan('temporary-registrations:migrate-cccd --apply --remove-public')
            ->assertExitCode(0);

        // File đã chuyển sang local
        $this->assertTrue(Storage::disk('local')->exists('temporary_registrations/cccd/'.$file1));
        $this->assertTrue(Storage::disk('local')->exists('temporary_registrations/cccd/'.$file2));

        // File đã bị xóa khỏi public
        $this->assertFalse(Storage::disk('public')->exists('temporary_registrations/cccd/'.$file1));
        $this->assertFalse(Storage::disk('public')->exists('temporary_registrations/cccd/'.$file2));

        // CSDL đã được cập nhật sang endpoint bảo mật
        $updatedRecord = DB::table('temporary_registrations')->where('id', $regId)->first();
        $this->assertEquals('/api/v1/residents/temporary-registrations/cccd/'.$file1, $updatedRecord->identity_card_front_url);
        $this->assertEquals('/api/v1/residents/temporary-registrations/cccd/'.$file2, $updatedRecord->identity_card_back_url);
    }

    /**
     * Test 11: Accessor trên Model TemporaryRegistration tự động chuẩn hóa URL cũ sang endpoint bảo mật
     */
    public function test_11_model_accessor_normalizes_legacy_storage_urls_automatically(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);

        $reg = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Test accessor',
            'police_status' => 'PENDING',
            'identity_card_front_url' => '/storage/temporary_registrations/cccd/cccd_front_legacy.jpg',
            'identity_card_back_url' => 'temporary_registrations/cccd/cccd_back_legacy.png',
        ]);

        // Kiểm tra truy xuất thuộc tính qua model
        $this->assertEquals(
            '/api/v1/residents/temporary-registrations/cccd/cccd_front_legacy.jpg',
            $reg->identity_card_front_url
        );
        $this->assertEquals(
            '/api/v1/residents/temporary-registrations/cccd/cccd_back_legacy.png',
            $reg->identity_card_back_url
        );

        // Kiểm tra serialization toArray() cho API / frontend
        $array = $reg->toArray();
        $this->assertEquals(
            '/api/v1/residents/temporary-registrations/cccd/cccd_front_legacy.jpg',
            $array['identity_card_front_url']
        );
        $this->assertEquals(
            '/api/v1/residents/temporary-registrations/cccd/cccd_back_legacy.png',
            $array['identity_card_back_url']
        );
    }
}
