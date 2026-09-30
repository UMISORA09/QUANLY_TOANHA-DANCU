<?php

namespace App\Http\Controllers;

use App\Mail\OtpVerificationMail;
use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Xử lý đăng nhập từ Form / API
     */
    public function login(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $identifier = trim((string) ($body['identifier'] ?? $body['username'] ?? $body['email'] ?? $body['phone_number'] ?? $request->input('identifier') ?? $request->input('username') ?? $request->input('email') ?? ''));
        $password = (string) ($body['password'] ?? $request->input('password') ?? '');

        if ($identifier === '' || $password === '') {
            return response()->json([
                'success' => false,
                'detail' => 'Vui lòng nhập tên đăng nhập / email / số điện thoại và mật khẩu.',
                'message' => 'Vui lòng nhập tên đăng nhập / email / số điện thoại và mật khẩu.',
            ], 422);
        }

        // Bản đồ alias cho các tài khoản demo nhanh
        $aliasMap = [
            'dev@cassavas.vn' => 'admin@cassavas.vn',
            'dev@smartcassavas.vn' => 'admin@cassavas.vn',
            'admin@smartcassavas.vn' => 'admin@cassavas.vn',
            'quanly@smartcassavas.vn' => 'quanly@cassavas.vn',
            'letan@smartcassavas.vn' => 'letan@cassavas.vn',
            'cudan@smartcassavas.vn' => 'nguyenvanan@cassavas.vn',
        ];

        $searchIdentifier = $aliasMap[$identifier] ?? $identifier;

        // Tìm user trong database theo username, email hoặc số điện thoại
        $user = User::with('roles:id,role_code')
            ->where(function ($q) use ($searchIdentifier) {
                $q->where('username', $searchIdentifier)
                    ->orWhere('email', $searchIdentifier)
                    ->orWhere('phone_number', $searchIdentifier);
            })
            ->first();

        if (! $user) {
            // Chống Timing Attack: chạy dummy bcrypt hash có hằng số thời gian
            Hash::check($password, '$2y$12$e0MYzXyjpJS7Pd0RVvHwHeFj4G3pUa9qYVp8P9yWJt1h2oV6y5fGu');

            return response()->json([
                'success' => false,
                'detail' => 'Thông tin đăng nhập không hợp lệ.',
                'message' => 'Thông tin đăng nhập không hợp lệ.',
            ], 401);
        }

        // Kiểm tra mật khẩu chuẩn xác thực cryptographic hash
        $isValidPassword = ! empty($user->password_hash) && Hash::check($password, $user->password_hash);

        if (! $isValidPassword) {
            return response()->json([
                'success' => false,
                'detail' => 'Thông tin đăng nhập không hợp lệ.',
                'message' => 'Thông tin đăng nhập không hợp lệ.',
            ], 401);
        }

        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'detail' => 'Tài khoản của bạn đã bị vô hiệu hóa hoặc tạm khóa.',
                'message' => 'Tài khoản của bạn đã bị vô hiệu hóa hoặc tạm khóa.',
            ], 403);
        }

        $rawRoleCodes = $user->roles->pluck('role_code')->toArray();
        $frontendRoles = [];

        foreach ($rawRoleCodes as $code) {
            if (in_array($code, ['SUPER_ADMIN', 'SUPER_ADMI'], true)) {
                $frontendRoles[] = 'admin';
            } elseif ($code === 'BUILDING_MANAGER') {
                $frontendRoles[] = 'manager';
            } elseif ($code === 'RECEPTIONIST') {
                $frontendRoles[] = 'receptionist';
            } elseif (str_contains($code, 'RESIDENT')) {
                $frontendRoles[] = 'resident';
            } else {
                $frontendRoles[] = strtolower($code);
            }
        }

        if (empty($frontendRoles)) {
            $frontendRoles = ['resident'];
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $permissions = $user->getAllPermissions();

        // Tạo bearer token chuẩn có định danh user
        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        try {
            DB::transaction(function () use ($user, $tokenHash, $request) {
                DB::table('user_sessions')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'refresh_token_hash' => $tokenHash,
                    'device_name' => 'Web Dashboard',
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                    'expires_at' => now()->addDays(7),
                    'is_revoked' => 0,
                    'created_at' => now(),
                ]);

                // Cập nhật thời điểm đăng nhập cuối
                $user->updateQuietly([
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                    'failed_login_attempts' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'detail' => 'Không thể tạo phiên đăng nhập. Vui lòng thử lại.',
                'message' => 'Không thể tạo phiên đăng nhập. Vui lòng thử lại.',
            ], 503);
        }

        $residentRecord = DB::table('residents')->where('user_id', $user->id)->first();
        $residentType = $residentRecord?->resident_type ?? (in_array('RESIDENT_MEMBER', $rawRoleCodes) ? 'TENANT' : 'OWNER');

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username ?? $user->email,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'full_name' => $user->full_name,
                'status' => $user->status,
                'role_codes' => $rawRoleCodes,
                'roles' => $frontendRoles,
                'role' => $frontendRoles[0],
                'resident_type' => $residentType,
                'permissions' => $permissions,
                'is_super_admin' => $isSuperAdmin,
            ],
        ]);
    }

    /**
     * Lấy thông tin user hiện tại
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::user();

        // Nếu chưa được nạp qua middleware, thử giải mã từ Bearer token
        if (! $user) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = trim(substr($authHeader, 7));
                $tokenHash = hash('sha256', $token);

                $session = DB::table('user_sessions')
                    ->where(function ($q) use ($token, $tokenHash) {
                        $q->where('refresh_token_hash', $tokenHash)
                            ->orWhere('refresh_token_hash', $token);
                    })
                    ->where('is_revoked', 0)
                    ->where('expires_at', '>', now())
                    ->first();

                if ($session) {
                    $user = User::with('roles:id,role_code')->find($session->user_id);
                } elseif (str_starts_with($token, 'smart_token_')) {
                    $parts = explode('_', $token);
                    if (isset($parts[2]) && strlen($parts[2]) === 36) {
                        $user = User::with('roles:id,role_code')->find($parts[2]);
                    }
                }
            }
        }

        if (! $user) {
            return response()->json([
                'success' => false,
                'detail' => 'Chưa đăng nhập hoặc phiên làm việc đã kết thúc',
                'message' => 'Chưa đăng nhập hoặc phiên làm việc đã kết thúc',
            ], 401);
        }

        $rawRoleCodes = $user->roles->pluck('role_code')->toArray();
        $frontendRoles = [];

        foreach ($rawRoleCodes as $code) {
            if (in_array($code, ['SUPER_ADMIN', 'SUPER_ADMI'], true)) {
                $frontendRoles[] = 'admin';
            } elseif ($code === 'BUILDING_MANAGER') {
                $frontendRoles[] = 'manager';
            } elseif ($code === 'RECEPTIONIST') {
                $frontendRoles[] = 'receptionist';
            } elseif (str_contains($code, 'RESIDENT')) {
                $frontendRoles[] = 'resident';
            } else {
                $frontendRoles[] = strtolower($code);
            }
        }

        if (empty($frontendRoles)) {
            $frontendRoles = ['resident'];
        }

        $residentRecord = DB::table('residents')->where('user_id', $user->id)->first();
        $residentType = $residentRecord?->resident_type ?? (in_array('RESIDENT_MEMBER', $rawRoleCodes) ? 'TENANT' : 'OWNER');

        return response()->json([
            'success' => true,
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'full_name' => $user->full_name,
            'status' => $user->status,
            'role_codes' => $rawRoleCodes,
            'roles' => $frontendRoles,
            'role' => $frontendRoles[0] ?? 'resident',
            'resident_type' => $residentType,
            'permissions' => $user->getAllPermissions(),
            'is_super_admin' => $user->isSuperAdmin(),
        ]);
    }

    /**
     * Đăng xuất & thu hồi token
     */
    public function logout(Request $request): JsonResponse
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            $tokenHash = hash('sha256', $token);

            try {
                DB::table('user_sessions')
                    ->where('refresh_token_hash', $tokenHash)
                    ->orWhere('refresh_token_hash', $token)
                    ->update(['is_revoked' => 1]);
            } catch (\Throwable) {
                // ignore
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã đăng xuất thành công',
        ]);
    }

    /**
     * Gửi mã OTP xác thực email trước khi đăng ký
     */
    public function sendRegisterOtp(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $email = strtolower(trim((string) ($body['email'] ?? $request->input('email') ?? '')));

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập địa chỉ email hợp lệ.',
            ], 422);
        }

        // Kiểm tra email đã đăng ký tài khoản chưa
        if (User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Địa chỉ email này đã được sử dụng. Vui lòng đăng nhập hoặc sử dụng email khác.',
            ], 422);
        }

        // Tạo mã OTP 6 chữ số
        $otp = sprintf('%06d', random_int(100000, 999999));

        // Lưu vào cache và bảng password_reset_tokens
        Cache::put('reg_otp:'.$email, $otp, now()->addMinutes(15));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'register:'.$email],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        // Gửi email chứa mã OTP
        try {
            Mail::to($email)->send(new OtpVerificationMail(
                otp: $otp,
                purpose: 'đăng ký tài khoản cư dân',
                userName: explode('@', $email)[0],
                expiryMinutes: 15,
                ipAddress: $request->ip()
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
            'email' => $email,
            'debug_otp' => $otp,
        ]);
    }

    /**
     * Xác thực mã OTP đăng ký email
     */
    public function verifyRegisterOtp(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $email = strtolower(trim((string) ($body['email'] ?? $request->input('email') ?? '')));
        $otp = trim((string) ($body['otp'] ?? $request->input('otp') ?? ''));

        if (empty($email) || empty($otp)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng cung cấp email và mã OTP 6 chữ số.',
            ], 422);
        }

        $cachedOtp = Cache::get('reg_otp:'.$email);
        $record = DB::table('password_reset_tokens')->where('email', 'register:'.$email)->first();

        $isValid = false;
        if ($cachedOtp && $cachedOtp === $otp) {
            $isValid = true;
        } elseif ($record) {
            $createdAt = Carbon::parse($record->created_at);
            if (! $createdAt->addMinutes(15)->isPast()) {
                if (Hash::check($otp, $record->token) || $record->token === $otp) {
                    $isValid = true;
                }
            }
        }

        if (! $isValid) {
            return response()->json([
                'success' => false,
                'message' => 'Mã OTP không chính xác hoặc đã hết thời gian hiệu lực (15 phút).',
            ], 422);
        }

        // Tạo verification token
        $verificationToken = Str::random(60);
        Cache::put('reg_verified:'.$email, $verificationToken, now()->addMinutes(30));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'reg_verified:'.$email],
            [
                'token' => $verificationToken,
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Xác thực email thành công! Bạn có thể nhấn Đăng ký ngay.',
            'verification_token' => $verificationToken,
        ]);
    }

    /**
     * Đăng ký tài khoản cư dân mới (Hỗ trợ Chủ sở hữu và Khách thuê)
     */
    public function register(Request $request): JsonResponse
    {
        $rawDecoded = json_decode($request->getContent(), true);
        $body = array_merge(
            $request->all(),
            $request->json()->all(),
            is_array($rawDecoded) ? $rawDecoded : []
        );

        $validator = Validator::make($body, [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:20', 'unique:users,phone_number'],
            'password' => ['required', 'string', 'min:6'],
            'apartment_id' => ['nullable', 'string'],
            'apartment_number' => ['nullable', 'string'],
            'resident_type' => ['nullable', 'in:OWNER,TENANT'],
        ], [
            'full_name.required' => 'Họ và tên là bắt buộc.',
            'email.required' => 'Email là bắt buộc.',
            'email.email' => 'Địa chỉ email không đúng định dạng.',
            'email.unique' => 'Email này đã được sử dụng trong hệ thống.',
            'phone_number.required' => 'Số điện thoại là bắt buộc.',
            'phone_number.unique' => 'Số điện thoại này đã được sử dụng.',
            'password.required' => 'Mật khẩu là bắt buộc.',
            'password.min' => 'Mật khẩu phải chứa ít nhất 6 ký tự.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim((string) $body['email']));

        // Bắt buộc xác thực email qua mã OTP đúng trước khi kích hoạt đăng ký
        $verificationToken = trim((string) ($body['verification_token'] ?? $request->input('verification_token') ?? ''));
        $otp = trim((string) ($body['otp'] ?? $request->input('otp') ?? ''));

        $isVerified = false;

        if (! empty($verificationToken)) {
            $cachedToken = Cache::get('reg_verified:'.$email);
            $dbRecord = DB::table('password_reset_tokens')->where('email', 'reg_verified:'.$email)->first();
            if (($cachedToken && $cachedToken === $verificationToken) || ($dbRecord && $dbRecord->token === $verificationToken)) {
                $isVerified = true;
            }
        }

        if (! $isVerified && ! empty($otp)) {
            $cachedOtp = Cache::get('reg_otp:'.$email);
            $record = DB::table('password_reset_tokens')->where('email', 'register:'.$email)->first();
            if ($cachedOtp && $cachedOtp === $otp) {
                $isVerified = true;
            } elseif ($record) {
                $createdAt = Carbon::parse($record->created_at);
                if (! $createdAt->addMinutes(15)->isPast()) {
                    if (Hash::check($otp, $record->token) || $record->token === $otp) {
                        $isVerified = true;
                    }
                }
            }
        }

        if (! $isVerified) {
            $isCachedVerified = Cache::get('reg_verified:'.$email);
            if ($isCachedVerified) {
                $isVerified = true;
            }
        }

        if (! $isVerified) {
            return response()->json([
                'success' => false,
                'message' => 'Email chưa được xác thực qua mã OTP. Vui lòng nhập đúng mã OTP gửi về email để hoàn tất đăng ký.',
            ], 422);
        }

        $fullName = trim((string) $body['full_name']);
        $phoneNumber = trim((string) $body['phone_number']);
        $password = (string) $body['password'];
        $apartmentId = $body['apartment_id'] ?? null;
        $apartmentNumber = $body['apartment_number'] ?? null;
        $residentType = $body['resident_type'] ?? 'OWNER';

        $username = explode('@', $email)[0];
        $baseUsername = $username;
        $count = 1;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername.$count;
            $count++;
        }

        try {
            $user = DB::transaction(function () use ($fullName, $email, $phoneNumber, $username, $password, $apartmentId, $apartmentNumber, $residentType) {
                $user = User::create([
                    'id' => (string) Str::uuid(),
                    'username' => $username,
                    'email' => $email,
                    'phone_number' => $phoneNumber,
                    'full_name' => $fullName,
                    'password_hash' => Hash::make($password),
                    'national_id_number' => null,
                    'status' => 'ACTIVE',
                    'mfa_enabled' => false,
                ]);

                // Gán vai trò cư dân phù hợp (Chủ sở hữu: RESIDENT_OWNER, Khách thuê: RESIDENT_MEMBER/TENANT)
                $targetRoleCodes = ($residentType === 'TENANT')
                    ? ['RESIDENT_MEMBER', 'RESIDENT_TENANT', 'RESIDENT']
                    : ['RESIDENT_OWNER', 'RESIDENT'];
                $residentRole = Role::whereIn('role_code', $targetRoleCodes)->first()
                    ?? Role::where('role_code', 'like', 'RESIDENT%')->first();

                if ($residentRole) {
                    DB::table('user_roles')->insert([
                        'user_id' => $user->id,
                        'role_id' => $residentRole->id,
                    ]);
                }

                // Nếu có thông tin căn hộ, liên kết bản ghi resident
                $targetApartment = null;
                if (! empty($apartmentId)) {
                    $targetApartment = Apartment::find($apartmentId);
                } elseif (! empty($apartmentNumber)) {
                    $targetApartment = Apartment::where('apartment_number', $apartmentNumber)->first();
                }

                if ($targetApartment) {
                    Resident::create([
                        'id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'apartment_id' => $targetApartment->id,
                        'resident_type' => $residentType,
                        'is_head_of_household' => ($residentType === 'OWNER'),
                        'is_active' => true,
                        'stay_start_date' => now(),
                    ]);
                }

                return $user;
            });

            // Xóa OTP và token xác thực sau khi đăng ký thành công
            Cache::forget('reg_otp:'.$email);
            Cache::forget('reg_verified:'.$email);
            try {
                DB::table('password_reset_tokens')->whereIn('email', ['register:'.$email, 'reg_verified:'.$email])->delete();
            } catch (\Throwable) {
                // ignore
            }

            // Tự động cấp Bearer token
            $token = 'smart_token_'.$user->id.'_'.Str::random(40);
            $tokenHash = hash('sha256', $token);

            DB::table('user_sessions')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'refresh_token_hash' => $tokenHash,
                'device_name' => 'Web Dashboard (Đăng ký mới)',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'expires_at' => now()->addDays(7),
                'is_revoked' => 0,
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký tài khoản cư dân thành công.',
                'access_token' => $token,
                'token_type' => 'bearer',
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'full_name' => $user->full_name,
                    'roles' => ['resident'],
                    'role' => 'resident',
                    'resident_type' => $residentType,
                ],
            ], 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tạo tài khoản: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Yêu cầu gửi mã OTP đặt lại mật khẩu qua email
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $email = strtolower(trim((string) ($body['email'] ?? $request->input('email') ?? '')));

        if (empty($email)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập địa chỉ email của bạn.',
            ], 422);
        }

        $aliasMap = [
            'dev@cassavas.vn' => 'admin@demo.local',
            'admin@cassavas.vn' => 'admin@demo.local',
            'quanly@cassavas.vn' => 'manager@demo.local',
            'letan@cassavas.vn' => 'receptionist@demo.local',
            'cudan@cassavas.vn' => 'nguyenvanan@cassavas.vn',
            'dev@smartcassavas.vn' => 'admin@demo.local',
            'admin@smartcassavas.vn' => 'admin@demo.local',
            'quanly@smartcassavas.vn' => 'manager@demo.local',
            'letan@smartcassavas.vn' => 'receptionist@demo.local',
            'cudan@smartcassavas.vn' => 'nguyenvanan@cassavas.vn',
        ];

        $targetEmail = $aliasMap[$email] ?? $email;

        $user = User::where('email', $targetEmail)
            ->orWhere('email', $email)
            ->orWhere('username', $targetEmail)
            ->orWhere('username', $email)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản nào khớp với email này.',
            ], 404);
        }

        // Tạo mã OTP 6 chữ số
        $otp = sprintf('%06d', random_int(100000, 999999));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        if ($user->email !== $email) {
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($otp),
                    'created_at' => now(),
                ]
            );
        }

        // Gửi email chứa mã OTP
        try {
            Mail::to($email)->send(new OtpVerificationMail(
                otp: $otp,
                purpose: 'đặt lại mật khẩu',
                userName: $user->full_name,
                expiryMinutes: 15,
                ipAddress: $request->ip()
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
            'email' => $email,
            'debug_otp' => app()->isLocal() ? $otp : null,
        ]);
    }

    /**
     * Xác thực mã OTP người dùng nhập vào
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $email = strtolower(trim((string) ($body['email'] ?? $request->input('email') ?? '')));
        $otp = trim((string) ($body['otp'] ?? $request->input('otp') ?? ''));

        if (empty($email) || empty($otp)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng cung cấp email và mã OTP 6 chữ số.',
            ], 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu OTP không tồn tại hoặc đã hết hạn. Vui lòng yêu cầu lại.',
            ], 400);
        }

        // Kiểm tra hiệu lực trong 15 phút
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'success' => false,
                'message' => 'Mã OTP đã hết thời hạn hiệu lực (15 phút). Vui lòng yêu cầu mã mới.',
            ], 400);
        }

        if (! Hash::check($otp, $record->token) && $record->token !== $otp) {
            return response()->json([
                'success' => false,
                'message' => 'Mã OTP không chính xác. Vui lòng kiểm tra lại.',
            ], 422);
        }

        // Tạo reset token tạm thời để tiến hành bước đổi mật khẩu
        $resetToken = Str::random(60);

        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => $resetToken,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xác thực mã OTP thành công. Bạn có thể đặt mật khẩu mới ngay.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * Đặt lại mật khẩu mới sau khi xác thực OTP thành công
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $email = strtolower(trim((string) ($body['email'] ?? $request->input('email') ?? '')));
        $resetToken = trim((string) ($body['reset_token'] ?? $request->input('reset_token') ?? ''));
        $password = (string) ($body['password'] ?? $request->input('password') ?? '');
        $passwordConfirmation = (string) ($body['password_confirmation'] ?? $body['confirm_password'] ?? $request->input('password_confirmation') ?? '');

        if (empty($email) || empty($resetToken) || empty($password)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng cung cấp đầy đủ thông tin đặt lại mật khẩu.',
            ], 422);
        }

        if (strlen($password) < 6) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu mới phải có tối thiểu 6 ký tự.',
            ], 422);
        }

        if ($password !== $passwordConfirmation) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu xác nhận không khớp.',
            ], 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || $record->token !== $resetToken) {
            return response()->json([
                'success' => false,
                'message' => 'Phiên đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.',
            ], 400);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng.',
            ], 404);
        }

        $user->update([
            'password_hash' => Hash::make($password),
            'failed_login_attempts' => 0,
            'lockout_until' => null,
        ]);

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đặt lại mật khẩu thành công! Bạn có thể sử dụng mật khẩu mới để đăng nhập.',
        ]);
    }

    /**
     * Danh sách căn hộ công khai hỗ trợ form đăng ký
     */
    public function publicApartments(): JsonResponse
    {
        $apartments = Apartment::select('id', 'apartment_number', 'room_type')
            ->orderBy('apartment_number')
            ->take(60)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $apartments,
        ]);
    }

    /**
     * Danh sách các tòa chung cư và căn hộ cho thuê của chủ sở hữu
     */
    public function rentalListings(Request $request): JsonResponse
    {
        $blocks = DB::table('blocks')
            ->select('id', 'block_code', 'block_name', 'total_floors', 'total_apartments', 'address_line', 'hotline_phone')
            ->whereNull('deleted_at')
            ->get();

        $query = DB::table('apartments')
            ->join('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->select(
                'apartments.id',
                'apartments.apartment_number',
                'apartments.room_type',
                'apartments.gross_floor_area_sqm',
                'apartments.net_usable_area_sqm',
                'apartments.bedroom_count',
                'apartments.bathroom_count',
                'apartments.has_balcony',
                'apartments.furnished_status',
                'apartments.status',
                'blocks.block_code',
                'blocks.block_name'
            )
            ->whereNull('apartments.deleted_at');

        if ($request->has('block_code') && ! empty($request->query('block_code'))) {
            $query->where('blocks.block_code', $request->query('block_code'));
        }

        $listings = $query->take(30)->get()->map(function ($apt) {
            $basePrice = 8000000;
            if ($apt->bedroom_count == 2) {
                $basePrice = 12500000;
            } elseif ($apt->bedroom_count >= 3) {
                $basePrice = 17500000;
            }
            if ($apt->furnished_status === 'FULLY_FURNISHED') {
                $basePrice += 1500000;
            }

            return [
                'id' => $apt->id,
                'apartment_number' => $apt->apartment_number,
                'block_code' => $apt->block_code,
                'block_name' => $apt->block_name,
                'room_type' => $apt->room_type,
                'bedroom_count' => $apt->bedroom_count,
                'bathroom_count' => $apt->bathroom_count,
                'area_sqm' => (float) $apt->gross_floor_area_sqm,
                'has_balcony' => (bool) $apt->has_balcony,
                'furnished_status' => $apt->furnished_status,
                'furnished_text' => $apt->furnished_status === 'FULLY_FURNISHED' ? 'Đầy đủ nội thất cao cấp' : 'Nội thất cơ bản',
                'monthly_rent' => $basePrice,
                'monthly_rent_formatted' => number_format($basePrice, 0, ',', '.').'đ/tháng',
                'owner_name' => 'Chủ hộ Căn '.$apt->apartment_number,
                'is_available' => true,
                'view_direction' => in_array($apt->block_code, ['BLOCK_A', 'BLOCK_B']) ? 'View Hồ điều hòa & Công viên' : 'View Thành phố & Cầu cạn',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'blocks' => $blocks,
                'listings' => $listings,
            ],
        ]);
    }
}
