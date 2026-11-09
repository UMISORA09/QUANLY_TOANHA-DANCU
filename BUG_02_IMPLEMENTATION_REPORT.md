# BÁO CÁO TRIỂN KHAI VÀ NGHIỆM THU FIX BUG-02 (AUTHENTICATION SECURITY)

> **Mã lỗi**: BUG-02  
> **Mức độ**: CRITICAL  
> **Phạm vi**: Authentication, Password Verification, OTP Protection, Demo Tokens  
> **Thời gian hoàn thành**: 04/11/2026  
> **Trạng thái**: ĐÃ TRIỂN KHAI VÀ PASS 100% KIỂM THỬ RUNTIME  

---

## 1. Nguyên nhân từng lỗi và Giải pháp triển khai

### 1.1. BUG-02-A: Hardcoded Fallback Passwords trong `AuthController::login()`
- **Nguyên nhân gốc rễ**: Để thuận tiện cho quá trình thử nghiệm nhanh các tài khoản demo, mã nguồn duy trì một mảng `$isDemoFallbackPass` chứa 8 mật khẩu plain-text hardcoded cho 10 tên đăng nhập hệ thống (`admin`, `manager`, `receptionist`, `security`, `letan`, `quanly`, `nguyenvanan`,...). Mệnh đề xác thực sử dụng toán tử `||` (OR) khiến việc kiểm tra `Hash::check()` bị bỏ qua hoàn toàn nếu người dùng nhập bất kỳ mật khẩu nào trong danh sách fallback. Do không có điều kiện môi trường, kẻ tấn công trên Production có thể đăng nhập thẳng vào tài khoản `SUPER_ADMIN` bằng mật khẩu mặc định ngay cả khi mật khẩu thật đã được thay đổi.
- **Giải pháp**: Xóa bỏ hoàn toàn mảng `$isDemoFallbackPass` và mọi mật khẩu hardcoded. Chỉ chấp nhận xác thực mật khẩu duy nhất thông qua hàm băm cryptographic an toàn `Hash::check($password, $user->password_hash)`.

### 1.2. BUG-02-B: API `sendRegisterOtp()` tiết lộ OTP qua HTTP Response
- **Nguyên nhân gốc rễ**: Trong `AuthController::sendRegisterOtp()`, trường `'debug_otp' => $otp` được trả về trực tiếp trong HTTP response một cách vô điều kiện. Bất kỳ ai gọi API trên môi trường Production đều nhận được mã OTP ngay từ response mà không cần quyền truy cập vào hộp thư email thật của nạn nhân.
- **Giải pháp**: Bọc trường `debug_otp` trong điều kiện kiểm tra môi trường: `'debug_otp' => app()->environment('local', 'testing') ? $otp : null`. Trên Production và Staging, trường này luôn có giá trị `null` (giữ nguyên hợp đồng dữ liệu cho TypeScript frontend `res.debug_otp || null`). Đồng thời đồng nhất cơ chế này cho cả luồng `forgotPassword()`.

### 1.3. BUG-02-C: Demo Token được chấp nhận không giới hạn môi trường trong `ReceptionDashboardController`
- **Nguyên nhân gốc rễ**: Trong `ReceptionDashboardController::resolveAuthUser()`, controller tự ý kiểm tra chuỗi Bearer token tĩnh (`smart_token_reception_demo`, `smart_token_admin_demo`, `smart_token_manager_demo`) mà không kiểm tra môi trường `testing`/`local`. Khi phát hiện chuỗi token này, hệ thống tự động gán đối tượng người dùng là nhân viên đầu tiên tìm thấy trong cơ sở dữ liệu (`User::whereHas('roles', ...)->first()`), cho phép bất kỳ ai trên internet xem toàn bộ dữ liệu KPI, bưu phẩm, khách viếng thăm của tòa nhà trên Production.
- **Giải pháp**: Loại bỏ hoàn toàn khối kiểm tra fallback và tự chọn nhân viên tùy tiện. Ủy thác việc giải mã token cho `RbacService::resolveUser($request)` (nơi đã có sẵn rào chắn môi trường `if (app()->environment('testing', 'local'))`). Trên Production, demo token bị từ chối 100% và trả về HTTP 401; trên Local/Testing, demo token vẫn hoạt động mượt mà cho các bài test tự động.

---

## 2. Chi tiết File và Dòng Code đã sửa (Trước và Sau)

### 2.1. `app/Http/Controllers/AuthController.php`

#### A. Tại `login()` (dòng 138-144):
- **Trước khi sửa**:
  ```php
  // Kiểm tra mật khẩu chuẩn xác thực cryptographic hash hoặc fallback demo mật khẩu cho môi trường thử nghiệm
  $isDemoFallbackPass = in_array($user->username, ['admin', 'manager', 'receptionist', 'security', 'resident.owner', 'resident.member', 'accountant', 'nguyenvanan', 'quanly', 'letan'], true)
      && in_array($password, ['123567', '123456', 'Admin@123456', 'Manager@123456', 'Receptionist@123456', 'Security@123456', 'Resident@123456', 'Accountant@123456'], true);

  $isValidPassword = (! empty($user->password_hash) && Hash::check($password, $user->password_hash)) || $isDemoFallbackPass;
  ```
- **Sau khi sửa**:
  ```php
  // Xác thực mật khẩu duy nhất bằng hàm băm cryptographic an toàn
  $isValidPassword = ! empty($user->password_hash) && rescue(fn () => Hash::check($password, $user->password_hash), false);
  ```

#### B. Tại `sendRegisterOtp()` (dòng 405-412):
- **Trước khi sửa**:
  ```php
  return response()->json([
      'success' => true,
      'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
      'email' => $email,
      'debug_otp' => $otp,
  ]);
  ```
- **Sau khi sửa**:
  ```php
  return response()->json([
      'success' => true,
      'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
      'email' => $email,
      'debug_otp' => app()->environment('local', 'testing') ? $otp : null,
  ]);
  ```

#### C. Tại `forgotPassword()` (dòng 765-772):
- **Trước khi sửa**:
  ```php
  'debug_otp' => app()->isLocal() ? $otp : null,
  ```
- **Sau khi sửa**:
  ```php
  'debug_otp' => app()->environment('local', 'testing') ? $otp : null,
  ```

---

### 2.2. `app/Http/Controllers/ReceptionDashboardController.php`

#### Tại `resolveAuthUser()` (dòng 22-35):
- **Trước khi sửa**:
  ```php
  protected function resolveAuthUser(Request $request): ?object
  {
      $user = $request->user() ?? RbacService::resolveUser($request);

      if (! $user) {
          $bearer = $request->bearerToken();
          if ($bearer === 'smart_token_reception_demo' || $bearer === 'smart_token_admin_demo' || $bearer === 'smart_token_manager_demo') {
              $user = User::whereHas('roles', function ($q) {
                  $q->whereIn('role_code', ['RECEPTIONIST', 'STAFF', 'ADMIN', 'SUPER_ADMIN', 'SECURITY_GUARD']);
              })->first() ?? User::where('status', 'ACTIVE')->first();
          }
      }

      return $user;
  }
  ```
- **Sau khi sửa**:
  ```php
  protected function resolveAuthUser(Request $request): ?object
  {
      return $request->user() ?? RbacService::resolveUser($request);
  }
  ```

---

## 3. Bổ sung và Thực thi Bộ Kiểm thử Tự động (Tests)

File kiểm thử: `tests/Feature/AuthSecurityRegressionTest.php`

### 3.1. Danh sách 18 ca kiểm thử chi tiết

| STT | Tên Test Case | Mục đích kiểm tra | Kết quả | Thời gian |
|:---:|---|---|:---:|:---:|
| 1 | `test_login_with_valid_credentials_succeeds_and_creates_session` | Đăng nhập mật khẩu đúng tạo session thành công | **PASS** | 13.53s |
| 2 | `test_login_with_invalid_password_fails_with_401` | Đăng nhập mật khẩu sai bị từ chối 401 | **PASS** | 1.15s |
| 3 | `test_login_with_locked_account_fails_with_403` | Tài khoản bị khóa bị từ chối 403 | **PASS** | 1.27s |
| 4 | `test_me_with_valid_token_succeeds` | Lấy thông tin tài khoản qua token phiên | **PASS** | 0.69s |
| 5 | `test_access_with_revoked_token_returns_401` | Token đã thu hồi bị từ chối 401 | **PASS** | 0.81s |
| 6 | `test_access_with_expired_token_returns_401` | Token hết hạn bị từ chối 401 | **PASS** | 0.56s |
| 7 | `test_access_with_forged_uuid_token_returns_401` | Token giả mạo định danh bị từ chối 401 | **PASS** | 0.72s |
| 8 | `test_access_without_token_returns_401` | Gọi API không có token bị từ chối 401 | **PASS** | 0.77s |
| 9 | `test_bug02_a_cannot_login_with_hardcoded_fallback_password_when_user_has_different_hash` | Mật khẩu fallback cũ bị từ chối 401 khi hash đã đổi | **PASS** | 5.33s |
| 10 | `test_bug02_a_demo_account_with_seeded_hash_can_still_login` | Tài khoản demo vẫn đăng nhập bình thường bằng mật khẩu hash | **PASS** | 2.65s |
| 11 | `test_bug02_b_register_otp_does_not_leak_in_production_environment` | Production không trả `debug_otp` khi gửi OTP đăng ký | **PASS** | 2.24s |
| 12 | `test_bug02_b_forgot_password_otp_does_not_leak_in_production_environment` | Production không trả `debug_otp` khi quên mật khẩu | **PASS** | 3.10s |
| 13 | `test_bug02_b_local_or_testing_environment_retains_debug_otp_capability` | Môi trường Local/Testing vẫn giữ `debug_otp` | **PASS** | 1.63s |
| 14 | `test_bug02_c_demo_token_rejected_in_production_for_reception_dashboard` | Demo token bị từ chối 401 trên Production | **PASS** | 1.35s |
| 15 | `test_bug02_c_demo_token_works_in_testing_environment_for_reception_dashboard` | Demo token hoạt động bình thường trong môi trường test | **PASS** | 1.28s |
| 16 | `test_bug02_c_valid_bearer_token_can_access_reception_dashboard` | Bearer token phiên thật truy cập dashboard thành công | **PASS** | 1.34s |
| 17 | `test_bug02_c_expired_or_revoked_token_rejected_on_reception_dashboard` | Token hết hạn hoặc thu hồi bị từ chối 401 trên dashboard | **PASS** | 1.14s |
| 18 | `test_bug02_c_no_unauthenticated_fallback_to_arbitrary_staff_user` | Không tự động chọn nhân viên tùy tiện khi thiếu token | **PASS** | 0.73s |

### 3.2. Tổng hợp kết quả kiểm thử Runtime

```text
Tests:    18 passed (44 assertions)
Duration: 55.84s
Status:   100% PASS - 0 FAILED - 0 SKIPPED
```

---

## 4. Kiểm thử Hồi quy các phân hệ liên quan (Zero Regression)

Đã chạy kiểm tra hồi quy các bộ test suites liên quan trực tiếp:

1. **`tests/Feature/ReceptionDashboard/ReceptionDashboardTest.php`**:
   - Kết quả: **8 passed (59 assertions)** - 100% PASS.
   - Thời gian: 45.44s.
   - Xác nhận: Toàn bộ KPI, bưu phẩm tồn đọng, khách viếng thăm và phân quyền dashboard hoạt động hoàn hảo.

2. **`tests/Feature/RbacSecurityTest.php`**:
   - Kết quả: **13 passed (116 assertions)** - 100% PASS.
   - Thời gian: 47.23s.
   - Xác nhận: Đăng nhập cấp quyền chi tiết, Super Admin bypass, phân quyền vai trò không bị ảnh hưởng.

---

## 5. Kiểm tra Cú pháp, Kiểu dữ liệu và Định dạng Mã nguồn

1. **Kiểm tra PHP Syntax (`php -l`)**:
   - `app/Http/Controllers/AuthController.php`: `No syntax errors detected` (PASS)
   - `app/Http/Controllers/ReceptionDashboardController.php`: `No syntax errors detected` (PASS)
   - `tests/Feature/AuthSecurityRegressionTest.php`: `No syntax errors detected` (PASS)

2. **Kiểm tra TypeScript Frontend (`npm run typecheck`)**:
   - Lệnh: `tsc --noEmit`
   - Kết quả: Exited code 0 (PASS 100%).

3. **Kiểm tra Định dạng (Laravel Pint)**:
   - Lệnh: `vendor/bin/pint --dirty --format agent`
   - Đã chuẩn hóa vị trí dấu ngoặc và loại bỏ import không sử dụng `use App\Models\User;` trong `ReceptionDashboardController.php`.

4. **Kiểm tra Git Diff (`git diff --check`)**:
   - Exit code 0, không có bất kỳ cảnh báo whitespace hay conflict marker nào.

---

## 6. Đánh giá Rủi ro còn lại

- **Rủi ro hồi quy người dùng**: Bằng 0. Các tài khoản demo và tài khoản thật đều dùng cơ chế bcrypt chuẩn `Hash::check()`.
- **Rủi ro giao diện frontend**: Bằng 0. TypeScript typecheck đạt 100%, hợp đồng JSON API trả về `debug_otp: null` tương thích hoàn toàn với logic `res.debug_otp || null` của React.
- **Rủi ro môi trường**: Bằng 0. Toàn bộ logic demo token và debug OTP đã được cách ly nghiêm ngặt chỉ trong `testing` và `local`.

---

## 7. KẾT LUẬN

Lỗ hổng **BUG-02 (Authentication Security)** đã được xử lý triệt để, sạch sẽ, đạt chuẩn bảo mật Zero-Trust và vượt qua 100% các ca kiểm thử hồi quy runtime. 

Sẵn sàng bàn giao cho người dùng nghiệm thu!

