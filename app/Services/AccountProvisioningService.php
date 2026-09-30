<?php

namespace App\Services;

use App\Mail\AccountActivationMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountProvisioningDuplicateException extends \RuntimeException {}
class AccountProvisioningNotFoundException extends \RuntimeException {}
class AccountProvisioningInvalidTokenException extends \RuntimeException {}
class AccountProvisioningRateLimitException extends \RuntimeException {}
class AccountProvisioningAlreadyActiveException extends \RuntimeException {}

class AccountProvisioningService
{
    /**
     * Thời hạn hiệu lực của liên kết kích hoạt (giờ)
     */
    protected const ACTIVATION_EXPIRY_HOURS = 48;

    /**
     * Thời gian chờ giữa 2 lần gửi lại email kích hoạt (giây)
     */
    protected const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Sinh username ngẫu nhiên duy nhất cho người dùng
     * Format: NV_{random6} (ví dụ: NV_A82K91) theo đúng format dự án và tối đa 60 ký tự theo giới hạn DB
     */
    public function generateUniqueUsername(?string $prefix = 'NV'): string
    {
        $prefix = strtoupper(trim((string) $prefix)) ?: 'NV';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $charsLen = strlen($chars);

        $maxAttempts = 50;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $suffix = '';
            for ($c = 0; $c < 6; $c++) {
                $suffix .= $chars[random_int(0, $charsLen - 1)];
            }
            $candidate = "{$prefix}_{$suffix}";

            if (strlen($candidate) > 60) {
                $candidate = substr($candidate, 0, 60);
            }

            // Kiểm tra tính duy nhất (kể cả các bản ghi đã soft-deleted để tránh xung đột UNIQUE index SQL)
            $exists = User::withTrashed()->where('username', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        // Dự phòng với entropy cao hơn nếu trùng lặp quá nhiều
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = "{$prefix}_".strtoupper(bin2hex(random_bytes(4)));
            if (! User::withTrashed()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Không thể tạo username duy nhất sau nhiều lần thử.');
    }

    /**
     * Sinh mật khẩu ngẫu nhiên có độ phức tạp cao
     */
    public function generateSecurePassword(int $length = 16): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $numbers = '23456789';
        $symbols = '@#$%!&*+=-';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        $allChars = $uppercase.$lowercase.$numbers.$symbols;
        $remainingLength = max($length - 4, 8);
        for ($i = 0; $i < $remainingLength; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Cấp phát tài khoản tự động (Tạo user ngẫu nhiên + Gửi email kích hoạt)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function provisionAccount(array $data, ?User $actor = null): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phoneNumber = trim((string) ($data['phone_number'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $nationalId = isset($data['national_id_number']) && trim((string) $data['national_id_number']) !== ''
            ? trim((string) $data['national_id_number'])
            : null;
        $gender = $data['gender'] ?? 'OTHER';
        $dob = $data['date_of_birth'] ?? null;
        $roleCodes = $data['roles'] ?? ['RESIDENT_OWNER'];

        $dbResult = DB::transaction(function () use ($email, $phoneNumber, $fullName, $nationalId, $gender, $dob, $roleCodes, $actor) {
            // Kiểm tra trùng lặp email với lock tránh race condition
            $emailExists = User::withTrashed()->where('email', $email)->lockForUpdate()->exists();
            if ($emailExists) {
                throw new AccountProvisioningDuplicateException("Địa chỉ email '{$email}' đã tồn tại trong hệ thống.");
            }

            // Kiểm tra trùng lặp số điện thoại
            $phoneExists = User::withTrashed()->where('phone_number', $phoneNumber)->lockForUpdate()->exists();
            if ($phoneExists) {
                throw new AccountProvisioningDuplicateException("Số điện thoại '{$phoneNumber}' đã tồn tại trong hệ thống.");
            }

            // Kiểm tra trùng lặp số CCCD nếu có
            if ($nationalId !== null) {
                $nationalIdExists = User::withTrashed()->where('national_id_number', $nationalId)->lockForUpdate()->exists();
                if ($nationalIdExists) {
                    throw new AccountProvisioningDuplicateException("Số CCCD/Passport '{$nationalId}' đã tồn tại trong hệ thống.");
                }
            }

            // 1. Sinh username ngẫu nhiên duy nhất theo pattern NV_xxxxxx
            $username = $this->generateUniqueUsername('NV');

            // 2. Sinh mật khẩu ngẫu nhiên & hash
            $temporaryPlainPassword = $this->generateSecurePassword(16);
            $passwordHash = Hash::make($temporaryPlainPassword);

            // 3. Khởi tạo bản ghi người dùng với trạng thái PENDING_ACTIVATION
            $now = Carbon::now();
            $user = User::create([
                'username' => $username,
                'phone_number' => $phoneNumber,
                'email' => $email,
                'password_hash' => $passwordHash,
                'full_name' => $fullName,
                'gender' => $gender,
                'date_of_birth' => $dob,
                'national_id_number' => $nationalId,
                'status' => 'PENDING_ACTIVATION',
                'extra_preferences' => [
                    'provisioning' => [
                        'is_auto_provisioned' => true,
                        'provisioned_at' => $now->toIso8601String(),
                        'provisioned_by' => $actor?->id,
                        'activation_sent_at' => $now->toIso8601String(),
                        'resend_count' => 0,
                        'activated_at' => null,
                    ],
                ],
            ]);

            // 4. Gán vai trò cho người dùng
            $this->assignRoles($user, $roleCodes, $actor);

            // 5. Tạo token kích hoạt và lưu vào bảng password_reset_tokens hiện có của hệ thống
            $plainToken = Str::random(64);
            $this->storeActivationToken($email, $plainToken);

            // 6. Xây dựng link kích hoạt
            $activationUrl = $this->buildActivationUrl($user->id, $email, $plainToken);

            return [$user, $activationUrl];
        });

        [$user, $activationUrl] = $dbResult;

        // 7. Gửi email kích hoạt sau khi giao dịch CSDL đã commit thành công
        Mail::to($user->email)->send(new AccountActivationMail($user, $activationUrl, self::ACTIVATION_EXPIRY_HOURS));

        $user->load('roles:id,role_code,role_name');

        return [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'national_id_number' => $user->national_id_number,
                'status' => $user->status,
                'roles' => $user->roles,
                'created_at' => $user->created_at,
                'provisioning' => $user->extra_preferences['provisioning'] ?? null,
            ],
            'activation' => [
                'sent_to' => $user->email,
                'expires_in_hours' => self::ACTIVATION_EXPIRY_HOURS,
                'status' => 'PENDING_ACTIVATION',
            ],
        ];
    }

    /**
     * Gửi lại email kích hoạt cho tài khoản đang chờ kích hoạt
     *
     * @return array<string, mixed>
     */
    public function resendActivation(string $userId, ?User $actor = null): array
    {
        $user = User::findOrFail($userId);

        if ($user->status === 'ACTIVE') {
            throw new AccountProvisioningAlreadyActiveException('Tài khoản này đã được kích hoạt thành công trước đó.');
        }

        // Kiểm tra cooldown rate limit chống spam email
        $extra = $user->extra_preferences ?? [];
        $provisioning = $extra['provisioning'] ?? [];
        $lastSentAt = isset($provisioning['activation_sent_at'])
            ? Carbon::parse($provisioning['activation_sent_at'])
            : null;

        if ($lastSentAt) {
            $cooldownEnd = $lastSentAt->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
            if (Carbon::now()->lessThan($cooldownEnd)) {
                $remaining = max(1, Carbon::now()->diffInSeconds($cooldownEnd));
                throw new AccountProvisioningRateLimitException("Vui lòng đợi {$remaining} giây trước khi yêu cầu gửi lại email kích hoạt.");
            }
        }

        // Tạo token mới và cập nhật bảng password_reset_tokens
        $plainToken = Str::random(64);
        $this->storeActivationToken($user->email, $plainToken);

        // Xây dựng link kích hoạt mới
        $activationUrl = $this->buildActivationUrl($user->id, $user->email, $plainToken);

        // Gửi lại email kích hoạt
        try {
            Mail::to($user->email)->send(new AccountActivationMail($user, $activationUrl, self::ACTIVATION_EXPIRY_HOURS));
        } catch (\Throwable $e) {
            Log::error('Lỗi khi gửi lại email kích hoạt: '.$e->getMessage(), [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }

        // Cập nhật metadata
        $now = Carbon::now();
        $provisioning['activation_sent_at'] = $now->toIso8601String();
        $provisioning['resend_count'] = ($provisioning['resend_count'] ?? 0) + 1;
        $provisioning['last_resend_by'] = $actor?->id;
        $extra['provisioning'] = $provisioning;

        $user->extra_preferences = $extra;
        $user->save();

        return [
            'success' => true,
            'message' => 'Đã gửi lại email kích hoạt tài khoản thành công.',
            'sent_to' => $user->email,
            'resend_count' => $provisioning['resend_count'],
            'sent_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Kích hoạt tài khoản khi người dùng truy cập liên kết và thiết lập mật khẩu mới
     *
     * @return array<string, mixed>
     */
    public function activateAccount(string $email, string $token, string $newPassword): array
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new AccountProvisioningNotFoundException('Không tìm thấy tài khoản người dùng tương ứng.');
        }

        // Kiểm tra bản ghi token trong bảng password_reset_tokens
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $record) {
            throw new AccountProvisioningInvalidTokenException('Mã kích hoạt không hợp lệ hoặc đã được sử dụng.');
        }

        // Kiểm tra thời hạn hiệu lực của token (48 giờ)
        $tokenCreatedAt = Carbon::parse($record->created_at);
        if ($tokenCreatedAt->addHours(self::ACTIVATION_EXPIRY_HOURS)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw new AccountProvisioningInvalidTokenException('Liên kết kích hoạt đã hết hạn (quá 48 giờ). Vui lòng liên hệ Ban Quản Lý để nhận liên kết mới.');
        }

        // Xác thực token mã hóa
        $isTokenValid = Hash::check($token, $record->token) || hash_equals($record->token, $token);
        if (! $isTokenValid) {
            throw new AccountProvisioningInvalidTokenException('Mã kích hoạt không chính xác.');
        }

        // Cập nhật mật khẩu mới và đổi trạng thái sang ACTIVE
        $now = Carbon::now();
        $user->password_hash = Hash::make($newPassword);
        $user->status = 'ACTIVE';

        $extra = $user->extra_preferences ?? [];
        $provisioning = $extra['provisioning'] ?? [];
        $provisioning['activated_at'] = $now->toIso8601String();
        $extra['provisioning'] = $provisioning;
        $user->extra_preferences = $extra;

        $user->save();

        // Xóa token đã kích hoạt để không thể sử dụng lại
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return [
            'success' => true,
            'message' => 'Kích hoạt tài khoản thành công! Quý cư dân có thể đăng nhập ngay.',
            'username' => $user->username,
            'activated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Danh sách tài khoản đã cấp phát tự động phục vụ quản trị
     *
     * @param  array<string, mixed>  $filters
     */
    public function getProvisionedAccounts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::with('roles:id,role_code,role_name')
            ->orderBy('created_at', 'desc');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', strtoupper(trim((string) $filters['status'])));
        }

        if (isset($filters['auto_provisioned_only']) && $filters['auto_provisioned_only']) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra_preferences, '$.provisioning.is_auto_provisioned')) = 'true'");
        }

        return $query->paginate($perPage);
    }

    /**
     * Lưu trữ token kích hoạt an toàn vào bảng password_reset_tokens
     */
    protected function storeActivationToken(string $email, string $plainToken): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => Carbon::now(),
            ]
        );
    }

    /**
     * Xây dựng liên kết kích hoạt tài khoản
     */
    protected function buildActivationUrl(string $userId, string $email, string $plainToken): string
    {
        $appUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
        $encodedEmail = urlencode($email);
        $encodedToken = urlencode($plainToken);

        return "{$appUrl}/kich-hoat-tai-khoan?id={$userId}&email={$encodedEmail}&token={$encodedToken}";
    }

    /**
     * Gán vai trò cho người dùng
     *
     * @param  array<string>  $roleCodes
     */
    protected function assignRoles(User $user, array $roleCodes, ?User $actor = null): void
    {
        $roles = Role::whereIn('role_code', $roleCodes)->get();

        if ($roles->isEmpty()) {
            // Mặc định gán vai trò RESIDENT nếu không tìm thấy
            $defaultRole = Role::where('role_code', 'RESIDENT_OWNER')->first()
                ?? Role::where('role_code', 'RESIDENT')->first();
            if ($defaultRole) {
                $roles = collect([$defaultRole]);
            }
        }

        $isFirst = true;
        foreach ($roles as $role) {
            DB::table('user_roles')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'is_primary' => $isFirst ? 1 : 0,
                    'assigned_at' => Carbon::now(),
                    'assigned_by' => $actor?->id,
                ]
            );
            $isFirst = false;
        }
    }
}
