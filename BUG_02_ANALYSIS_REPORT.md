# BÁO CÁO PHÂN TÍCH VÀ KIỂM TOÁN BẢO MẬT AUTHENTICATION (BUG-02)

> **Mã lỗi**: BUG-02  
> **Mức độ nghiêm trọng**: CRITICAL  
> **Phạm vi**: Authentication, Password Verification, OTP Security, Demo Tokens  
> **Chế độ**: REVIEW ONLY – Khảo sát độc lập, bảo toàn mã nguồn, không chỉnh sửa code hay test.  
> **Thời gian thực hiện**: 04/11/2026  

---

## 1. Executive Summary

Quá trình kiểm toán bảo mật mã nguồn đã tiến hành rà soát chuyên sâu các cơ chế xác thực, quản lý phiên đăng nhập và bảo vệ dữ liệu nhạy cảm trên toàn bộ hệ thống Smart Building.

Kết quả kiểm toán xác nhận sự tồn tại của **3 lỗ hổng bảo mật nghiêm trọng (Confirmed Vulnerabilities)** trong phân hệ xác thực:

1. **BUG-02-A (CRITICAL - Authentication Bypass via Hardcoded Passwords)**:
   Tại `AuthController::login()`, hệ thống duy trì một danh sách mật khẩu fallback dạng plain-text hardcoded cho 10 tài khoản hệ thống (bao gồm `admin`, `manager`, `receptionist`, `security`, `cudan`). Mệnh đề xác thực sử dụng toán tử `||` cho phép đăng nhập thành công bằng mật khẩu fallback mà **hoàn toàn bỏ qua kiểm tra hàm băm mật khẩu `Hash::check()`**, không có bất kỳ điều kiện giới hạn môi trường nào, dẫn đến nguy cơ chiếm quyền quản trị tối cao trên môi trường Production.

2. **BUG-02-B (HIGH - Information Disclosure / OTP Leakage)**:
   Tại `AuthController::sendRegisterOtp()`, trường `'debug_otp' => $otp` được trả về trực tiếp trong HTTP JSON response cho client một cách vô điều kiện. Bất kỳ người dùng nào trên Production cũng có thể đọc trực tiếp mã OTP 6 số từ API response để xác thực email mà không cần quyền truy cập hộp thư thật.

3. **BUG-02-C (HIGH - Unauthorized Dashboard Access via Hardcoded Demo Token)**:
   Tại `ReceptionDashboardController::resolveAuthUser()`, controller chấp nhận các chuỗi Bearer token tĩnh (`smart_token_*_demo`) mà **không có kiểm tra môi trường `testing`/`local`**. Khi nhận được chuỗi token này, hệ thống tự động gán đối tượng người dùng là nhân viên đầu tiên tìm thấy trong cơ sở dữ liệu (`User::whereHas('roles', ...)->first()`), cho phép bất kỳ ai trên internet xem toàn bộ dữ liệu KPI, bưu phẩm, khách viếng thăm của tòa nhà trong môi trường Production.

Cả 3 lỗ hổng đều có thể khai thác trực tiếp từ bên ngoài qua giao diện web hoặc HTTP API mà không cần quyền hạn từ trước.

---

## 2. BUG-02-A: Hardcoded Fallback Password

### 2.1. Vị trí mã nguồn và logic vi phạm
- **Tập tin**: [`app/Http/Controllers/AuthController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AuthController.php#L138-L144)
- **Phương thức**: `AuthController::login(Request $request)`
- **Dòng code**: 138–144
- **Đoạn mã thực tế**:
  ```php
  // Kiểm tra mật khẩu chuẩn xác thực cryptographic hash hoặc fallback demo mật khẩu cho môi trường thử nghiệm
  $isDemoFallbackPass = in_array($user->username, ['admin', 'manager', 'receptionist', 'security', 'resident.owner', 'resident.member', 'accountant', 'nguyenvanan', 'quanly', 'letan'], true)
      && in_array($password, ['123***', '123***', 'Admin@******', 'Manager@******', 'Receptionist@******', 'Security@******', 'Resident@******', 'Accountant@******'], true);

  $isValidPassword = $isDemoFallbackPass || (! empty($user->password_hash) && rescue(fn () => Hash::check($password, $user->password_hash), false));
  ```
  *(Các chuỗi mật khẩu thật đã được che theo nguyên tắc an toàn).*

### 2.2. Trả lời các câu hỏi xác minh bắt buộc
1. **Có mật khẩu hardcoded trong source code không?**  
   **CÓ.** Có 8 mật khẩu plain-text được khai báo trực tiếp trong mảng cứng tại dòng 140.
2. **Mật khẩu fallback được sử dụng trong điều kiện nào?**  
   Khi `username` của người dùng thuộc 10 tài khoản chỉ định và mật khẩu nhập vào khớp 1 trong 8 mật khẩu hardcoded. Do dùng toán tử `$isDemoFallbackPass || (...)`, khi điều kiện này đúng thì nhánh băm mật khẩu `Hash::check()` không được thực thi.
3. **Có tài khoản nào có thể đăng nhập mà không cần mật khẩu hợp lệ của người dùng?**  
   **CÓ.** Toàn bộ 10 tài khoản trọng yếu của hệ thống (kể cả tài khoản `SUPER_ADMIN`) đều đăng nhập được bằng mật khẩu fallback, bất kể mật khẩu thực sự lưu trong cơ sở dữ liệu là gì.
4. **Có nguy cơ cùng một mật khẩu fallback được dùng cho nhiều tài khoản không?**  
   **CÓ.** Mật khẩu fallback dạng quản trị có thể dùng để đăng nhập chéo cho cả tài khoản cư dân và lễ tân; ngược lại mật khẩu ngắn có thể dùng để đăng nhập vào tài khoản `SUPER_ADMIN`.
5. **Cơ chế hiện tại có thể bị khai thác để bypass xác thực không?**  
   **CÓ.** Đây là một backdoor xác thực điển hình trên môi trường Production.
6. **Loại bỏ fallback có ảnh hưởng đến tài khoản cũ hoặc chức năng cấp tài khoản không?**  
   **KHÔNG.** Các tài khoản trong hệ thống đều có cột `password_hash` được băm bcrypt hợp lệ (qua seeder `RestoreDemoAccountsSeeder.php` hoặc qua `AccountProvisioningService.php`). Khi bỏ fallback, các tài khoản vẫn đăng nhập hoàn toàn bình thường qua `Hash::check($password, $user->password_hash)`.

---

## 3. BUG-02-B: Debug OTP Leakage

### 3.1. Vị trí mã nguồn và logic vi phạm
- **Tập tin**: [`app/Http/Controllers/AuthController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AuthController.php#L407-L413)
- **Phương thức**: `AuthController::sendRegisterOtp(Request $request)`
- **Dòng code**: 407–413
- **Đoạn mã thực tế**:
  ```php
  return response()->json([
      'success' => true,
      'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
      'email' => $email,
      'debug_otp' => $otp,
  ]);
  ```
- **So sánh với `forgotPassword()` (dòng 771)**:
  ```php
  'debug_otp' => app()->isLocal() ? $otp : null,
  ```
  Tại `forgotPassword()`, lập trình viên đã có ý thức kiểm tra `app()->isLocal()`, nhưng tại `sendRegisterOtp()`, trường `debug_otp` lại bị trả về **vô điều kiện**.

### 3.2. Trả lời các câu hỏi xác minh bắt buộc
1. **API response có trả về `debug_otp` không?**  
   **CÓ.** `sendRegisterOtp()` luôn trả về giá trị OTP thật trong trường `debug_otp`.
2. **OTP có bị ghi vào log, response hoặc thông tin lỗi không?**  
   Bị lộ trực tiếp trong HTTP Response JSON.
3. **Điều kiện hiển thị OTP có được giới hạn theo môi trường không?**  
   Tại `sendRegisterOtp()`: **HOÀN TOÀN KHÔNG**. Nó hiển thị ở mọi môi trường, kể cả `production`.
4. **Người dùng Production có thể đọc OTP từ response hay không?**  
   **CÓ.** Bất kỳ ai gọi endpoint này trên Production đều đọc được mã OTP ngay lập tức.
5. **OTP có thời hạn hiệu lực không?**  
   Có (15 phút thông qua Cache và bảng `password_reset_tokens`).
6. **Có giới hạn số lần gửi OTP và xác thực OTP không?**  
   Hiện tại route có middleware `throttle:60,1` (60 request/phút) nhưng chưa có cơ chế lockout số lần nhập sai mã OTP liên tiếp (chống brute-force 6 số).
7. **OTP cũ có bị vô hiệu hóa khi gửi OTP mới không?**  
   Có, `Cache::put` và `updateOrInsert` sẽ ghi đè giá trị mới.
8. **Có cơ chế chống gửi OTP liên tục không?**  
   Frontend có countdown 60s, nhưng backend API chỉ chặn theo rate-limit chung của route (60/min), chưa có cooldown riêng giữa 2 lần gửi cho cùng một email.

---

## 4. BUG-02-C: Demo Token Without Environment Restriction

### 4.1. Vị trí mã nguồn và logic vi phạm
- **Tập tin**: [`app/Http/Controllers/ReceptionDashboardController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ReceptionDashboardController.php#L22-L36)
- **Phương thức**: `ReceptionDashboardController::resolveAuthUser(Request $request)`
- **Dòng code**: 26–33
- **Đoạn mã thực tế**:
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

### 4.2. So sánh đối chiếu với các nơi xử lý Token khác
Trong hệ thống, cả [`AuthenticateBearer.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Middleware/AuthenticateBearer.php#L85) và [`RbacService.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/RbacService.php#L38) đều đã được bọc trong điều kiện nghiêm ngặt:
```php
if (app()->environment('testing', 'local')) {
    // Chỉ chấp nhận token demo khi đang ở local hoặc testing
}
```
Tuy nhiên, tại `ReceptionDashboardController`, lập trình viên đã viết thêm một khối fallback riêng biệt nằm **ngoài** mọi điều kiện kiểm tra môi trường. Khi chạy trên Production:
- `RbacService::resolveUser($request)` từ chối token demo và trả về `null`.
- Khối `if (! $user)` trong controller bắt được và **tự động cấp quyền truy cập** bằng cách lấy tài khoản nhân viên đầu tiên có trong cơ sở dữ liệu.

### 4.3. Trả lời các câu hỏi xác minh bắt buộc
1. **Demo token được khai báo và kiểm tra ở đâu?**  
   Tại `ReceptionDashboardController.php` (dòng 27–32), `RbacService.php` (dòng 38–54), và `AuthenticateBearer.php` (dòng 85–108).
2. **Những API nào chấp nhận demo token không an toàn?**  
   `GET /api/v1/reception/dashboard` và `GET /api/reception/dashboard`.
3. **Token có thể dùng mà không cần Bearer token hợp lệ trong DB không?**  
   **CÓ.** Chỉ cần gửi chuỗi tĩnh `Authorization: Bearer smart_token_reception_demo`.
4. **Demo token có được chấp nhận ở Production không?**  
   **CÓ.** Hoàn toàn không có rào cản môi trường ở controller này.
5. **Có phân biệt môi trường không?**  
   Tại `ReceptionDashboardController`: Hoàn toàn KHÔNG.
6. **Có thể dùng để xem dữ liệu dashboard thật không?**  
   **CÓ.** Người gửi sẽ xem được toàn bộ số liệu thống kê bưu phẩm, khách viếng thăm thật của tòa nhà.

---

## 5. Phân tích nguyên nhân gốc rễ (Root Cause)

1. **Sự tiện lợi trong phát triển (Developer Convenience Over Security)**:
   Các lập trình viên ban đầu thêm danh sách mật khẩu fallback và chuỗi demo token tĩnh để phục vụ kiểm thử nhanh trên frontend hoặc viết test mà không cần khởi tạo phiên hợp lệ.
2. **Thiếu kiểm tra môi trường (Environment Isolation Absence)**:
   Khi đưa tính năng lên môi trường chung, các đoạn mã thử nghiệm này không được bọc điều kiện `app()->environment('local', 'testing')` hoặc không được loại bỏ trước khi triển khai.
3. **Phá vỡ kiến trúc xác thực tập trung (Bypassing Centralized Auth)**:
   Thay vì tin cậy vào `AuthenticateBearer` middleware hoặc `RbacService`, controller `ReceptionDashboardController` tự ý triển khai cơ chế phân giải người dùng riêng, tạo ra cửa sau bypass.

---

## 6. Kịch bản khai thác thực tế (Exploitation Scenarios)

### Kịch bản 1: Chiếm quyền Quản trị viên Tối cao (SUPER_ADMIN)
- **Mục tiêu**: Đăng nhập tài khoản `admin`.
- **Hành động của kẻ tấn công**: Gửi request `POST /api/v1/auth/login` với payload:
  ```json
  { "identifier": "admin", "password": "123***" }
  ```
- **Kết quả**: Dù quản trị viên thật đã đổi mật khẩu thành một chuỗi 32 ký tự phức tạp, hệ thống vẫn cấp `access_token` hợp lệ với vai trò `SUPER_ADMIN`. Kẻ tấn công có toàn quyền cấu hình hệ thống, xóa dữ liệu cư dân, cấp thẻ RFID.

### Kịch bản 2: Đăng ký tài khoản cư dân mạo danh (Account Impersonation)
- **Mục tiêu**: Đăng ký tài khoản bằng email của Ban Quản trị hoặc chủ căn hộ mà không sở hữu hộp thư.
- **Hành động**: Gọi `POST /api/v1/auth/send-register-otp` với `email: "truongbqt@toanha.vn"`.
- **Kết quả**: Response trả về `{ "success": true, "debug_otp": "482910" }`. Kẻ tấn công lấy ngay mã `482910`, gọi tiếp `verify-register-otp` và hoàn tất đăng ký tài khoản với email của nạn nhân.

### Kịch bản 3: Do thám dữ liệu vận hành tòa nhà không cần xác thực
- **Mục tiêu**: Thu thập thông tin khách ra vào và tình trạng bưu phẩm của cư dân.
- **Hành động**: Gọi `GET /api/v1/reception/dashboard` với header:
  `Authorization: Bearer smart_token_reception_demo`.
- **Kết quả**: Server trả về HTTP 200 kèm toàn bộ danh sách khách viếng thăm, căn hộ đang có khách, kiện hàng tồn đọng quá hạn.

---

## 7. Phân tích các luồng Authentication bắt buộc bảo toàn

Quá trình sửa lỗi phải bảo toàn 100% các luồng hoạt động sau:

1. **Đăng nhập hợp lệ**: Sử dụng `Hash::check($password, $user->password_hash)` qua thuật toán Bcrypt.
2. **Khởi tạo và quản lý phiên (`user_sessions`)**: Tạo token ngẫu nhiên dạng `smart_token_{uuid}_{random}`, băm SHA-256 lưu DB, kiểm tra `is_revoked` và `expires_at`.
3. **Xác thực qua Bearer Token**: `AuthenticateBearer` và `RbacService` giải mã token, nạp quyền đầy đủ.
4. **Tài khoản Demo chuẩn**: Các tài khoản demo trong `RestoreDemoAccountsSeeder` (`admin`, `quanly`, `letan`, `nguyenvanan`) vốn đã có mật khẩu băm chuẩn `Hash::make('123***')` trong cơ sở dữ liệu. Chúng sẽ đăng nhập hoàn toàn bình thường qua `Hash::check()` mà không cần bất kỳ fallback nào.
5. **Đăng nhập nhanh từ giao diện Demo (`LoginPage.tsx`)**: Các nút bấm chọn nhanh trên giao diện truyền mật khẩu `123***`, khớp hoàn toàn với hash trong cơ sở dữ liệu.
6. **Xác thực OTP Email**: Người dùng nhận email thật, nhập mã 6 số từ email để xác thực.

---

## 8. Đánh giá rủi ro hồi quy (Regression Risk Assessment)

| Thành phần | Nguy cơ hồi quy | Mức độ | Biện pháp kiểm soát |
|---|---|:---:|---|
| **Đăng nhập người dùng thật** | Không thể đăng nhập nếu mật khẩu trong DB bị lỗi định dạng | Thấp | Kiểm tra hàm băm chuẩn Bcrypt qua `Hash::check()`. Không thay đổi logic băm. |
| **Đăng nhập tài khoản demo** | Nút bấm demo trên trang đăng nhập có thể không vào được nếu DB thiếu hash | Thấp | Xác nhận `RestoreDemoAccountsSeeder` đã seed đúng hash cho `123***`. Đảm bảo tài khoản demo có `password_hash` hợp lệ. |
| **Kiểm thử tự động hiện tại** | Các test tự động cũ có thể dựa vào `smart_token_*_demo` | Trung bình | `RbacService` và `AuthenticateBearer` vẫn duy trì hỗ trợ demo token trong môi trường `testing`/`local`. |
| **Quy trình đăng ký tài khoản** | Frontend không tự điền OTP nếu thiếu `debug_otp` | Rất thấp | Frontend đã có sẵn luồng nhập OTP thủ công từ email; chỉ hiển thị nút điền debug khi `debug_otp` tồn tại. |
| **Dashboard Lễ tân** | Màn hình Dashboard bị lỗi 401 nếu frontend không gửi token phiên thật | Trung bình | Đảm bảo frontend luôn truyền token lấy từ phiên đăng nhập sau khi login thành công. |

---

## 9. Đề xuất phương án sửa tối thiểu (Proposed Minimal Fix)

### 9.1. Khắc phục BUG-02-A (`AuthController.php`)
- **Giải pháp**:
  Loại bỏ hoàn toàn cơ chế `$isDemoFallbackPass`. Chỉ xác thực mật khẩu duy nhất qua hàm băm an toàn:
  ```php
  $isValidPassword = ! empty($user->password_hash) && rescue(fn () => Hash::check($password, $user->password_hash), false);
  ```
  *(Trường hợp cần hỗ trợ testing riêng biệt, chỉ kích hoạt khi `app()->environment('local', 'testing')`, tuy nhiên khuyến nghị triệt tiêu hoàn toàn để đảm bảo nguyên tắc Zero-Trust).*

### 9.2. Khắc phục BUG-02-B (`AuthController.php`)
- **Giải pháp**:
  Giới hạn trường `debug_otp` trong `sendRegisterOtp()` chỉ xuất hiện trong môi trường `local` hoặc `testing`:
  ```php
  return response()->json([
      'success' => true,
      'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
      'email' => $email,
      'debug_otp' => app()->environment('local', 'testing') ? $otp : null,
  ]);
  ```
  Trong môi trường `production`, `debug_otp` luôn là `null` (hoặc loại bỏ khỏi payload).
  Đồng nhất logic này với `forgotPassword()`.

### 9.3. Khắc phục BUG-02-C (`ReceptionDashboardController.php`)
- **Giải pháp**:
  Loại bỏ khối cấp quyền tùy tiện trong `resolveAuthUser()` của `ReceptionDashboardController`.
  Ủy thác hoàn toàn việc xác thực cho `RbacService::resolveUser($request)`:
  ```php
  protected function resolveAuthUser(Request $request): ?object
  {
      return $request->user() ?? RbacService::resolveUser($request);
  }
  ```
  Do `RbacService::resolveUser` đã có sẵn rào chắn `app()->environment('testing', 'local')`:
  - Trong môi trường `production`: Demo token bị từ chối 100%, trả về `null` -> ném lỗi `401 Unauthorized`.
  - Trong môi trường `testing`/`local`: Demo token vẫn hoạt động mượt mà cho các bài test tự động.

---

## 10. Danh sách các tập tin dự kiến thay đổi

Chỉ can thiệp **tối thiểu 2 tập tin mã nguồn production**:

1. [`app/Http/Controllers/AuthController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AuthController.php):
   - Xóa bỏ fallback password cứng tại `login()`.
   - Giới hạn `debug_otp` theo môi trường tại `sendRegisterOtp()`.
2. [`app/Http/Controllers/ReceptionDashboardController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ReceptionDashboardController.php):
   - Chuẩn hóa `resolveAuthUser()`, loại bỏ bypass demo token không an toàn ngoài production.

---

## 11. Kế hoạch kiểm thử bổ sung (Tests Required)

Mở rộng bộ kiểm thử tự động tại [tests/Feature/AuthSecurityRegressionTest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/AuthSecurityRegressionTest.php) để bổ sung các ca kiểm thử hồi quy:

1. **Test BUG-02-A**:
   - `test_cannot_login_with_hardcoded_fallback_password_when_user_has_different_hash`: Đặt mật khẩu user là `SecurePass@2026`, thử đăng nhập bằng các mật khẩu fallback cũ (`123***`, `Admin@******`) -> Phải trả về **HTTP 401**.
   - `test_demo_account_with_seeded_hash_can_still_login`: Đăng nhập bằng tài khoản seeder với mật khẩu đúng `123***` -> Phải trả về **HTTP 200**.
2. **Test BUG-02-B**:
   - `test_register_otp_does_not_leak_in_production_environment`: Giả lập môi trường `production`, gọi `send-register-otp` -> `debug_otp` phải là `null` hoặc không tồn tại.
   - `test_forgot_password_otp_does_not_leak_in_production_environment`: Tương tự cho luồng quên mật khẩu.
3. **Test BUG-02-C**:
   - `test_demo_token_rejected_in_production_for_reception_dashboard`: Giả lập môi trường `production`, gửi `Authorization: Bearer smart_token_reception_demo` tới `/api/v1/reception/dashboard` -> Phải trả về **HTTP 401 Unauthorized**.
   - `test_valid_session_token_can_access_reception_dashboard`: Gửi token phiên thật từ `user_sessions` -> Phải trả về **HTTP 200**.

---

## 12. Câu hỏi mở cần thống nhất (Unresolved Questions)

1. *Trong môi trường testing và local, có cần duy trì `$isDemoFallbackPass` hay loại bỏ hoàn toàn 100%?*  
   **Khuyến nghị**: Loại bỏ 100%, vì toàn bộ tài khoản demo đã được nạp hash chuẩn `Hash::make('123***')` trong database. Việc giữ lại fallback dù ở local cũng tiềm ẩn nguy cơ cấu hình sai khi đưa vào staging/production.
2. *Trường `debug_otp` trong `production` nên trả về `null` hay hoàn toàn không xuất hiện trong JSON response?*  
   **Khuyến nghị**: Không trả về trường `debug_otp` hoặc gán `null` (để tương thích ngược với kiểu dữ liệu của TypeScript frontend `res.debug_otp || null`).

---

## 13. Kết luận và Đề xuất tiếp theo

- Cả 3 lỗ hổng trong BUG-02 đã được kiểm chứng và định vị chính xác vị trí dòng code, nguyên nhân cũng như phương án khắc phục tối thiểu.
- Giải pháp đề xuất không ảnh hưởng đến bất kỳ cấu trúc cơ sở dữ liệu nào, không làm thay đổi hợp đồng API hiện tại và bảo vệ 100% người dùng thật cũng như tài khoản demo.
- **Trạng thái hiện tại**: Đã hoàn thành giai đoạn 1 (Khảo sát & Phân tích). Tôi đã dừng lại và chờ chỉ thị phê duyệt từ bạn trước khi tiến hành viết code hay chỉnh sửa test.

