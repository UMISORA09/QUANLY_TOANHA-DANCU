<?php

namespace Tests\Feature\AccountProvisioning;

use App\Mail\AccountActivationMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProvisioningForbiddenException;
use App\Services\AccountProvisioningService;
use App\Services\AccountProvisioningValidationException;
use App\Services\QuocTinRealtimeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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

        $userId = $provisioned['user']['id'];

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

        // Kiểm tra status endpoint theo tham số liên kết kích hoạt (id + email): báo Đã kích hoạt và không lộ PII
        $statusRes = $this->getJson("/api/v1/account-provisioning/status?id={$userId}&email={$email}");
        $statusRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'ALREADY_ACTIVE',
                'is_activated' => true,
                'message' => 'Đã kích hoạt tài khoản bạn vui lòng đăng nhập.',
            ])
            ->assertJsonMissingPath('username')
            ->assertJsonMissingPath('full_name')
            ->assertJsonMissingPath('email');

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

    /**
     * Helper: tạo (hoặc lấy) vai trò và gắn tập permission code cho test phân quyền.
     *
     * @param  array<int, string>  $permissionCodes
     */
    private function ensureRoleWithPermissions(string $roleCode, string $roleName, array $permissionCodes = [], bool $isSystemRole = true): Role
    {
        $role = Role::where('role_code', $roleCode)->first();
        if (! $role) {
            $role = Role::create([
                'role_code' => $roleCode,
                'role_name' => $roleName,
                'description' => $roleName,
                'is_system_role' => $isSystemRole,
            ]);
        }

        foreach ($permissionCodes as $code) {
            $perm = Permission::where('permission_code', $code)->first();
            if (! $perm) {
                $module = explode(':', $code)[0] ?: 'SYSTEM';
                $perm = Permission::create([
                    'module' => $module,
                    'permission_code' => $code,
                    'permission_name' => $code,
                    'description' => $code,
                    'created_at' => now(),
                ]);
            }

            $exists = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $perm->id)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $role->id,
                    'permission_id' => $perm->id,
                    'created_at' => now(),
                ]);
            }
        }

        Cache::flush();

        return $role;
    }

    /**
     * Helper: tạo tài khoản test ACTIVE, gán vai trò và cấp Bearer token.
     *
     * @param  array<int, string>  $roleCodes
     * @param  array<int, string>  $permissions
     * @return array{user: User, token: string, role: Role}
     */
    private function createActiveActorWithRole(string $roleCode, string $roleName, array $permissions = [], bool $isSystemRole = true, ?string $suffix = null): array
    {
        $role = $this->ensureRoleWithPermissions($roleCode, $roleName, $permissions, $isSystemRole);
        $tag = $suffix ?? substr((string) Str::uuid(), 0, 8);
        $digits = str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

        $user = User::create([
            'username' => strtolower($roleCode).'_'.$tag,
            'full_name' => $roleName.' '.$tag,
            'email' => strtolower($roleCode).'.'.$tag.'@test.local',
            'phone_number' => '091'.$digits,
            'national_id_number' => '079099'.substr($digits, 0, 6),
            'gender' => 'MALE',
            'password_hash' => Hash::make('Secret@2026'),
            'status' => 'ACTIVE',
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => true,
            'assigned_at' => now(),
            'assigned_by' => $this->admin->id,
        ]);

        Cache::flush();
        $token = $this->issueTestBearerToken($user);

        return ['user' => $user, 'token' => $token, 'role' => $role];
    }

    /**
     * Helper: payload hợp lệ cơ bản để tạo tài khoản.
     *
     * @param  array<int, string>  $roles
     * @return array<string, mixed>
     */
    private function validProvisioningPayload(array $roles = ['RECEPTIONIST'], ?string $tag = null): array
    {
        $tag = $tag ?? substr((string) Str::uuid(), 0, 8);
        $digits = str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

        return [
            'full_name' => 'Test User '.$tag,
            'email' => 'user.'.$tag.'@test.local',
            'phone_number' => '098'.$digits,
            'national_id_number' => '079088'.substr($digits, 0, 6),
            'gender' => 'FEMALE',
            'roles' => $roles,
        ];
    }

    public function test_19_super_admin_can_provision_update_toggle_lock_and_delete_accounts_including_secondary_super_admin(): void
    {
        Mail::fake();
        $this->ensureRoleWithPermissions('BUILDING_MANAGER', 'Building Manager', ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE']);
        $this->ensureRoleWithPermissions('RECEPTIONIST', 'Receptionist', []);

        // 1. SUPER_ADMIN tạo RECEPTIONIST, BUILDING_MANAGER và một SUPER_ADMIN phụ
        $recRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'rec01'));
        $recRes->assertStatus(201)->assertJsonPath('success', true);

        $mgrRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['BUILDING_MANAGER'], 'mgr01'));
        $mgrRes->assertStatus(201)->assertJsonPath('success', true);

        $sa2Res = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['SUPER_ADMIN'], 'sa02'));
        $sa2Res->assertStatus(201)->assertJsonPath('success', true);
        $sa2Id = $sa2Res->json('data.id');

        // Kích hoạt SUPER_ADMIN phụ để có > 1 SUPER_ADMIN ACTIVE
        $sa2 = User::findOrFail($sa2Id);
        $sa2->update(['status' => 'ACTIVE']);

        // 2. SUPER_ADMIN cập nhật vai trò SUPER_ADMIN phụ -> BUILDING_MANAGER -> SUPER_ADMIN
        $downgradeRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->putJson("/api/v1/account-provisioning/{$sa2Id}", [
                'full_name' => 'Secondary Admin Updated',
                'roles' => ['BUILDING_MANAGER'],
            ]);
        $downgradeRes->assertStatus(200)->assertJsonPath('success', true);

        $repromoteRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->putJson("/api/v1/account-provisioning/{$sa2Id}", [
                'full_name' => 'Secondary Super Admin',
                'roles' => ['SUPER_ADMIN'],
            ]);
        $repromoteRes->assertStatus(200)->assertJsonPath('success', true);

        // 3. SUPER_ADMIN khóa rồi mở khóa và xóa SUPER_ADMIN phụ
        $lockRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson("/api/v1/account-provisioning/{$sa2Id}/toggle-lock");
        $lockRes->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('LOCKED', User::findOrFail($sa2Id)->status);

        $unlockRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson("/api/v1/account-provisioning/{$sa2Id}/toggle-lock");
        $unlockRes->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('ACTIVE', User::findOrFail($sa2Id)->status);

        $deleteRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->deleteJson("/api/v1/account-provisioning/{$sa2Id}");
        $deleteRes->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSoftDeleted('users', ['id' => $sa2Id]);

        // 4. Không thể gỡ quyền/khóa/xóa SUPER_ADMIN cuối cùng còn ACTIVE ($this->admin)
        // Cô lập trong transaction của test để $this->admin là SUPER_ADMIN ACTIVE duy nhất bất chấp dữ liệu tồn dư trên DB test
        $otherSuperAdminIds = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.role_code', ['SUPER_ADMIN', 'SUPER_ADMI'])
            ->where('user_roles.user_id', '!=', $this->admin->id)
            ->pluck('user_roles.user_id')
            ->unique()
            ->values()
            ->all();

        if (! empty($otherSuperAdminIds)) {
            DB::table('users')
                ->whereIn('id', $otherSuperAdminIds)
                ->update(['status' => 'INACTIVE']);
        }

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->putJson("/api/v1/account-provisioning/{$this->admin->id}", ['roles' => ['RECEPTIONIST']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson("/api/v1/account-provisioning/{$this->admin->id}/toggle-lock")
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');

        $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->deleteJson("/api/v1/account-provisioning/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');

        $this->admin->refresh();
        $this->assertEquals('ACTIVE', $this->admin->status);
        $this->assertNull($this->admin->deleted_at);
        $this->assertContains('SUPER_ADMIN', $this->service->getUserRoleCodesFromDatabase($this->admin));
    }

    public function test_20_regular_resident_and_receptionist_cannot_create_super_admin_or_access_provisioning_endpoints(): void
    {
        Mail::fake();

        $residentOwner = $this->createActiveActorWithRole('RESIDENT_OWNER', 'Resident Owner', []);
        $receptionist = $this->createActiveActorWithRole('RECEPTIONIST', 'Receptionist', []);
        $target = $this->createActiveActorWithRole('RECEPTIONIST', 'Target Rec', [], true, 'tgt01')['user'];

        $beforeUsers = User::withTrashed()->count();
        $beforeRoles = DB::table('user_roles')->count();
        $beforeTokens = DB::table('password_reset_tokens')->count();

        // Chưa đăng nhập -> 401
        $this->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['SUPER_ADMIN']))
            ->assertStatus(401);

        foreach ([$this->regularToken, $residentOwner['token'], $receptionist['token']] as $token) {
            $headers = ['Authorization' => 'Bearer '.$token];

            $this->withHeaders($headers)->getJson('/api/v1/account-provisioning')->assertStatus(403);
            $this->withHeaders($headers)->getJson("/api/v1/account-provisioning/{$target->id}")->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['SUPER_ADMIN']))
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST']))
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$target->id}", ['roles' => ['SUPER_ADMIN']])
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson('/api/v1/account-provisioning/import', [
                    'records' => [$this->validProvisioningPayload(['SUPER_ADMIN'])],
                ])
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson("/api/v1/account-provisioning/{$target->id}/toggle-lock")
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson("/api/v1/account-provisioning/{$target->id}/resend-activation")
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->postJson('/api/v1/account-provisioning/batch-resend')
                ->assertStatus(403);

            $this->withHeaders($headers)
                ->deleteJson("/api/v1/account-provisioning/{$target->id}")
                ->assertStatus(403);
        }

        $this->assertSame($beforeUsers, User::withTrashed()->count());
        $this->assertSame($beforeRoles, DB::table('user_roles')->count());
        $this->assertSame($beforeTokens, DB::table('password_reset_tokens')->count());
        Mail::assertNothingSent();
    }

    public function test_21_admin_and_building_manager_cannot_self_elevate_to_super_admin_or_other_roles(): void
    {
        Mail::fake();

        $adminActor = $this->createActiveActorWithRole('ADMIN', 'Admin', []);
        $managerActor = $this->createActiveActorWithRole(
            'BUILDING_MANAGER',
            'Building Manager',
            ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE']
        );

        foreach ([
            ['actor' => $adminActor, 'ownRole' => 'ADMIN'],
            ['actor' => $managerActor, 'ownRole' => 'BUILDING_MANAGER'],
        ] as $case) {
            $user = $case['actor']['user'];
            $headers = ['Authorization' => 'Bearer '.$case['actor']['token']];
            $ownRole = $case['ownRole'];

            // Tự nâng lên SUPER_ADMIN (đơn lẻ & hỗn hợp)
            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$user->id}", ['roles' => ['SUPER_ADMIN']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$user->id}", ['roles' => [$ownRole, 'SUPER_ADMIN']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            // Tự đổi sang vai trò khác (RECEPTIONIST / ADMIN)
            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$user->id}", ['roles' => ['RECEPTIONIST']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            // Tự nâng via spoofed field
            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$user->id}", [
                    'full_name' => 'Self Spoofed',
                    'role' => 'SUPER_ADMIN',
                ])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            // Xác nhận DB chỉ còn duy nhất vai trò ban đầu
            $dbRoles = $this->service->getUserRoleCodesFromDatabase($user);
            $this->assertSame([$ownRole], $dbRoles);

            // Sửa hồ sơ cá nhân hợp lệ (không đổi role) vẫn thành công
            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$user->id}", [
                    'full_name' => 'Legitimate Self Profile Update',
                    'roles' => [$ownRole],
                ])
                ->assertStatus(200)
                ->assertJsonPath('success', true);
        }

        // BUILDING_MANAGER cố tự nâng lên ADMIN cũng bị chặn
        $this->withHeaders(['Authorization' => 'Bearer '.$managerActor['token']])
            ->putJson("/api/v1/account-provisioning/{$managerActor['user']->id}", ['roles' => ['ADMIN']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_22_non_super_admin_cannot_provision_or_escalate_other_users_to_privileged_roles_via_http_or_service(): void
    {
        Mail::fake();

        $superAdminRole = Role::where('role_code', 'SUPER_ADMIN')->firstOrFail();
        $specialCustomRole = $this->ensureRoleWithPermissions('CUSTOM_ESCALATE_ROLE', 'Custom Special Role', ['SYSTEM:SPECIAL'], false);
        $this->ensureRoleWithPermissions('CUSTOM_USER_TEST_ADMIN', 'Custom Override Role', ['USER:VIEW'], true);

        $adminActor = $this->createActiveActorWithRole('ADMIN', 'Admin', []);
        $managerActor = $this->createActiveActorWithRole(
            'BUILDING_MANAGER',
            'Building Manager',
            ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE']
        );
        $createOnlyActor = $this->createActiveActorWithRole('USER_CREATOR_ONLY', 'Creator Helper', ['USER:CREATE'], false);
        $target = $this->createActiveActorWithRole('RECEPTIONIST', 'Normal Staff', [], true, 'norm01')['user'];

        $restrictedRoleInputs = [
            ['SUPER_ADMIN'],
            ['super_admin'],
            ['  Super_Admin  '],
            ['SUPER_ADMI'],
            ['ADMIN'],
            ['CUSTOM_USER_TEST_ADMIN'],
            [$superAdminRole->id],
            [$specialCustomRole->role_code],
            ['RECEPTIONIST', 'SUPER_ADMIN'],
        ];

        $beforeUsers = User::withTrashed()->count();
        $beforeRoles = DB::table('user_roles')->count();
        $beforeTokens = DB::table('password_reset_tokens')->count();

        foreach ([$adminActor, $managerActor, $createOnlyActor] as $actorInfo) {
            $headers = ['Authorization' => 'Bearer '.$actorInfo['token']];

            foreach ($restrictedRoleInputs as $rolesPayload) {
                $this->withHeaders($headers)
                    ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload($rolesPayload))
                    ->assertStatus(403)
                    ->assertJsonPath('error', 'FORBIDDEN');

                if ($actorInfo['user']->id !== $createOnlyActor['user']->id) {
                    $this->withHeaders($headers)
                        ->putJson("/api/v1/account-provisioning/{$target->id}", ['roles' => $rolesPayload])
                        ->assertStatus(403)
                        ->assertJsonPath('error', 'FORBIDDEN');
                }
            }

            // Import batch hỗn hợp: dòng 0 hợp lệ, dòng 1 nâng SUPER_ADMIN -> chặn toàn bộ (0 bản ghi)
            $this->withHeaders($headers)
                ->postJson('/api/v1/account-provisioning/import', [
                    'records' => [
                        $this->validProvisioningPayload(['RECEPTIONIST'], 'mixok'),
                        $this->validProvisioningPayload(['SUPER_ADMIN'], 'mixsa'),
                    ],
                ])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // Gọi trực tiếp Service với $actor = null hoặc non-SUPER_ADMIN đều bị chặn
        $threwOnNullActor = false;
        try {
            $this->service->provisionAccount($this->validProvisioningPayload(['SUPER_ADMIN']), null);
        } catch (AccountProvisioningForbiddenException) {
            $threwOnNullActor = true;
        }
        $this->assertTrue($threwOnNullActor);

        $threwOnAdminService = false;
        try {
            $this->service->provisionAccount($this->validProvisioningPayload(['SUPER_ADMIN']), $adminActor['user']);
        } catch (AccountProvisioningForbiddenException) {
            $threwOnAdminService = true;
        }
        $this->assertTrue($threwOnAdminService);

        $threwOnUpdateService = false;
        try {
            $this->service->updateAccount($target->id, ['roles' => ['SUPER_ADMIN']], $managerActor['user']);
        } catch (AccountProvisioningForbiddenException) {
            $threwOnUpdateService = true;
        }
        $this->assertTrue($threwOnUpdateService);

        $threwOnImportService = false;
        try {
            $this->service->importAccounts([
                $this->validProvisioningPayload(['RECEPTIONIST'], 'svcmix1'),
                $this->validProvisioningPayload(['SUPER_ADMIN'], 'svcmix2'),
            ], $managerActor['user']);
        } catch (AccountProvisioningForbiddenException) {
            $threwOnImportService = true;
        }
        $this->assertTrue($threwOnImportService);

        // Xác nhận 0 thay đổi DB và 0 mail gửi đi
        $this->assertSame(['RECEPTIONIST'], $this->service->getUserRoleCodesFromDatabase($target));
        $this->assertSame($beforeUsers, User::withTrashed()->count());
        $this->assertSame($beforeRoles, DB::table('user_roles')->count());
        $this->assertSame($beforeTokens, DB::table('password_reset_tokens')->count());
        Mail::assertNothingSent();
    }

    public function test_23_non_super_admin_cannot_modify_lock_delete_or_resend_activation_for_super_admin_targets(): void
    {
        Mail::fake();

        // Tạo 1 SUPER_ADMIN phụ ở trạng thái PENDING_ACTIVATION bởi SUPER_ADMIN thật
        $pendingSaRes = $this->withHeaders(['Authorization' => 'Bearer '.$this->adminToken])
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['SUPER_ADMIN'], 'sapend'));
        $pendingSaRes->assertStatus(201);
        $pendingSaId = $pendingSaRes->json('data.id');

        $adminWithDelete = $this->createActiveActorWithRole(
            'BUILDING_MANAGER',
            'Manager With Extra Delete',
            ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:DELETE', 'USER:ASSIGN_ROLE']
        );
        $plainAdmin = $this->createActiveActorWithRole('ADMIN', 'Admin', []);

        // Tạo thêm 1 RECEPTIONIST đang PENDING_ACTIVATION để kiểm tra batch-resend bỏ qua SUPER_ADMIN
        $pendingStaffRes = $this->withHeaders(['Authorization' => 'Bearer '.$adminWithDelete['token']])
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'stpend'));
        $pendingStaffRes->assertStatus(201);
        $pendingStaffId = $pendingStaffRes->json('data.id');
        $pendingStaffEmail = $pendingStaffRes->json('data.email');
        $pendingSaEmail = User::findOrFail($pendingSaId)->email;

        // Cho hết cooldown resend của cả hai tài khoản PENDING để kiểm chứng batch-resend thực sự lọc theo quyền SUPER_ADMIN
        QuocTinRealtimeService::clearModuleCooldown('account_provisioning');
        foreach ([$pendingSaId, $pendingStaffId] as $uid) {
            $u = User::findOrFail($uid);
            $extra = $u->extra_preferences ?? [];
            $extra['provisioning']['activation_sent_at'] = \Carbon\Carbon::now()->subMinutes(2)->toIso8601String();
            $u->extra_preferences = $extra;
            $u->save();
        }

        Mail::fake();

        foreach ([$adminWithDelete['token'], $plainAdmin['token']] as $token) {
            $headers = ['Authorization' => 'Bearer '.$token];

            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$this->admin->id}", ['full_name' => 'Tampered'])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->putJson("/api/v1/account-provisioning/{$pendingSaId}", ['roles' => ['RECEPTIONIST']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->postJson("/api/v1/account-provisioning/{$this->admin->id}/toggle-lock")
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->deleteJson("/api/v1/account-provisioning/{$pendingSaId}")
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->postJson("/api/v1/account-provisioning/{$pendingSaId}/resend-activation")
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // batch-resend do BUILDING_MANAGER chạy chỉ gửi lại cho nhân viên thường, KHÔNG gửi cho PENDING SUPER_ADMIN
        $batchRes = $this->withHeaders(['Authorization' => 'Bearer '.$adminWithDelete['token']])
            ->postJson('/api/v1/account-provisioning/batch-resend');
        $batchRes->assertStatus(200)->assertJsonPath('success', true);

        Mail::assertSent(AccountActivationMail::class, function ($mail) use ($pendingStaffEmail) {
            return $mail->hasTo($pendingStaffEmail);
        });
        Mail::assertNotSent(AccountActivationMail::class, function ($mail) use ($pendingSaEmail) {
            return $mail->hasTo($pendingSaEmail);
        });

        $this->assertEquals('ACTIVE', User::findOrFail($this->admin->id)->status);
        $this->assertNull(User::findOrFail($pendingSaId)->deleted_at);
    }

    public function test_24_spoofed_privilege_fields_and_unknown_roles_are_strictly_rejected(): void
    {
        Mail::fake();

        $managerActor = $this->createActiveActorWithRole(
            'BUILDING_MANAGER',
            'Building Manager',
            ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE']
        );
        $target = $this->createActiveActorWithRole('RECEPTIONIST', 'Staff', [], true, 'spoof01')['user'];
        $managerHeaders = ['Authorization' => 'Bearer '.$managerActor['token']];
        $saHeaders = ['Authorization' => 'Bearer '.$this->adminToken];

        $beforeUsers = User::withTrashed()->count();
        $spoofedPayloads = [
            ['role' => 'SUPER_ADMIN'],
            ['role_code' => 'SUPER_ADMIN'],
            ['role_ids' => ['fake-uuid']],
            ['permissions' => ['SYSTEM:SPECIAL']],
            ['is_super_admin' => true],
            ['status' => 'ACTIVE'],
        ];

        foreach ($spoofedPayloads as $spoof) {
            $this->withHeaders($managerHeaders)
                ->postJson('/api/v1/account-provisioning', array_merge($this->validProvisioningPayload(['RECEPTIONIST']), $spoof))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($saHeaders)
                ->postJson('/api/v1/account-provisioning', array_merge($this->validProvisioningPayload(['RECEPTIONIST']), $spoof))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($managerHeaders)
                ->putJson("/api/v1/account-provisioning/{$target->id}", array_merge(['full_name' => 'Spoof Update'], $spoof))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($managerHeaders)
                ->postJson('/api/v1/account-provisioning/import', [
                    'records' => [array_merge($this->validProvisioningPayload(['RECEPTIONIST']), $spoof)],
                ])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // Vai trò không tồn tại bị trả 422 thay vì fallback ngầm
        $this->withHeaders($managerHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['UNKNOWN_GHOST_ROLE']))
            ->assertStatus(422);

        $this->withHeaders($saHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['UNKNOWN_GHOST_ROLE']))
            ->assertStatus(422);

        $this->assertSame($beforeUsers, User::withTrashed()->count());
        Mail::assertNothingSent();

        // Kích hoạt công khai kèm payload giả mạo roles/permissions/is_super_admin phải bị chặn 403
        $created = $this->withHeaders($managerHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'actspoof'));
        $created->assertStatus(201);
        $email = $created->json('data.email');

        $tokenPlain = Str::random(64);
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->update(['token' => Hash::make($tokenPlain), 'created_at' => now()]);

        foreach ([
            ['roles' => ['SUPER_ADMIN']],
            ['permissions' => ['SYSTEM:SPECIAL']],
            ['is_super_admin' => true],
        ] as $spoofActivate) {
            $this->postJson('/api/v1/account-provisioning/activate', array_merge([
                'email' => $email,
                'token' => $tokenPlain,
                'password' => 'StrongPass@2026',
                'password_confirmation' => 'StrongPass@2026',
            ], $spoofActivate))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        $pendingUser = User::where('email', $email)->firstOrFail();
        $this->assertEquals('PENDING_ACTIVATION', $pendingUser->status);
        $this->assertSame(['RECEPTIONIST'], $this->service->getUserRoleCodesFromDatabase($pendingUser));

        // Kích hoạt hợp lệ sau đó thành công và giữ nguyên vai trò RECEPTIONIST
        $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $tokenPlain,
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
        ])->assertStatus(200)->assertJsonPath('success', true);

        $pendingUser->refresh();
        $this->assertEquals('ACTIVE', $pendingUser->status);
        $this->assertSame(['RECEPTIONIST'], $this->service->getUserRoleCodesFromDatabase($pendingUser));
    }

    public function test_25_legitimate_manager_and_admin_flows_and_action_level_permissions_work_without_regression(): void
    {
        Mail::fake();

        $this->ensureRoleWithPermissions('RECEPTIONIST', 'Receptionist', []);
        $this->ensureRoleWithPermissions('RESIDENT_OWNER', 'Resident Owner', []);
        $this->ensureRoleWithPermissions('ACCOUNTANT', 'Accountant', []);

        $managerActor = $this->createActiveActorWithRole(
            'BUILDING_MANAGER',
            'Building Manager',
            ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE']
        );
        $managerHeaders = ['Authorization' => 'Bearer '.$managerActor['token']];

        // 1. BUILDING_MANAGER tạo RECEPTIONIST & RESIDENT_OWNER, xem list/detail, cập nhật, khóa, import và không có USER:DELETE
        $this->withHeaders($managerHeaders)->getJson('/api/v1/account-provisioning')->assertStatus(200);

        $recCreated = $this->withHeaders($managerHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'mgrrec'));
        $recCreated->assertStatus(201)->assertJsonPath('success', true);
        $recId = $recCreated->json('data.id');

        $this->withHeaders($managerHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RESIDENT_OWNER'], 'mgrres'))
            ->assertStatus(201);

        $this->withHeaders($managerHeaders)->getJson("/api/v1/account-provisioning/{$recId}")->assertStatus(200);

        $this->withHeaders($managerHeaders)
            ->putJson("/api/v1/account-provisioning/{$recId}", [
                'full_name' => 'Updated By Manager',
                'roles' => ['ACCOUNTANT'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertSame(['ACCOUNTANT'], $this->service->getUserRoleCodesFromDatabase(User::findOrFail($recId)));

        // Đặt lại cooldown gửi lại kích hoạt để BUILDING_MANAGER thực hiện resend hợp lệ
        QuocTinRealtimeService::clearModuleCooldown('account_provisioning');
        $recModel = User::findOrFail($recId);
        $recExtra = $recModel->extra_preferences ?? [];
        $recExtra['provisioning']['activation_sent_at'] = \Carbon\Carbon::now()->subMinutes(2)->toIso8601String();
        $recModel->extra_preferences = $recExtra;
        $recModel->save();

        $this->withHeaders($managerHeaders)->postJson("/api/v1/account-provisioning/{$recId}/resend-activation")->assertStatus(200);
        $this->withHeaders($managerHeaders)->postJson("/api/v1/account-provisioning/{$recId}/toggle-lock")->assertStatus(200);

        $this->withHeaders($managerHeaders)
            ->postJson('/api/v1/account-provisioning/import', [
                'records' => [$this->validProvisioningPayload(['RECEPTIONIST'], 'mgrimp')],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // BUILDING_MANAGER không có USER:DELETE -> 403
        $this->withHeaders($managerHeaders)->deleteJson("/api/v1/account-provisioning/{$recId}")->assertStatus(403);

        // 2. User chỉ có USER:VIEW -> xem được, không tạo/sửa/xóa/khóa được
        $viewOnly = $this->createActiveActorWithRole('STAFF_VIEW_ONLY', 'View Only', ['USER:VIEW'], false);
        $viewHeaders = ['Authorization' => 'Bearer '.$viewOnly['token']];
        $this->withHeaders($viewHeaders)->getJson('/api/v1/account-provisioning')->assertStatus(200);
        $this->withHeaders($viewHeaders)->getJson("/api/v1/account-provisioning/{$recId}")->assertStatus(200);
        $this->withHeaders($viewHeaders)->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST']))->assertStatus(403);
        $this->withHeaders($viewHeaders)->putJson("/api/v1/account-provisioning/{$recId}", ['full_name' => 'Nope'])->assertStatus(403);
        $this->withHeaders($viewHeaders)->deleteJson("/api/v1/account-provisioning/{$recId}")->assertStatus(403);

        // 3. User chỉ có USER:CREATE -> tạo được RECEPTIONIST, không gán được BUILDING_MANAGER, không gọi index/update/delete
        $createOnly = $this->createActiveActorWithRole('STAFF_CREATE_ONLY', 'Create Only', ['USER:CREATE'], false);
        $createHeaders = ['Authorization' => 'Bearer '.$createOnly['token']];
        $this->withHeaders($createHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'creok'))
            ->assertStatus(201);
        $this->withHeaders($createHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['BUILDING_MANAGER'], 'crefail'))
            ->assertStatus(403);
        $this->withHeaders($createHeaders)->getJson('/api/v1/account-provisioning')->assertStatus(403);
        $this->withHeaders($createHeaders)->putJson("/api/v1/account-provisioning/{$recId}", ['full_name' => 'Nope'])->assertStatus(403);
        $this->withHeaders($createHeaders)->deleteJson("/api/v1/account-provisioning/{$recId}")->assertStatus(403);

        // 4. Custom override role không có USER:CREATE phải mất quyền tạo dù có vai trò STAFF_CREATE_ONLY
        $overrideRole = $this->ensureRoleWithPermissions('CUSTOM_USER_NO_CREATE', 'Override No Create', ['USER:VIEW'], false);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $createOnly['user']->id,
            'role_id' => $overrideRole->id,
            'is_primary' => false,
            'assigned_at' => now(),
            'assigned_by' => $this->admin->id,
        ]);
        Cache::flush();

        $this->withHeaders($createHeaders)
            ->postJson('/api/v1/account-provisioning', $this->validProvisioningPayload(['RECEPTIONIST'], 'ovfail'))
            ->assertStatus(403);
    }

    /**
     * 26 (BUG-M1-06): Xác thực activation token từ chối raw bcrypt hash, token sai, token hết hạn và ngăn tái sử dụng token.
     */
    public function test_26_activation_rejects_raw_bcrypt_hash_and_enforces_token_lifecycle(): void
    {
        Mail::fake();

        $email = 'm106_token_'.Str::random(6).'@example.com';
        $this->service->provisionAccount([
            'full_name' => 'Nguyễn Văn Bảo Mật Token',
            'email' => $email,
            'phone_number' => '096'.random_int(1000000, 9999999),
            'roles' => ['RECEPTIONIST'],
        ], $this->admin);

        $plainToken = 'plain_secret_token_'.Str::random(32);
        $bcryptHash = Hash::make($plainToken);

        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->update([
                'token' => $bcryptHash,
                'created_at' => Carbon::now(),
            ]);

        // 1. Gửi trực tiếp raw bcrypt hash trong DB làm activation token -> bắt buộc bị từ chối 422
        $rawHashRes = $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $bcryptHash,
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
        ]);
        $rawHashRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'INVALID_TOKEN',
            ]);
        $this->assertEquals('PENDING_ACTIVATION', User::where('email', $email)->firstOrFail()->status);

        // 2. Token sai -> bị từ chối 422
        $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => 'wrong_token_value',
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
        ])->assertStatus(422)->assertJsonPath('error', 'INVALID_TOKEN');

        // 3. Token hết hạn (> 48h) -> bị từ chối 422
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->update(['created_at' => Carbon::now()->subHours(49)]);

        $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
        ])->assertStatus(422)->assertJsonPath('error', 'INVALID_TOKEN');

        // Khôi phục thời hạn token hợp lệ (chưa từng kích hoạt) -> token gốc kích hoạt thành công
        DB::table('password_reset_tokens')
            ->updateOrInsert(
                ['email' => $email],
                ['token' => $bcryptHash, 'created_at' => Carbon::now()]
            );

        $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'StrongPass@2026',
            'password_confirmation' => 'StrongPass@2026',
        ])->assertStatus(200)->assertJsonPath('success', true);

        $activatedUser = User::where('email', $email)->firstOrFail();
        $this->assertEquals('ACTIVE', $activatedUser->status);
        $this->assertNull(DB::table('password_reset_tokens')->where('email', $email)->first());

        // 4. Token đã sử dụng không thể tái sử dụng
        $this->postJson('/api/v1/account-provisioning/activate', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'AnotherPass@2026',
            'password_confirmation' => 'AnotherPass@2026',
        ])->assertStatus(400)->assertJsonPath('error', 'ALREADY_ACTIVE');
    }

    /**
     * 27 (BUG-M1-06): Public status endpoint không rò rỉ PII (username, full_name, email), chống dò tài khoản và giữ tương thích luồng kích hoạt.
     */
    public function test_27_public_status_endpoint_prevents_pii_leak_and_enumeration_while_supporting_valid_activation_flow(): void
    {
        Mail::fake();
        $rateLimitKey = 'account-provisioning-status:'.hash('sha256', '127.0.0.1');
        RateLimiter::clear($rateLimitKey);

        $pendingEmail = 'm106_pending_'.Str::random(6).'@example.com';
        $pendingProvisioned = $this->service->provisionAccount([
            'full_name' => 'Lê Minh Chờ Kích Hoạt',
            'email' => $pendingEmail,
            'phone_number' => '095'.random_int(1000000, 9999999),
            'roles' => ['RECEPTIONIST'],
        ], $this->admin);
        $pendingId = $pendingProvisioned['user']['id'];

        $pendingPlainToken = 'status_plain_tok_'.Str::random(32);
        $pendingBcryptHash = Hash::make($pendingPlainToken);
        DB::table('password_reset_tokens')
            ->where('email', $pendingEmail)
            ->update([
                'token' => $pendingBcryptHash,
                'created_at' => Carbon::now(),
            ]);

        $activeEmail = 'm106_active_'.Str::random(6).'@example.com';
        $activeProvisioned = $this->service->provisionAccount([
            'full_name' => 'Phạm Thị Đã Kích Hoạt',
            'email' => $activeEmail,
            'phone_number' => '094'.random_int(1000000, 9999999),
            'roles' => ['RECEPTIONIST'],
        ], $this->admin);
        $activeId = $activeProvisioned['user']['id'];
        $activePlainToken = 'active_plain_tok_'.Str::random(32);
        DB::table('password_reset_tokens')
            ->where('email', $activeEmail)
            ->update([
                'token' => Hash::make($activePlainToken),
                'created_at' => Carbon::now(),
            ]);
        $this->postJson('/api/v1/account-provisioning/activate', [
            'id' => $activeId,
            'email' => $activeEmail,
            'token' => $activePlainToken,
            'password' => 'ActivatedPass@2026',
            'password_confirmation' => 'ActivatedPass@2026',
        ])->assertStatus(200);

        // 1. Chỉ gửi email (không kèm proof-of-possession) hoặc sai id/token hoặc raw bcrypt hash -> phản hồi đồng nhất, không phân biệt tồn tại/trạng thái, không lộ PII
        $nonExistentRes = $this->getJson('/api/v1/account-provisioning/status?email=nonexistent_'.Str::random(8).'@example.com');
        $emailOnlyPendingRes = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}");
        $emailOnlyActiveRes = $this->getJson("/api/v1/account-provisioning/status?email={$activeEmail}");
        $wrongUuidActiveRes = $this->getJson("/api/v1/account-provisioning/status?email={$activeEmail}&id=".Str::uuid());
        $rawHashTokenRes = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}&token=".urlencode($pendingBcryptHash));
        $wrongTokenRes = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}&token=invalid_token");

        foreach ([
            $nonExistentRes,
            $emailOnlyPendingRes,
            $emailOnlyActiveRes,
            $wrongUuidActiveRes,
            $rawHashTokenRes,
            $wrongTokenRes,
        ] as $probeRes) {
            $probeRes->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'status' => 'PENDING_ACTIVATION',
                    'is_activated' => false,
                ])
                ->assertJsonMissingPath('username')
                ->assertJsonMissingPath('full_name')
                ->assertJsonMissingPath('email');
        }

        // Phản hồi cho email không tồn tại, pending và active khi thiếu proof-of-possession phải giống hệt nhau
        $this->assertSame($nonExistentRes->json(), $emailOnlyPendingRes->json());
        $this->assertSame($nonExistentRes->json(), $emailOnlyActiveRes->json());

        // 2. Có proof-of-possession hợp lệ (token hoặc cặp id + email từ link kích hoạt frontend) -> trả đúng trạng thái, vẫn không lộ PII
        $validPendingWithToken = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}&token={$pendingPlainToken}");
        $validPendingWithToken->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'PENDING_ACTIVATION',
                'is_activated' => false,
            ])
            ->assertJsonMissingPath('username')
            ->assertJsonMissingPath('full_name')
            ->assertJsonMissingPath('email');

        $validPendingWithId = $this->getJson("/api/v1/account-provisioning/status?id={$pendingId}&email={$pendingEmail}");
        $validPendingWithId->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'PENDING_ACTIVATION',
                'is_activated' => false,
            ])
            ->assertJsonMissingPath('username')
            ->assertJsonMissingPath('full_name')
            ->assertJsonMissingPath('email');

        $validActiveWithId = $this->getJson("/api/v1/account-provisioning/status?id={$activeId}&email={$activeEmail}");
        $validActiveWithId->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'ALREADY_ACTIVE',
                'is_activated' => true,
            ])
            ->assertJsonMissingPath('username')
            ->assertJsonMissingPath('full_name')
            ->assertJsonMissingPath('email');

        // 3. Rate limit bảo vệ endpoint status công khai (tối đa 20 lượt/phút theo IP)
        for ($i = 0; $i < 15; $i++) {
            $res = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}");
            if ($res->status() === 429) {
                break;
            }
        }
        $throttledRes = $this->getJson("/api/v1/account-provisioning/status?email={$pendingEmail}");
        $throttledRes->assertStatus(429)
            ->assertJsonPath('status', 'TOO_MANY_REQUESTS');

        RateLimiter::clear($rateLimitKey);
    }

    /**
     * 28 (BUG-M1-07): Gán role SECURITY_GUARD thành công, chặn triệt để role SECURITY và mã vai trò không tồn tại, không fallback ngầm sang RESIDENT_OWNER.
     */
    public function test_28_security_guard_assignment_and_rejection_of_invalid_security_role(): void
    {
        Mail::fake();

        $this->ensureRoleWithPermissions('SECURITY_GUARD', 'Nhân Viên An Ninh', ['SECURITY:VIEW']);
        $this->ensureRoleWithPermissions('RESIDENT_OWNER', 'Cư Dân Chủ Hộ', ['RESIDENT:VIEW']);

        $adminHeaders = ['Authorization' => 'Bearer '.$this->adminToken];

        // 1. Gán role SECURITY_GUARD hợp lệ qua HTTP POST: Thành công (201), nhận role SECURITY_GUARD, không bị fallback sang RESIDENT_OWNER
        $guardPayload = $this->validProvisioningPayload(['SECURITY_GUARD'], 'secguard');
        $guardRes = $this->withHeaders($adminHeaders)
            ->postJson('/api/v1/account-provisioning', $guardPayload);

        $guardRes->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $guardUser = User::where('email', $guardPayload['email'])->first();
        $this->assertNotNull($guardUser);
        $guardRoles = $this->service->getUserRoleCodesFromDatabase($guardUser);
        $this->assertContains('SECURITY_GUARD', $guardRoles);
        $this->assertNotContains('RESIDENT_OWNER', $guardRoles);

        // 2. Gửi request tạo tài khoản với roles = ['SECURITY'] (mã sai cũ từ Frontend): Bị chặn HTTP 422, không tạo user rác, không fallback RESIDENT_OWNER
        $invalidSecurityPayload = $this->validProvisioningPayload(['SECURITY'], 'secerr');
        $invalidSecurityRes = $this->withHeaders($adminHeaders)
            ->postJson('/api/v1/account-provisioning', $invalidSecurityPayload);

        $invalidSecurityRes->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');
        $this->assertStringContainsString('SECURITY', (string) $invalidSecurityRes->json('message'));
        $this->assertDatabaseMissing('users', ['email' => $invalidSecurityPayload['email']]);

        // 3. Gửi role code hoàn toàn không tồn tại: Bị chặn HTTP 422, không fallback ngầm
        $ghostPayload = $this->validProvisioningPayload(['COMPLETELY_NONEXISTENT_ROLE'], 'ghost');
        $ghostRes = $this->withHeaders($adminHeaders)
            ->postJson('/api/v1/account-provisioning', $ghostPayload);

        $ghostRes->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');
        $this->assertDatabaseMissing('users', ['email' => $ghostPayload['email']]);

        // 4. Cập nhật tài khoản với role = ['SECURITY'] qua HTTP PUT: Bị từ chối HTTP 422, vai trò cũ vẫn giữ nguyên không đổi
        $updateRes = $this->withHeaders($adminHeaders)
            ->putJson("/api/v1/account-provisioning/{$guardUser->id}", [
                'full_name' => 'Security Guard Updated',
                'roles' => ['SECURITY'],
            ]);

        $updateRes->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_ERROR');
        $guardRolesAfterFailedUpdate = $this->service->getUserRoleCodesFromDatabase($guardUser->fresh());
        $this->assertSame(['SECURITY_GUARD'], $guardRolesAfterFailedUpdate);

        // 5. Gọi trực tiếp AccountProvisioningService::assignRoles() với role không hợp lệ -> ném ngoại lệ AccountProvisioningValidationException, không fallback sang RESIDENT_OWNER
        $directExceptionThrown = false;
        try {
            $this->service->assignRoles($guardUser, ['SECURITY'], $this->admin);
        } catch (AccountProvisioningValidationException $e) {
            $directExceptionThrown = true;
            $this->assertStringContainsString('SECURITY', $e->getMessage());
        }
        $this->assertTrue($directExceptionThrown, 'assignRoles() must throw AccountProvisioningValidationException for invalid role code');
        $this->assertSame(['SECURITY_GUARD'], $this->service->getUserRoleCodesFromDatabase($guardUser->fresh()));

        // 6. Gọi trực tiếp AccountProvisioningService::assignRoles() với SECURITY_GUARD thành công
        $freshUser = User::create([
            'username' => 'sec_direct_'.Str::random(6),
            'email' => 'sec_direct_'.Str::random(6).'@test.local',
            'phone_number' => '093'.random_int(1000000, 9999999),
            'full_name' => 'Bảo Vệ Direct',
            'password_hash' => Hash::make('Pass@123'),
            'status' => 'PENDING_ACTIVATION',
        ]);
        $this->service->assignRoles($freshUser, ['SECURITY_GUARD'], $this->admin);
        $this->assertSame(['SECURITY_GUARD'], $this->service->getUserRoleCodesFromDatabase($freshUser));

        // 7. Tạo tài khoản khi roles bị bỏ trống (null/empty) vẫn mặc định sang RESIDENT_OWNER theo nghiệp vụ chuẩn
        $defaultResidentPayload = $this->validProvisioningPayload([], 'defres');
        unset($defaultResidentPayload['roles']);
        $defaultRes = $this->withHeaders($adminHeaders)
            ->postJson('/api/v1/account-provisioning', $defaultResidentPayload);

        $defaultRes->assertStatus(201);
        $defaultUser = User::where('email', $defaultResidentPayload['email'])->first();
        $this->assertNotNull($defaultUser);
        $this->assertSame(['RESIDENT_OWNER'], $this->service->getUserRoleCodesFromDatabase($defaultUser));
    }
}
