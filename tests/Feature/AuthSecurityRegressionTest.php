<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSecurityRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $activeUser;

    protected string $rawPassword = 'Password@123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeUser = User::create([
            'username' => 'auth_test_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'auth_test_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make($this->rawPassword),
            'full_name' => 'Security Tester',
            'status' => 'ACTIVE',
        ]);

        $role = Role::firstOrCreate(
            ['role_code' => 'RECEPTIONIST'],
            ['role_name' => 'Receptionist', 'is_system_role' => true]
        );

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->activeUser->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Helper tạo session token trong DB
     */
    protected function createSession(User $user, bool $isRevoked = false, bool $isExpired = false): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'Security Regression Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => $isExpired ? now()->subDay() : now()->addDays(7),
            'is_revoked' => $isRevoked ? 1 : 0,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * TEST 1: Đăng nhập với mật khẩu đúng -> Thành công 200, trả về access_token và lưu vào user_sessions
     */
    public function test_login_with_valid_credentials_succeeds_and_creates_session(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->activeUser->username,
            'password' => $this->rawPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $token = $response->json('access_token');
        $this->assertNotEmpty($token);

        $tokenHash = hash('sha256', $token);
        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $this->activeUser->id,
            'refresh_token_hash' => $tokenHash,
            'is_revoked' => 0,
        ]);
    }

    /**
     * TEST 2: Đăng nhập với mật khẩu sai -> Bị từ chối HTTP 401
     */
    public function test_login_with_invalid_password_fails_with_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->activeUser->username,
            'password' => 'WrongPassword@999',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
        ]);
    }

    /**
     * TEST 3: Đăng nhập với tài khoản bị khóa -> Bị từ chối HTTP 403
     */
    public function test_login_with_locked_account_fails_with_403(): void
    {
        $this->activeUser->update(['status' => 'LOCKED']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->activeUser->username,
            'password' => $this->rawPassword,
        ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 4: Gọi API me với token hợp lệ -> Thành công 200
     */
    public function test_me_with_valid_token_succeeds(): void
    {
        $token = $this->createSession($this->activeUser);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'id' => $this->activeUser->id,
            'email' => $this->activeUser->email,
        ]);
    }

    /**
     * TEST 5: Gọi API với token đã bị thu hồi (is_revoked = 1) -> HTTP 401
     */
    public function test_access_with_revoked_token_returns_401(): void
    {
        $token = $this->createSession($this->activeUser, isRevoked: true);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reception/parcels/123/check-out');

        $response->assertStatus(401);
    }

    /**
     * TEST 6: Gọi API với token đã hết hạn (expires_at in past) -> HTTP 401
     */
    public function test_access_with_expired_token_returns_401(): void
    {
        $token = $this->createSession($this->activeUser, isExpired: true);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reception/parcels/123/check-out');

        $response->assertStatus(401);
    }

    /**
     * TEST 7: Gọi API với token giả mạo định danh UUID mà không có trong DB -> HTTP 401
     */
    public function test_access_with_forged_uuid_token_returns_401(): void
    {
        $forgedToken = 'smart_token_'.$this->activeUser->id.'_'.Str::random(40);

        $response = $this->withHeader('Authorization', 'Bearer '.$forgedToken)
            ->postJson('/api/v1/reception/parcels/123/check-out');

        $response->assertStatus(401);
    }

    /**
     * TEST 8: Gọi API khi chưa xác thực -> HTTP 401
     */
    public function test_access_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/reception/parcels/123/check-out');

        $response->assertStatus(401);
    }

    protected function tearDown(): void
    {
        app()['env'] = 'testing';
        parent::tearDown();
    }

    /**
     * BUG-02-A TEST 1: Mật khẩu fallback cũ không thể đăng nhập nếu không khớp password hash
     */
    public function test_bug02_a_cannot_login_with_hardcoded_fallback_password_when_user_has_different_hash(): void
    {
        $secureUser = User::create([
            'username' => 'sec_user_'.Str::random(6),
            'email' => 'sec_'.Str::random(6).'@cassavas.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'password_hash' => Hash::make('ActualStrongPassword@2026!'),
            'full_name' => 'Secure Target User',
            'status' => 'ACTIVE',
        ]);

        $fallbacks = ['123567', '123456', 'Admin@123456', 'Manager@123456', 'Receptionist@123456', 'Security@123456', 'Resident@123456', 'Accountant@123456'];
        foreach ($fallbacks as $fb) {
            $res = $this->postJson('/api/v1/auth/login', [
                'identifier' => $secureUser->username,
                'password' => $fb,
            ]);
            $res->assertStatus(401, "Mật khẩu fallback cũ '{$fb}' không được phép đăng nhập khi password_hash khác");
        }

        // Kiểm tra với tài khoản admin hệ thống khi đổi mật khẩu
        $adminUser = User::where('username', 'admin')->first();
        if ($adminUser) {
            $oldHash = $adminUser->password_hash;
            $adminUser->update(['password_hash' => Hash::make('NewAdminSuperSecretPassword@2026!')]);

            $resFb = $this->postJson('/api/v1/auth/login', [
                'username' => 'admin',
                'password' => '123456',
            ]);
            $resFb->assertStatus(401, 'Tài khoản admin không được phép dùng mật khẩu fallback 123456 khi password_hash đã đổi');

            $resFbAdmin = $this->postJson('/api/v1/auth/login', [
                'username' => 'admin',
                'password' => 'Admin@123456',
            ]);
            $resFbAdmin->assertStatus(401, 'Tài khoản admin không được phép dùng mật khẩu fallback Admin@123456 khi password_hash đã đổi');

            // Đăng nhập mật khẩu thật vẫn thành công
            $resCorrect = $this->postJson('/api/v1/auth/login', [
                'username' => 'admin',
                'password' => 'NewAdminSuperSecretPassword@2026!',
            ]);
            $resCorrect->assertStatus(200);

            // Khôi phục hash cũ
            $adminUser->update(['password_hash' => $oldHash]);
        }
    }

    /**
     * BUG-02-A TEST 2: Tài khoản demo vẫn đăng nhập được bình thường bằng mật khẩu đã hash trong DB
     */
    public function test_bug02_a_demo_account_with_seeded_hash_can_still_login(): void
    {
        $demoUser = User::create([
            'username' => 'demo_staff_'.Str::random(6),
            'email' => 'demostaff_'.Str::random(6).'@cassavas.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'password_hash' => Hash::make('123567'),
            'full_name' => 'Demo Staff Member',
            'status' => 'ACTIVE',
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => $demoUser->username,
            'password' => '123567',
        ]);

        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('access_token'));
    }

    /**
     * BUG-02-B TEST 3: Production không trả debug_otp trong HTTP response khi gửi OTP đăng ký
     */
    public function test_bug02_b_register_otp_does_not_leak_in_production_environment(): void
    {
        Mail::fake();
        app()['env'] = 'production';

        $testEmail = 'newresident_'.Str::random(8).'@example.com';
        $res = $this->postJson('/api/v1/auth/send-register-otp', [
            'email' => $testEmail,
        ]);

        $res->assertStatus(200);
        $this->assertNull($res->json('debug_otp'), 'Trên Production trường debug_otp bắt buộc phải là null');
    }

    /**
     * BUG-02-B TEST 4: Production không trả debug_otp trong HTTP response khi quên mật khẩu
     */
    public function test_bug02_b_forgot_password_otp_does_not_leak_in_production_environment(): void
    {
        Mail::fake();
        app()['env'] = 'production';

        $res = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $this->activeUser->email,
        ]);

        $res->assertStatus(200);
        $this->assertNull($res->json('debug_otp'), 'Trên Production forgot-password debug_otp bắt buộc phải là null');
    }

    /**
     * BUG-02-B TEST 5: Local và Testing vẫn giữ được khả năng debug OTP theo thiết kế
     */
    public function test_bug02_b_local_or_testing_environment_retains_debug_otp_capability(): void
    {
        Mail::fake();
        app()['env'] = 'testing';

        $testEmail = 'testotp_'.Str::random(8).'@example.com';
        $res = $this->postJson('/api/v1/auth/send-register-otp', [
            'email' => $testEmail,
        ]);

        $res->assertStatus(200);
        $debugOtp = $res->json('debug_otp');
        $this->assertNotNull($debugOtp, 'Trong môi trường testing phải giữ debug_otp');
        $this->assertEquals(6, strlen($debugOtp));
    }

    /**
     * BUG-02-C TEST 6: Demo token bị từ chối trên Production cho Reception Dashboard (HTTP 401)
     */
    public function test_bug02_c_demo_token_rejected_in_production_for_reception_dashboard(): void
    {
        app()['env'] = 'production';

        $demoTokens = ['smart_token_reception_demo', 'smart_token_admin_demo', 'smart_token_manager_demo'];
        foreach ($demoTokens as $dt) {
            $res = $this->withHeader('Authorization', 'Bearer '.$dt)
                ->getJson('/api/v1/reception/dashboard');
            $res->assertStatus(401, "Token {$dt} phải bị từ chối 401 trên Production");
        }
    }

    /**
     * BUG-02-C TEST 7: Demo token vẫn hoạt động trong local/testing khi được RbacService hỗ trợ
     */
    public function test_bug02_c_demo_token_works_in_testing_environment_for_reception_dashboard(): void
    {
        app()['env'] = 'testing';

        $recUser = User::where('username', 'letan')->first()
            ?? User::where('username', 'receptionist')->first();

        if (! $recUser) {
            $recUser = User::create([
                'username' => 'letan',
                'email' => 'letan_test_'.Str::random(6).'@cassavas.vn',
                'phone_number' => '09'.rand(10000000, 99999999),
                'full_name' => 'Lễ Tân Demo',
                'status' => 'ACTIVE',
                'password_hash' => Hash::make('123567'),
            ]);
        }
        $role = Role::firstOrCreate(['role_code' => 'RECEPTIONIST'], ['role_name' => 'Receptionist', 'is_system_role' => true]);
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $recUser->id, 'role_id' => $role->id],
            ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => now()]
        );

        $res = $this->withHeader('Authorization', 'Bearer smart_token_reception_demo')
            ->getJson('/api/v1/reception/dashboard');
        $res->assertStatus(200);
        $this->assertTrue($res->json('success'));
    }

    /**
     * BUG-02-C TEST 8: Bearer token phiên thật hợp lệ vẫn truy cập dashboard đúng quyền
     */
    public function test_bug02_c_valid_bearer_token_can_access_reception_dashboard(): void
    {
        $staffUser = User::create([
            'username' => 'staff_valid_'.Str::random(6),
            'email' => 'staff_'.Str::random(6).'@cassavas.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nhân Viên Có Quyền Dashboard',
            'status' => 'ACTIVE',
        ]);
        $role = Role::firstOrCreate(['role_code' => 'RECEPTIONIST'], ['role_name' => 'Receptionist', 'is_system_role' => true]);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $staffUser->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->createSession($staffUser);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reception/dashboard');
        $res->assertStatus(200);
        $this->assertTrue($res->json('success'));
    }

    /**
     * BUG-02-C TEST 9: Token không hợp lệ hoặc hết hạn/thu hồi không thể truy cập dashboard
     */
    public function test_bug02_c_expired_or_revoked_token_rejected_on_reception_dashboard(): void
    {
        $staffUser = User::create([
            'username' => 'staff_exp_'.Str::random(6),
            'email' => 'staff_exp_'.Str::random(6).'@cassavas.vn',
            'phone_number' => '09'.rand(10000000, 99999999),
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nhân Viên Expired Dashboard',
            'status' => 'ACTIVE',
        ]);
        $role = Role::firstOrCreate(['role_code' => 'RECEPTIONIST'], ['role_name' => 'Receptionist', 'is_system_role' => true]);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $staffUser->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $expiredToken = $this->createSession($staffUser, isExpired: true);
        $resExp = $this->withHeader('Authorization', 'Bearer '.$expiredToken)
            ->getJson('/api/v1/reception/dashboard');
        $resExp->assertStatus(401);

        $revokedToken = $this->createSession($staffUser, isRevoked: true);
        $resRev = $this->withHeader('Authorization', 'Bearer '.$revokedToken)
            ->getJson('/api/v1/reception/dashboard');
        $resRev->assertStatus(401);
    }

    /**
     * BUG-02-C TEST 10: Không có fallback chọn nhân viên đầu tiên khi thiếu danh tính hợp lệ
     */
    public function test_bug02_c_no_unauthenticated_fallback_to_arbitrary_staff_user(): void
    {
        app()['env'] = 'production';

        // Không gửi token -> 401
        $resAnon = $this->getJson('/api/v1/reception/dashboard');
        $resAnon->assertStatus(401);

        // Gửi token giả mạo -> 401
        $resFake = $this->withHeader('Authorization', 'Bearer smart_token_arbitrary_fake_999')
            ->getJson('/api/v1/reception/dashboard');
        $resFake->assertStatus(401);
    }
}
