<?php

namespace Tests\Feature;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_forged_tokens_are_rejected_on_both_me_aliases_and_legacy_routes(): void
    {
        $user = User::factory()->create();
        Route::get('/qa/legacy-auth', fn () => response()->json(['ok' => true]))->middleware('auth.bearer');
        foreach (['smart_token_admin_demo', 'smart_token_manager_demo', 'smart_token_'.$user->id.'_fake'] as $token) {
            Cache::put('auth_bearer_user_id:'.hash('sha256', $token), $user->id, 120);
            foreach (['/api/v1/auth/me', '/api/auth/me', '/qa/legacy-auth'] as $uri) {
                $this->withToken($token)->getJson($uri)->assertUnauthorized();
            }
        }
    }

    public function test_real_session_is_required_after_revocation_expiry_and_logout(): void
    {
        $user = User::factory()->create(['password_hash' => Hash::make('ValidPassword123!')]);
        $login = $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'ValidPassword123!'])->assertOk();
        $token = $login->json('access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->withToken(hash('sha256', $token))->getJson('/api/v1/auth/me')->assertUnauthorized();
        Cache::put('auth_bearer_user_id:'.hash('sha256', $token), $user->id, 120);
        DB::table('user_sessions')->where('user_id', $user->id)->update(['is_revoked' => 1]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $user->id)->update(['is_revoked' => 0, 'expires_at' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $user->id)->update(['expires_at' => now()->addHour()]);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_demo_password_is_not_a_bypass_even_in_testing_and_locked_users_are_denied(): void
    {
        $user = User::where('username', 'admin')->first() ?? User::factory()->create(['username' => 'admin']);
        $user->update(['password_hash' => Hash::make('RealPassword123!'), 'status' => 'ACTIVE']);
        $sessionCount = DB::table('user_sessions')->where('user_id', $user->id)->count();
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => '123567'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'not-a-user', 'password' => '123567'])->assertUnauthorized();
        $user->update(['status' => 'INACTIVE']);
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'RealPassword123!'])->assertForbidden();
        $this->assertSame($sessionCount, DB::table('user_sessions')->where('user_id', $user->id)->count());
    }

    public function test_registration_otp_still_sends_email_and_verifies_without_exposing_code(): void
    {
        Mail::fake();
        $email = 'otp-'.Str::uuid().'@example.test';
        $this->postJson('/api/v1/auth/send-register-otp', ['email' => $email])->assertOk()->assertJsonPath('debug_otp', null);
        $otp = null;
        Mail::assertSent(OtpVerificationMail::class, function ($mail) use ($email, &$otp): bool {
            $otp = $mail->otp;

            return $mail->hasTo($email) && strlen($otp) === 6;
        });
        $this->postJson('/api/v1/auth/verify-register-otp', ['email' => $email, 'otp' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/verify-register-otp', ['email' => $email, 'otp' => $otp])
            ->assertOk()->assertJsonStructure(['verification_token']);
    }

    public function test_password_reset_otp_still_sends_verifies_and_changes_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('debug_otp', null);
        $otp = null;
        Mail::assertSent(OtpVerificationMail::class, function ($mail) use ($user, &$otp): bool {
            $otp = $mail->otp;

            return $mail->hasTo($user->email);
        });
        $token = $this->postJson('/api/v1/auth/verify-otp', ['email' => $user->email, 'otp' => $otp])->assertOk()->json('reset_token');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'reset_token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'NewPassword123!'])->assertOk();
    }
}
