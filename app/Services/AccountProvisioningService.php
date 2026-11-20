<?php

namespace App\Services;

use App\Mail\AccountActivationMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
class AccountProvisioningForbiddenException extends \RuntimeException {}
class AccountProvisioningValidationException extends \RuntimeException {}

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
     * Các mã vai trò quản trị tối cao của hệ thống.
     *
     * @var array<int, string>
     */
    protected const SUPER_ADMIN_ROLE_CODES = [
        'SUPER_ADMIN',
        'SUPER_ADMI',
    ];

    /**
     * Các mã vai trò quản trị đặc quyền cao chỉ SUPER_ADMIN mới được phép gán.
     *
     * @var array<int, string>
     */
    protected const RESTRICTED_ADMIN_ROLE_CODES = [
        'SUPER_ADMIN',
        'SUPER_ADMI',
        'ADMIN',
    ];

    /**
     * Tiền tố vai trò phân quyền riêng gắn theo từng tài khoản.
     */
    protected const CUSTOM_USER_ROLE_PREFIX = 'CUSTOM_USER_';

    /**
     * Danh sách quyền đặc thù hệ thống không cho phép tài khoản dưới SUPER_ADMIN cấp gián tiếp qua vai trò.
     *
     * @var array<int, string>
     */
    protected const RESTRICTED_ROLE_PERMISSIONS = [
        'SPECIAL',
        'SYSTEM:SPECIAL',
        'SYSTEM:CREATE',
        'SYSTEM:UPDATE',
        'SYSTEM:DELETE',
        'ROLE:ASSIGN_PERMISSION',
        'ROLE:CREATE',
        'ROLE:UPDATE',
        'ROLE:DELETE',
        'PERMISSION:CREATE',
        'PERMISSION:UPDATE',
        'PERMISSION:DELETE',
    ];

    /**
     * Danh sách key payload giả mạo vai trò/quyền hạn không hợp lệ qua Account Provisioning.
     *
     * @var array<int, string>
     */
    protected const SPOOFED_PRIVILEGE_KEYS = [
        'role',
        'role_code',
        'role_codes',
        'role_id',
        'role_ids',
        'primary_role',
        'permissions',
        'permission_codes',
        'is_super_admin',
        'extra_preferences',
        'provisioned_by',
        'status',
    ];

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
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $this->assertNoSpoofedPrivilegeFields($data);
        $resolvedRoles = $this->resolveAndAuthorizeRequestedRoles($data['roles'] ?? null, $actor, null, true);

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phoneNumber = trim((string) ($data['phone_number'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $nationalId = isset($data['national_id_number']) && trim((string) $data['national_id_number']) !== ''
            ? trim((string) $data['national_id_number'])
            : null;
        $gender = $data['gender'] ?? 'OTHER';
        $dob = $data['date_of_birth'] ?? null;

        // 1. Sinh mật khẩu ngẫu nhiên & hash TRƯỚC KHI mở transaction để không block CSDL bằng CPU bcrypt
        $temporaryPlainPassword = $this->generateSecurePassword(16);
        $passwordHash = Hash::make($temporaryPlainPassword);
        $plainToken = Str::random(64);

        $dbResult = DB::transaction(function () use ($email, $phoneNumber, $fullName, $nationalId, $gender, $dob, $resolvedRoles, $actor, $passwordHash, $plainToken) {
            // Kiểm tra trùng lặp email bằng index seek
            $emailExists = User::withTrashed()->where('email', $email)->exists();
            if ($emailExists) {
                throw new AccountProvisioningDuplicateException("Địa chỉ email '{$email}' đã tồn tại trong hệ thống.");
            }

            // Kiểm tra trùng lặp số điện thoại
            $phoneExists = User::withTrashed()->where('phone_number', $phoneNumber)->exists();
            if ($phoneExists) {
                throw new AccountProvisioningDuplicateException("Số điện thoại '{$phoneNumber}' đã tồn tại trong hệ thống.");
            }

            // Kiểm tra trùng lặp số CCCD nếu có
            if ($nationalId !== null) {
                $nationalIdExists = User::withTrashed()->where('national_id_number', $nationalId)->exists();
                if ($nationalIdExists) {
                    throw new AccountProvisioningDuplicateException("Số CCCD/Passport '{$nationalId}' đã tồn tại trong hệ thống.");
                }
            }

            // 2. Sinh username ngẫu nhiên duy nhất theo pattern NV_xxxxxx
            $username = $this->generateUniqueUsername('NV');

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

            // 4. Gán vai trò đã xác thực quyền hạn cho người dùng
            if ($resolvedRoles !== null) {
                $this->syncResolvedRoles($user, $resolvedRoles, $actor, false);
            }

            // 5. Tạo token kích hoạt và lưu vào bảng password_reset_tokens
            $this->storeActivationToken($email, $plainToken);

            // 6. Xây dựng link kích hoạt
            $activationUrl = $this->buildActivationUrl($user->id, $email, $plainToken);

            return [$user, $activationUrl];
        });

        [$user, $activationUrl] = $dbResult;

        // 7. Gửi email kích hoạt sau khi giao dịch CSDL đã commit thành công
        Mail::to($user->email)->send(new AccountActivationMail($user, $activationUrl, self::ACTIVATION_EXPIRY_HOURS));

        $user->load('roles:id,role_code,role_name');

        QuocTinRealtimeService::emit('account-provisioning', 'user', 'PROVISIONED', $user->id, [
            'status' => $user->status,
            'email' => $user->email,
            'full_name' => $user->full_name,
        ], $actor?->id, false);

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

        if ($this->userIsSuperAdmin($user) && ! $this->isActiveSuperAdmin($actor)) {
            throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền thao tác với tài khoản SUPER_ADMIN.');
        }

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
    public function activateAccount(string $email, string $token, string $newPassword, ?string $userId = null): array
    {
        $email = strtolower(trim($email));
        $query = User::where('email', $email);
        if ($userId) {
            $query->where('id', $userId);
        }
        $user = $query->first();

        if (! $user) {
            throw new AccountProvisioningNotFoundException('Không tìm thấy tài khoản người dùng tương ứng.');
        }

        // Đảm bảo liên kết chỉ kích hoạt 1 lần duy nhất, nếu đã kích hoạt rồi thì thông báo đăng nhập
        if ($user->status === 'ACTIVE') {
            throw new AccountProvisioningAlreadyActiveException('Đã kích hoạt tài khoản bạn vui lòng đăng nhập.');
        }

        // Kiểm tra bản ghi token trong bảng password_reset_tokens (nếu đã kích hoạt, token đã bị xóa)
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

        // Xác thực token mã hóa (chỉ chấp nhận plain token khớp bcrypt hash, không chấp nhận raw hash)
        $isTokenValid = is_string($record->token) && $record->token !== '' && Hash::check($token, $record->token);
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

        // Xóa token đã kích hoạt để không thể sử dụng lại lần thứ hai
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        QuocTinRealtimeService::emit('account-provisioning', 'user', 'ACTIVATED', $user->id, [
            'status' => 'ACTIVE',
        ]);

        return [
            'success' => true,
            'message' => 'Kích hoạt tài khoản thành công! Quý cư dân có thể đăng nhập ngay.',
            'username' => $user->username,
            'activated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Kiểm tra token kích hoạt còn hiệu lực và khớp với bản băm lưu trong CSDL (không chấp nhận raw hash).
     */
    public function hasValidActivationToken(string $email, string $token): bool
    {
        $email = strtolower(trim($email));
        $token = trim($token);

        if ($email === '' || $token === '') {
            return false;
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $record || ! is_string($record->token) || $record->token === '') {
            return false;
        }

        $tokenCreatedAt = Carbon::parse($record->created_at);
        if ($tokenCreatedAt->addHours(self::ACTIVATION_EXPIRY_HOURS)->isPast()) {
            return false;
        }

        return Hash::check($token, $record->token);
    }

    /**
     * Danh sách tài khoản đã cấp phát tự động phục vụ quản trị
     *
     * @param  array<string, mixed>  $filters
     */
    public function getProvisionedAccounts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->select([
                'id',
                'username',
                'full_name',
                'email',
                'phone_number',
                'national_id_number',
                'gender',
                'status',
                'extra_preferences',
                'created_at',
            ])
            ->with('roles:id,role_code,role_name')
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
     * Kiểm tra tài khoản người dùng đang ở trạng thái ACTIVE và chưa bị xóa mềm.
     */
    public function isUserRecordActive(?User $user): bool
    {
        if (! $user || empty($user->id)) {
            return false;
        }

        return DB::table('users')
            ->where('id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Lấy danh sách mã vai trò hiện tại từ CSDL cho một tài khoản bất kỳ (bỏ qua role đã xóa mềm).
     *
     * @return array<int, string>
     */
    public function getUserRoleCodesFromDatabase(User $user): array
    {
        if (empty($user->id)) {
            return [];
        }

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->pluck('roles.role_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Kiểm tra người thực hiện có phải SUPER_ADMIN đang hoạt động hợp lệ trong CSDL hay không.
     */
    public function isActiveSuperAdmin(?User $actor): bool
    {
        if (! $this->isUserRecordActive($actor)) {
            return false;
        }

        /** @var User $actor */
        $roleCodes = $this->getUserRoleCodesFromDatabase($actor);

        foreach (self::SUPER_ADMIN_ROLE_CODES as $superCode) {
            if (in_array($superCode, $roleCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kiểm tra một tài khoản đích có mang vai trò SUPER_ADMIN hay không.
     */
    public function userIsSuperAdmin(User $user): bool
    {
        $roleCodes = $this->getUserRoleCodesFromDatabase($user);

        foreach (self::SUPER_ADMIN_ROLE_CODES as $superCode) {
            if (in_array($superCode, $roleCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Đếm số lượng tài khoản SUPER_ADMIN đang ACTIVE trong hệ thống.
     */
    protected function countActiveSuperAdmins(): int
    {
        return (int) DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('users', 'user_roles.user_id', '=', 'users.id')
            ->whereIn('roles.role_code', self::SUPER_ADMIN_ROLE_CODES)
            ->where('users.status', 'ACTIVE')
            ->whereNull('users.deleted_at')
            ->whereNull('roles.deleted_at')
            ->distinct('users.id')
            ->count('users.id');
    }

    /**
     * Kiểm tra người dùng có quyền thực thi endpoint Account Provisioning hay không.
     *
     * @param  string|array<int, string>  $requiredPermissions
     */
    public function canAccessProvisioningAction(?User $user, array|string $requiredPermissions): bool
    {
        if (! $this->isUserRecordActive($user)) {
            return false;
        }

        if ($this->isActiveSuperAdmin($user)) {
            return true;
        }

        /** @var User $user */
        $roleCodes = $this->getUserRoleCodesFromDatabase($user);
        $perms = is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions];

        $hasCustomRole = collect($roleCodes)->contains(
            fn (string $code): bool => str_starts_with($code, self::CUSTOM_USER_ROLE_PREFIX)
        );

        // Nếu chưa phân quyền riêng CUSTOM_USER_%, giữ nguyên luồng hợp lệ của ADMIN / BUILDING_MANAGER (trừ DELETE đòi hỏi USER:DELETE)
        if (! $hasCustomRole) {
            $normalizedPerms = array_map(fn ($p): string => strtoupper(trim((string) $p)), $perms);
            $isDeleteAction = in_array('USER:DELETE', $normalizedPerms, true) && count($normalizedPerms) === 1;

            if (! $isDeleteAction && (in_array('BUILDING_MANAGER', $roleCodes, true) || in_array('ADMIN', $roleCodes, true))) {
                return true;
            }
        }

        $user->clearPermissionCache();
        foreach ($perms as $perm) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission((string) $perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kiểm tra tài khoản thực hiện có quyền phân vai trò quản lý vận hành (không bao gồm SUPER_ADMIN).
     */
    protected function actorCanAssignOperationalRoles(?User $actor): bool
    {
        if ($this->isActiveSuperAdmin($actor)) {
            return true;
        }

        if (! $this->isUserRecordActive($actor)) {
            return false;
        }

        /** @var User $actor */
        $roleCodes = $this->getUserRoleCodesFromDatabase($actor);
        $hasCustomRole = collect($roleCodes)->contains(
            fn (string $code): bool => str_starts_with($code, self::CUSTOM_USER_ROLE_PREFIX)
        );

        if (! $hasCustomRole && (in_array('BUILDING_MANAGER', $roleCodes, true) || in_array('ADMIN', $roleCodes, true))) {
            return true;
        }

        $actor->clearPermissionCache();

        return $actor->hasPermission('USER:ASSIGN_ROLE') || $actor->hasPermission('USER:UPDATE');
    }

    /**
     * Từ chối các trường giả mạo vai trò/quyền hạn trong request payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertNoSpoofedPrivilegeFields(array $payload, bool $rejectRolesKey = false): void
    {
        $keysToBlock = self::SPOOFED_PRIVILEGE_KEYS;
        if ($rejectRolesKey) {
            $keysToBlock[] = 'roles';
        }

        foreach ($keysToBlock as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            $hasNonEmptyValue = $value !== null && $value !== '' && $value !== [] && $value !== false;
            if ($hasNonEmptyValue) {
                throw new AccountProvisioningForbiddenException(
                    "Không được phép giả mạo hoặc truyền trường đặc quyền '{$key}' trong yêu cầu này."
                );
            }
        }
    }

    /**
     * Trích xuất danh sách token vai trò thô từ input (hỗ trợ cả array hoặc string phân cách dấu phẩy).
     *
     * @return array<int, string>
     */
    protected function extractRawRoleTokens(mixed $rawRoles): array
    {
        if (is_string($rawRoles)) {
            $parts = preg_split('/[,;|]+/', $rawRoles) ?: [];

            return array_values(array_filter(array_map(fn (string $part): string => trim($part), $parts), fn (string $v): bool => $v !== ''));
        }

        if (! is_array($rawRoles)) {
            return [];
        }

        $tokens = [];
        foreach ($rawRoles as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $tokens[] = $trimmed;
                }
            }
        }

        return $tokens;
    }

    /**
     * Kiểm tra tập hợp Role model có chứa bất kỳ quyền hạn đặc biệt nào trong danh sách chỉ định hay không.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<int, string>  $permissionCodesToCheck
     */
    protected function rolesContainAnyPermission(Collection $roles, array $permissionCodesToCheck): bool
    {
        if ($roles->isEmpty()) {
            return false;
        }

        $roleIds = $roles->pluck('id')->all();

        return DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->whereIn('role_permissions.role_id', $roleIds)
            ->whereIn('permissions.permission_code', $permissionCodesToCheck)
            ->exists();
    }

    /**
     * Kiểm tra quyền hạn đối với payload trước khi chạy validation/DB write để tránh rò rỉ hoặc leo thang đặc quyền.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertAuthorizedForPayload(?User $actor, array $payload, ?User $targetUser = null): void
    {
        $this->assertNoSpoofedPrivilegeFields($payload);

        if (! array_key_exists('roles', $payload) || $payload['roles'] === null || $payload['roles'] === []) {
            return;
        }

        $rawTokens = $this->extractRawRoleTokens($payload['roles']);
        if (empty($rawTokens)) {
            return;
        }

        $this->resolveAndAuthorizeRequestedRoles($rawTokens, $actor, $targetUser, false, true);
    }

    /**
     * Xác thực quyền gán vai trò và phân giải tập Role models hợp lệ từ CSDL.
     *
     * @return Collection<int, Role>|null
     */
    protected function resolveAndAuthorizeRequestedRoles(
        mixed $rawRoles,
        ?User $actor = null,
        ?User $targetUser = null,
        bool $allowDefaultWhenEmpty = false,
        bool $precheckOnly = false
    ): ?Collection {
        if ($rawRoles === null || $rawRoles === []) {
            if (! $allowDefaultWhenEmpty) {
                return null;
            }

            $defaultRole = Role::where('role_code', 'RESIDENT_OWNER')->first()
                ?? Role::where('role_code', 'RESIDENT')->first();

            return $defaultRole ? collect([$defaultRole]) : collect();
        }

        if (! is_array($rawRoles)) {
            throw new AccountProvisioningValidationException('Danh sách vai trò (roles) phải là một mảng hợp lệ.');
        }

        $normalizedCodes = [];
        $candidateUuids = [];

        foreach ($rawRoles as $item) {
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                throw new AccountProvisioningValidationException('Mã vai trò trong danh sách roles không hợp lệ.');
            }

            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }

            $upper = strtoupper($trimmed);
            if (str_starts_with($upper, self::CUSTOM_USER_ROLE_PREFIX)) {
                throw new AccountProvisioningForbiddenException('Không được phép gán vai trò phân quyền riêng (CUSTOM_USER) thông qua Account Provisioning.');
            }

            $normalizedCodes[] = $upper;
            if (Str::isUuid($trimmed)) {
                $candidateUuids[] = $trimmed;
            }
        }

        $normalizedCodes = array_values(array_unique($normalizedCodes));
        if (empty($normalizedCodes)) {
            if (! $allowDefaultWhenEmpty) {
                return null;
            }

            throw new AccountProvisioningValidationException('Danh sách vai trò không chứa mã vai trò hợp lệ.');
        }

        $isActorSuperAdmin = $this->isActiveSuperAdmin($actor);
        $isSelfUpdate = $targetUser !== null && $actor !== null && (string) $actor->id === (string) $targetUser->id;
        $currentTargetCodes = $targetUser !== null ? $this->getUserRoleCodesFromDatabase($targetUser) : [];

        $sortedRequested = $normalizedCodes;
        sort($sortedRequested);
        $sortedCurrent = $currentTargetCodes;
        sort($sortedCurrent);
        $isUnchangedSelfRoleEdit = $isSelfUpdate && ($sortedRequested === $sortedCurrent);

        // Chặn tuyệt đối tài khoản dưới SUPER_ADMIN tự nâng hoặc thay đổi vai trò của chính mình
        if ($isSelfUpdate && ! $isActorSuperAdmin && ! $isUnchangedSelfRoleEdit) {
            throw new AccountProvisioningForbiddenException('Bạn không có quyền tự thay đổi hoặc nâng vai trò của chính mình.');
        }

        // Kiểm tra vai trò theo cả role_code và UUID (ngăn chặn kỹ thuật gửi UUID của vai trò SUPER_ADMIN để vượt qua kiểm tra chuỗi)
        $rolesMatchedByUuid = ! empty($candidateUuids)
            ? Role::whereIn('id', $candidateUuids)->get()
            : collect();

        foreach ($rolesMatchedByUuid as $uuidRole) {
            $uuidRoleCode = strtoupper((string) $uuidRole->role_code);
            if (str_starts_with($uuidRoleCode, self::CUSTOM_USER_ROLE_PREFIX)) {
                throw new AccountProvisioningForbiddenException('Không được phép gán vai trò phân quyền riêng (CUSTOM_USER) thông qua Account Provisioning.');
            }
            if (in_array($uuidRoleCode, self::RESTRICTED_ADMIN_ROLE_CODES, true) && ! $isActorSuperAdmin) {
                throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền cấp hoặc gán vai trò quản trị tối cao.');
            }
        }

        if ($rolesMatchedByUuid->isNotEmpty() && ! $isActorSuperAdmin && $this->rolesContainAnyPermission($rolesMatchedByUuid, self::RESTRICTED_ROLE_PERMISSIONS)) {
            throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền gán vai trò mang đặc quyền hệ thống.');
        }

        $resolvedRoles = Role::whereIn('role_code', $normalizedCodes)->get();

        // Chặn nâng hoặc gán SUPER_ADMIN / SUPER_ADMI / ADMIN cho tài khoản không phải SUPER_ADMIN
        if (! $isActorSuperAdmin) {
            foreach ($normalizedCodes as $code) {
                if (in_array($code, self::SUPER_ADMIN_ROLE_CODES, true)) {
                    throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền cấp hoặc gán vai trò SUPER_ADMIN.');
                }

                if (in_array($code, self::RESTRICTED_ADMIN_ROLE_CODES, true) && ! ($isUnchangedSelfRoleEdit && in_array($code, $currentTargetCodes, true))) {
                    throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền cấp hoặc gán vai trò quản trị tối cao.');
                }
            }

            if ($this->rolesContainAnyPermission($resolvedRoles, self::RESTRICTED_ROLE_PERMISSIONS)) {
                throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền gán vai trò chứa đặc quyền hệ thống.');
            }

            if (! $this->actorCanAssignOperationalRoles($actor)) {
                $isManagementRole = collect($normalizedCodes)->contains(
                    fn (string $code): bool => in_array($code, ['BUILDING_MANAGER', 'MANAGER'], true)
                );

                if ($isManagementRole || $this->rolesContainAnyPermission($resolvedRoles, ['USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:DELETE', 'USER:ASSIGN_ROLE'])) {
                    throw new AccountProvisioningForbiddenException('Bạn không có quyền gán vai trò quản lý hoặc vai trò có quyền quản trị tài khoản.');
                }
            }
        }

        if ($precheckOnly) {
            return $resolvedRoles;
        }

        // Khi caller truyền rõ danh sách roles, tất cả mã vai trò phải tồn tại hợp lệ trong CSDL (không fallback âm thầm)
        $resolvedUpperCodes = $resolvedRoles
            ->pluck('role_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->toArray();

        $missingCodes = array_diff($normalizedCodes, $resolvedUpperCodes);
        if (! empty($missingCodes)) {
            throw new AccountProvisioningValidationException(
                'Vai trò không tồn tại hoặc không hợp lệ: '.implode(', ', $missingCodes)
            );
        }

        if ($isUnchangedSelfRoleEdit) {
            return null;
        }

        return $resolvedRoles;
    }

    /**
     * Ghi nhận danh sách Role đã được kiểm tra quyền hạn vào bảng user_roles.
     *
     * @param  Collection<int, Role>  $roles
     */
    protected function syncResolvedRoles(User $user, Collection $roles, ?User $actor = null, bool $replaceExisting = false): void
    {
        if ($replaceExisting) {
            DB::table('user_roles')->where('user_id', $user->id)->delete();
        }

        $batch = [];
        $now = Carbon::now();
        $isFirst = true;
        foreach ($roles as $role) {
            $batch[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => $isFirst ? 1 : 0,
                'assigned_at' => $now,
                'assigned_by' => $actor?->id,
            ];
            $isFirst = false;
        }

        if (! empty($batch)) {
            DB::table('user_roles')->insertOrIgnore($batch);
        }

        $user->clearPermissionCache();
        $user->unsetRelation('roles');
    }

    /**
     * Gán vai trò cho người dùng (không fallback sang RESIDENT_OWNER nếu role không hợp lệ)
     *
     * @param  array<string>  $roleCodes
     */
    public function assignRoles(User $user, array $roleCodes, ?User $actor = null): void
    {
        $roles = $this->resolveAndAuthorizeRequestedRoles($roleCodes, $actor, $user, false);

        if ($roles !== null && $roles->isNotEmpty()) {
            $this->syncResolvedRoles($user, $roles, $actor, false);
        }
    }

    /**
     * Cập nhật thông tin tài khoản đã cấp phát
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(string $userId, array $data, ?User $actor = null): User
    {
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $this->assertNoSpoofedPrivilegeFields($data);

        $user = User::findOrFail($userId);

        $targetIsSuperAdmin = $this->userIsSuperAdmin($user);
        $actorIsSuperAdmin = $this->isActiveSuperAdmin($actor);

        if ($targetIsSuperAdmin && ! $actorIsSuperAdmin) {
            throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền chỉnh sửa tài khoản SUPER_ADMIN.');
        }

        $rolesToAssign = null;
        if (array_key_exists('roles', $data)) {
            $rolesToAssign = $this->resolveAndAuthorizeRequestedRoles($data['roles'], $actor, $user, false);

            // Chống hạ quyền SUPER_ADMIN cuối cùng đang hoạt động trong hệ thống
            if ($targetIsSuperAdmin && $rolesToAssign !== null) {
                $newRoleCodes = $rolesToAssign
                    ->pluck('role_code')
                    ->map(fn ($c): string => strtoupper(trim((string) $c)))
                    ->toArray();

                $stillHasSuperAdmin = ! empty(array_intersect($newRoleCodes, self::SUPER_ADMIN_ROLE_CODES));
                if (! $stillHasSuperAdmin && $this->isUserRecordActive($user) && $this->countActiveSuperAdmins() <= 1) {
                    throw new AccountProvisioningValidationException('Không thể hạ quyền Quản trị viên cấp cao cuối cùng trong hệ thống.');
                }
            }
        }

        if (! empty($data['full_name'])) {
            $user->full_name = trim((string) $data['full_name']);
        }
        if (! empty($data['phone_number'])) {
            $phoneNumber = trim((string) $data['phone_number']);
            $exists = User::where('phone_number', $phoneNumber)->where('id', '!=', $user->id)->exists();
            if ($exists) {
                throw new AccountProvisioningDuplicateException("Số điện thoại '{$phoneNumber}' đã được sử dụng bởi tài khoản khác.");
            }
            $user->phone_number = $phoneNumber;
        }
        if (array_key_exists('national_id_number', $data)) {
            $nationalId = trim((string) $data['national_id_number']) ?: null;
            if ($nationalId) {
                $exists = User::where('national_id_number', $nationalId)->where('id', '!=', $user->id)->exists();
                if ($exists) {
                    throw new AccountProvisioningDuplicateException("Số CCCD/Passport '{$nationalId}' đã được sử dụng bởi tài khoản khác.");
                }
            }
            $user->national_id_number = $nationalId;
        }
        if (! empty($data['gender'])) {
            $user->gender = $data['gender'];
        }

        DB::transaction(function () use ($user, $rolesToAssign, $actor): void {
            $user->save();

            if ($rolesToAssign !== null) {
                $this->syncResolvedRoles($user, $rolesToAssign, $actor, true);
            }
        });

        $user->clearPermissionCache();
        $user->unsetRelation('roles');
        $user->load('roles:id,role_code,role_name');

        QuocTinRealtimeService::emit('account-provisioning', 'user', 'UPDATED', $user->id, [
            'status' => $user->status,
        ]);

        return $user;
    }

    /**
     * Xóa tài khoản người dùng
     */
    public function deleteAccount(string $userId, ?User $actor = null): bool
    {
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $user = User::findOrFail($userId);

        if ($actor && (string) $actor->id === (string) $user->id) {
            throw new AccountProvisioningValidationException('Bạn không thể tự xóa tài khoản của chính mình.');
        }

        if ($this->userIsSuperAdmin($user)) {
            if (! $this->isActiveSuperAdmin($actor)) {
                throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền xóa tài khoản SUPER_ADMIN.');
            }

            if ($this->isUserRecordActive($user) && $this->countActiveSuperAdmins() <= 1) {
                throw new AccountProvisioningValidationException('Không thể xóa Quản trị viên cấp cao cuối cùng trong hệ thống.');
            }
        }

        $deleted = DB::transaction(function () use ($user): bool {
            // Xóa token kích hoạt liên quan
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // Thu hồi các phiên đăng nhập đang hoạt động
            DB::table('user_sessions')->where('user_id', $user->id)->update(['is_revoked' => 1]);

            return (bool) $user->delete();
        });

        if ($deleted) {
            QuocTinRealtimeService::emit('account-provisioning', 'user', 'DELETED', $userId);
        }

        return $deleted;
    }

    /**
     * Khóa hoặc mở khóa tài khoản
     */
    public function toggleLockAccount(string $userId, ?User $actor = null): User
    {
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $user = User::findOrFail($userId);

        if ($actor && (string) $actor->id === (string) $user->id) {
            throw new AccountProvisioningValidationException('Bạn không thể tự khóa tài khoản của chính mình.');
        }

        if ($this->userIsSuperAdmin($user)) {
            if (! $this->isActiveSuperAdmin($actor)) {
                throw new AccountProvisioningForbiddenException('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền khóa hoặc mở khóa tài khoản SUPER_ADMIN.');
            }

            if ($user->status !== 'LOCKED' && $this->isUserRecordActive($user) && $this->countActiveSuperAdmins() <= 1) {
                throw new AccountProvisioningValidationException('Không thể khóa Quản trị viên cấp cao cuối cùng đang hoạt động.');
            }
        }

        $extra = $user->extra_preferences ?? [];
        $provisioning = $extra['provisioning'] ?? [];

        if ($user->status === 'LOCKED') {
            $prevStatus = $provisioning['prev_status'] ?? 'ACTIVE';
            $user->status = $prevStatus;
            unset($provisioning['prev_status']);
        } else {
            $provisioning['prev_status'] = $user->status;
            $user->status = 'LOCKED';
        }

        $extra['provisioning'] = $provisioning;
        $user->extra_preferences = $extra;
        $user->save();
        $user->load('roles:id,role_code,role_name');

        QuocTinRealtimeService::emit('account-provisioning', 'user', 'STATUS_CHANGED', $user->id, [
            'status' => $user->status,
        ]);

        return $user;
    }

    /**
     * Gửi lại email kích hoạt hàng loạt cho các tài khoản đang chờ
     *
     * @return array<string, mixed>
     */
    public function batchResendActivation(?User $actor = null): array
    {
        $pendingUsers = User::where('status', 'PENDING_ACTIVATION')->get();
        $actorIsSuperAdmin = $this->isActiveSuperAdmin($actor);
        $sentCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($pendingUsers as $user) {
            if (! $actorIsSuperAdmin && $this->userIsSuperAdmin($user)) {
                $skippedCount++;

                continue;
            }

            try {
                $this->resendActivation($user->id, $actor);
                $sentCount++;
            } catch (AccountProvisioningRateLimitException $e) {
                $skippedCount++;
            } catch (AccountProvisioningForbiddenException $e) {
                $skippedCount++;
            } catch (\Throwable $e) {
                $errors[] = "Tài khoản {$user->email}: ".$e->getMessage();
            }
        }

        return [
            'sent_count' => $sentCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
            'total_pending' => $pendingUsers->count(),
        ];
    }

    /**
     * Nhập hàng loạt tài khoản từ danh sách mảng (Excel / CSV)
     *
     * @param  array<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function importAccounts(array $records, ?User $actor = null): array
    {
        // Kiểm tra quyền và giả mạo payload trên toàn bộ danh sách TRƯỚC KHI tạo bất kỳ tài khoản nào
        foreach ($records as $row) {
            if (is_array($row)) {
                $this->assertAuthorizedForPayload($actor, $row, null);
            }
        }

        $results = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($records as $index => $row) {
            $rowNum = $index + 1;
            try {
                if (! is_array($row) || empty($row['full_name']) || empty($row['email']) || empty($row['phone_number'])) {
                    throw new \InvalidArgumentException("Dòng {$rowNum}: Thiếu Họ tên, Email hoặc Số điện thoại.");
                }

                $parsedRoles = ['RECEPTIONIST'];
                if (array_key_exists('roles', $row) && $row['roles'] !== null && $row['roles'] !== '' && $row['roles'] !== []) {
                    $parsedRoles = $this->extractRawRoleTokens($row['roles']);
                    if (empty($parsedRoles)) {
                        throw new AccountProvisioningValidationException("Dòng {$rowNum}: Danh sách vai trò không hợp lệ.");
                    }
                }

                $res = $this->provisionAccount([
                    'full_name' => trim((string) $row['full_name']),
                    'email' => strtolower(trim((string) $row['email'])),
                    'phone_number' => trim((string) $row['phone_number']),
                    'national_id_number' => ! empty($row['national_id_number']) ? trim((string) $row['national_id_number']) : null,
                    'gender' => ! empty($row['gender']) ? $row['gender'] : 'MALE',
                    'roles' => $parsedRoles,
                ], $actor);

                $results[] = $res['user'];
                $successCount++;
            } catch (AccountProvisioningForbiddenException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Dòng {$rowNum} (".($row['email'] ?? 'N/A').'): '.$e->getMessage();
            }
        }

        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'total' => count($records),
            'results' => $results,
            'errors' => $errors,
        ];
    }
}
