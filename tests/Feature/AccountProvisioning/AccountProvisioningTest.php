<?php

namespace Tests\Feature\AccountProvisioning;

use App\Mail\AccountActivationMail;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\QuocTinRealtimeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected string $adminToken;

    protected User $regularUser;

    protected string $regularToken;

    protected AccountProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountProvisioningService::class);

        [$this->admin, $this->adminToken] = $this->createAdminUser();
        [$this->regularUser, $this->regularToken] = $this->createRegularUser();
    }

    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_prov_'.Str::random(8),
            'phone_number' => '09'.random_int(10000000, 99999999),
            'email' => 'adm_prov_'.Str::random(8).'@cassavas.vn',
            'full_name' => 'Quản Lý Cấp Phát',
            'password_hash' => Hash::make('AdminPass@123'),
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if (! $role) {
            $role = Role::create([
                'id' => (string) Str::uuid(),
                'role_code' => 'SUPER_ADMIN',
                'role_name' => 'Quản Trị Viên Cấp Cao',
                'is_system_role' => true,
            ]);
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Provision Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createRegularUser(): array
    {
        $user = User::create([
            'username' => 'reg_prov_'.Str::random(8),
            'phone_number' => '09'.random_int(10000000, 99999999),
            'email' => 'reg_prov_'.Str::random(8).'@cassavas.vn',
            'full_name' => 'Người Dùng Thường',
            'password_hash' => Hash::make('UserPass@123'),
            'status' => 'ACTIVE',
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Regular Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * 1. Quản lý tạo tài khoản
     */
    public function test_01_quan_ly_tao_tai_khoan_thanh_cong(): void
    {
        Mail::fake();

        $payload = [
            'full_name' => 'Nguyễn Văn Nhân Viên',
            'email' => strtolower('nhanvien_'.Str::random(6).'@example.com'),
            'phone_number' => '098'.random_int(1000000, 9999999),
            'national_id_number' => '079'.random_int(100000000, 999999999),
            'gender' => 'MALE',
            'roles' => ['RECEPTIONIST'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Cấp phát tài khoản tự động thành công. Email kích hoạt đã được gửi tới người dùng.',
            ]);

        $this->assertNotEmpty($response->json('data.username'));
        $this->assertEquals($payload['email'], $response->json('data.email'));
    }

    /**
     * 2. Username tự sinh (unique, pattern NV_xxxxxx, không vượt VARCHAR)
     */
    public function test_02_username_tu_sinh_dung_pattern_va_unique(): void
    {
        Mail::fake();

        $username1 = $this->service->generateUniqueUsername('NV');
        $username2 = $this->service->generateUniqueUsername('NV');

        $this->assertNotEmpty($username1);
        $this->assertStringStartsWith('NV_', $username1);
        $this->assertLessThanOrEqual(60, strlen($username1));
        $this->assertNotEquals($username1, $username2);

        // Kiểm tra xử lý collision nếu username đã tồn tại
        User::create([
            'username' => $username1,
            'email' => 'collision_'.Str::random(6).'@example.com',
            'phone_number' => '091'.random_int(1000000, 9999999),
            'full_name' => 'Tài Khoản Đã Có',
            'password_hash' => Hash::make('Pass@123'),
            'status' => 'PENDING_ACTIVATION',
        ]);

        $username3 = $this->service->generateUniqueUsername('NV');
        $this->assertNotEquals($username1, $username3);
        $this->assertFalse(User::where('username', $username3)->exists());
    }

    /**
     * 3. User được tạo trong database với trạng thái PENDING_ACTIVATION
     */
    public function test_03_user_duoc_tao_voi_trang_thai_pending_activation(): void
    {
        Mail::fake();

        $email = 'user_created_'.Str::random(6).'@example.com';
        $phone = '097'.random_int(1000000, 9999999);
        $payload = [
            'full_name' => 'Lê Thị Thu',
            'email' => $email,
            'phone_number' => $phone,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'phone_number' => $phone,
            'full_name' => 'Lê Thị Thu',
            'status' => 'PENDING_ACTIVATION',
        ]);
    }

    /**
     * 4. Password được hash (không lưu plain text, không trả về qua API)
     */
    public function test_04_password_duoc_hash_va_khong_lo_plain_text(): void
    {
        Mail::fake();

        $email = 'hash_check_'.Str::random(6).'@example.com';
        $payload = [
            'full_name' => 'Phạm Văn Hash',
            'email' => $email,
            'phone_number' => '096'.random_int(1000000, 9999999),
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        $response->assertStatus(201);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertNotEmpty($user->password_hash);
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        // Đảm bảo không lộ hash hoặc password thô trong API response
        $content = $response->getContent();
        $this->assertStringNotContainsString('password_hash', $content);
        $this->assertStringNotContainsString('"password":', $content);
    }

    /**
     * 5. Email activation được gửi (không chứa password hay hash)
     */
    public function test_05_email_activation_duoc_gui_khong_chua_password(): void
    {
        Mail::fake();

        $email = strtolower('mail_activation_'.Str::random(6).'@example.com');
        $payload = [
            'full_name' => 'Hoàng Minh Thắng',
            'email' => $email,
            'phone_number' => '093'.random_int(1000000, 9999999),
        ];

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        Mail::assertSent(AccountActivationMail::class, function ($mail) use ($email) {
            $this->assertEquals($email, $mail->user->email);
            $this->assertNotEmpty($mail->activationUrl);
            $this->assertStringContainsString('/kich-hoat-tai-khoan', $mail->activationUrl);

            // Render view email kiểm tra nội dung
            $rendered = $mail->render();
            $this->assertStringContainsString('Bạn đã được cấp tài khoản', $rendered);
            $this->assertStringContainsString('Xin chào Hoàng Minh Thắng', $rendered);
            $this->assertStringContainsString('KÍCH HOẠT TÀI KHOẢN', $rendered);
            $this->assertStringNotContainsString('password_hash', $rendered);

            return true;
        });
    }

    /**
     * 6. Link activation hợp lệ (chứa token khớp với database)
     */
    public function test_06_link_activation_hop_le(): void
    {
        Mail::fake();

        $email = 'link_valid_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Ngô Văn Link',
            'email' => $email,
            'phone_number' => '092'.random_int(1000000, 9999999),
        ], $this->admin);

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
        $this->assertNotNull($record);
        $this->assertNotEmpty($record->token);
    }

    /**
     * 7. Nhân viên đặt password thành công (status chuyển ACTIVE, password được cập nhật)
     */
    public function test_07_nhan_vien_dat_password_thanh_cong(): void
    {
        $email = 'activate_success_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Vũ Thị Hoa',
            'email' => $email,
            'phone_number' => '094'.random_int(1000000, 9999999),
        ], $this->admin);

        $plainToken = 'valid_secret_token_'.Str::random(32);
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'EmployeePass@2026',
            'password_confirmation' => 'EmployeePass@2026',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $user = User::where('email', $email)->first();
        $this->assertEquals('ACTIVE', $user->status);
        $this->assertTrue(Hash::check('EmployeePass@2026', $user->password_hash));
    }

    /**
     * 8. Password confirmation sai trả về lỗi 422
     */
    public function test_08_password_confirmation_sai_tra_ve_loi(): void
    {
        $email = 'confirm_fail_'.Str::random(6).'@example.com';
        $this->service->provisionAccount([
            'full_name' => 'Trần Lỗi Xác Nhận',
            'email' => $email,
            'phone_number' => '095'.random_int(1000000, 9999999),
        ], $this->admin);

        $plainToken = 'token_test_confirm_123';
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'Password123@',
            'password_confirmation' => 'PasswordKhongKhop456@',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 9. Token invalid trả về lỗi 422
     */
    public function test_09_token_invalid_tra_ve_loi(): void
    {
        $email = 'invalid_token_'.Str::random(6).'@example.com';
        $this->service->provisionAccount([
            'full_name' => 'Đỗ Văn Token',
            'email' => $email,
            'phone_number' => '090'.random_int(1000000, 9999999),
        ], $this->admin);

        $response = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => 'completely_wrong_token_xyz',
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'INVALID_TOKEN',
            ]);
    }

    /**
     * 10. Token expired trả về lỗi 422
     */
    public function test_10_token_expired_tra_ve_loi(): void
    {
        $email = 'expired_tok_'.Str::random(6).'@example.com';
        $this->service->provisionAccount([
            'full_name' => 'Lý Hết Hạn',
            'email' => $email,
            'phone_number' => '098'.random_int(1000000, 9999999),
        ], $this->admin);

        $plainToken = 'expired_plain_token_123';
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now()->subHours(50), // Quá 48h
        ]);

        $response = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'INVALID_TOKEN',
            ]);
    }

    /**
     * 11. Token dùng lại không được (vô hiệu hóa token sau khi kích hoạt thành công)
     */
    public function test_11_token_dung_lai_khong_duoc(): void
    {
        $email = 'reuse_token_'.Str::random(6).'@example.com';
        $this->service->provisionAccount([
            'full_name' => 'Bùi Dùng Lại',
            'email' => $email,
            'phone_number' => '097'.random_int(1000000, 9999999),
        ], $this->admin);

        $plainToken = 'reuse_plain_token_12345';
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now(),
        ]);

        // Lần 1: Thành công
        $firstAttempt = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'FirstPass@123',
            'password_confirmation' => 'FirstPass@123',
        ]);
        $firstAttempt->assertStatus(200);

        // Lần 2: Token đã bị xóa khỏi bảng password_reset_tokens hoặc tài khoản đã active, không thể dùng lại
        $secondAttempt = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'SecondPass@456',
            'password_confirmation' => 'SecondPass@456',
        ]);
        $this->assertContains($secondAttempt->status(), [400, 422]);
        $this->assertFalse($secondAttempt->json('success'));
        $this->assertContains($secondAttempt->json('error'), ['ALREADY_ACTIVE', 'INVALID_TOKEN']);
    }

    /**
     * 12. Email duplicate trả về lỗi 422
     */
    public function test_12_email_duplicate_tra_ve_loi(): void
    {
        $dupEmail = 'duplicate_email_'.Str::random(6).'@example.com';
        User::create([
            'username' => 'existing_u_'.Str::random(6),
            'email' => $dupEmail,
            'phone_number' => '091'.random_int(1000000, 9999999),
            'full_name' => 'Tài Khoản Có Sẵn',
            'password_hash' => Hash::make('Pass@123'),
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'full_name' => 'Trùng Email Test',
            'email' => $dupEmail,
            'phone_number' => '098'.random_int(1000000, 9999999),
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 13. Phone duplicate trả về lỗi 422
     */
    public function test_13_phone_duplicate_tra_ve_loi(): void
    {
        $dupPhone = '099'.random_int(1000000, 9999999);
        User::create([
            'username' => 'existing_ph_'.Str::random(6),
            'email' => 'unique_ph_'.Str::random(6).'@example.com',
            'phone_number' => $dupPhone,
            'full_name' => 'Tài Khoản Trùng SĐT',
            'password_hash' => Hash::make('Pass@123'),
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'full_name' => 'Người Dùng Mới',
            'email' => 'new_email_'.Str::random(6).'@example.com',
            'phone_number' => $dupPhone,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/account-provisioning', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    /**
     * 14. Resend activation gửi lại email kích hoạt
     */
    public function test_14_resend_activation_gui_lai_email(): void
    {
        Mail::fake();

        $email = 'resend_email_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Mai Văn Gửi Lại',
            'email' => $email,
            'phone_number' => '093'.random_int(1000000, 9999999),
        ], $this->admin);

        $userId = $provisioned['user']['id'];

        // Reset cooldown để cho phép resend
        QuocTinRealtimeService::clearModuleCooldown('account_provisioning');
        $user = User::find($userId);
        $extra = $user->extra_preferences;
        $extra['provisioning']['activation_sent_at'] = Carbon::now()->subMinutes(2)->toIso8601String();
        $user->extra_preferences = $extra;
        $user->save();

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/account-provisioning/{$userId}/resend-activation");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'resend_count' => 1,
                ],
            ]);

        Mail::assertSent(AccountActivationMail::class);
    }

    /**
     * 15. Không tạo duplicate user khi resend email
     */
    public function test_15_resend_activation_khong_tao_duplicate_user(): void
    {
        Mail::fake();

        $email = 'no_dup_user_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Đinh Không Duplicate',
            'email' => $email,
            'phone_number' => '094'.random_int(1000000, 9999999),
        ], $this->admin);

        $userId = $provisioned['user']['id'];

        $user = User::find($userId);
        $extra = $user->extra_preferences;
        $extra['provisioning']['activation_sent_at'] = Carbon::now()->subMinutes(2)->toIso8601String();
        $user->extra_preferences = $extra;
        $user->save();

        $countBefore = User::where('email', $email)->count();
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/account-provisioning/{$userId}/resend-activation");
        $countAfter = User::where('email', $email)->count();

        $this->assertEquals(1, $countBefore);
        $this->assertEquals(1, $countAfter);
    }

    /**
     * 16. Nhân viên đăng nhập thành công bằng username và mật khẩu vừa đặt
     */
    public function test_16_nhan_vien_dang_nhap_thanh_cong_sau_khi_kich_hoat(): void
    {
        $email = 'login_after_act_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Nhân Viên Đăng Nhập Mới',
            'email' => $email,
            'phone_number' => '092'.random_int(1000000, 9999999),
            'roles' => ['RECEPTIONIST'],
        ], $this->admin);

        $username = $provisioned['user']['username'];
        $plainToken = 'login_test_token_'.Str::random(32);

        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now(),
        ]);

        // Kích hoạt với mật khẩu mới
        $newPassword = 'MySecretPass@2026';
        $actRes = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);
        $actRes->assertStatus(200);

        // Đăng nhập bằng Username + Mật khẩu vừa đặt
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $newPassword,
        ]);

        $loginRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertNotEmpty($loginRes->json('access_token'));
        $this->assertEquals($username, $loginRes->json('user.username'));
    }

    /**
     * 17. Chưa kích hoạt tài khoản thì không thể đăng nhập (status = PENDING_ACTIVATION)
     */
    public function test_17_chua_kich_hoat_khong_the_dang_nhap(): void
    {
        $email = 'pending_login_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Chưa Kích Hoạt Đòi Login',
            'email' => $email,
            'phone_number' => '093'.random_int(1000000, 9999999),
        ], $this->admin);

        $username = $provisioned['user']['username'];

        // Cố đăng nhập khi tài khoản chưa kích hoạt
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => 'AnyPassword@123',
        ]);

        // Trả về 401 (sai pass) hoặc 403 (tài khoản chưa active)
        $this->assertContains($loginRes->status(), [401, 403]);
    }

    /**
     * 18. Đã kích hoạt 1 lần rồi thì lần 2 báo Đã kích hoạt tài khoản bạn vui lòng đăng nhập
     */
    public function test_18_da_kich_hoat_mot_lan_thi_lan_hai_bao_da_kich_hoat_vui_long_dang_nhap(): void
    {
        $email = 'act_twice_'.Str::random(6).'@example.com';
        $provisioned = $this->service->provisionAccount([
            'full_name' => 'Nhân Viên Kích Hoạt Lần Hai',
            'email' => $email,
            'phone_number' => '091'.random_int(1000000, 9999999),
        ], $this->admin);

        $plainToken = 'test_token_'.Str::random(32);
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now(),
        ]);

        // Lần 1: Kích hoạt thành công
        $firstAct = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'FirstPass@2026',
            'password_confirmation' => 'FirstPass@2026',
        ]);
        $firstAct->assertStatus(200);

        // Kiểm tra status endpoint không cần Bearer token: báo Đã kích hoạt tài khoản bạn vui lòng đăng nhập
        $statusRes = $this->getJson("/api/v1/account-provisioning/status?email={$email}");
        $statusRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'ALREADY_ACTIVE',
                'is_activated' => true,
                'message' => 'Đã kích hoạt tài khoản bạn vui lòng đăng nhập.',
            ]);

        // Lần 2: Cố kích hoạt lại với token hoặc mật khẩu mới
        $secondAct = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'SecondPass@2026',
            'password_confirmation' => 'SecondPass@2026',
        ]);
        $secondAct->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'ALREADY_ACTIVE',
                'message' => 'Đã kích hoạt tài khoản bạn vui lòng đăng nhập.',
            ]);
    }
}
