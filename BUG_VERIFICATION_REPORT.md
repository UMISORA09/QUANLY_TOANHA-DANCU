# BÁO CÁO XÁC MINH CHI TIẾT 12 LỖI (BUG-01 ĐẾN BUG-12)
**Dự án**: `UMISORA09/QUANLY_TOANHA-DANCU`  
**Nhánh kiểm tra**: `QuocTin/Fix_all`  
**Ngày thực hiện**: 2026-10-30  
**Nguyên tắc**: CHỈ ĐỌC – CHỈ PHÂN TÍCH – KHÔNG SỬA ĐỔI SOURCE CODE

---

## MỤC LỤC CHI TIẾT

1. [BUG-01 – Bảo Mật API Bưu Phẩm (Chức năng 9 & 11)](#bug-01--bảo-mật-api-bưu-phẩm-chức-năng-9--11)
2. [BUG-02 – Bảo Mật Đăng Nhập, Demo Token & Mã OTP (Chức năng 1, 4 & 13)](#bug-02--bảo-mật-đăng-nhập-demo-token--mã-otp-chức-năng-1-4--13)
3. [BUG-03 – Đăng Ký Tạm Trú / Tạm Vắng Dành Cho Cư Dân (Chức năng 3)](#bug-03--đăng-ký-tạm-trú--tạm-vắng-dành-cho-cư-dân-chức-năng-3)
4. [BUG-04 – RBAC Matrix & Kiểm Tra Quyền Hạn Ở Backend (Chức năng 1 & 2–13)](#bug-04--rbac-matrix--kiểm-tra-quyền-hạn-ở-backend-chức-năng-1--213)
5. [BUG-05 – Phân Quyền Các Kênh SSE / Realtime Stream (Chức năng 6, 7, 8, 9, 11, 12)](#bug-05--phân-quyền-các-kênh-sse--realtime-stream-chức-năng-6-7-8-9-11-12)
6. [BUG-06 – Quy Trình Từ Chối Đăng Ký Phương Tiện (Chức năng 5)](#bug-06--quy-trình-từ-chối-đăng-ký-phương-tiện-chức-năng-5)
7. [BUG-07 – Xem Lịch Trực Theo Tuần / Tháng (Chức năng 12)](#bug-07--xem-lịch-trực-theo-tuần--tháng-chức-năng-12)
8. [BUG-08 – Phạm Vi Danh Sách Khách & Gán Căn Hộ Fallback (Chức năng 7)](#bug-08--phạm-vi-danh-sách-khách--gán-căn-hộ-fallback-chức-năng-7)
9. [BUG-09 – Thêm Lại Cư Dân Đã Bị Xóa Mềm Vào Căn Hộ (Chức năng 2)](#bug-09--thêm-lại-cư-dân-đã-bị-xóa-mềm-vào-căn-hộ-chức-năng-2)
10. [BUG-10 – Ép Kiểu (int) Trên Khóa Chính UUID Người Thực Hiện (Chức năng 6)](#bug-10--ép-kiểu-int-trên-khóa-chính-uuid-người-thực-hiện-chức-năng-6)
11. [BUG-11 – Thống Kê KPI Tính Trên Trang Hiện Tại Thay Vì Toàn Bộ Dữ Liệu (Chức năng 2, 3, 5)](#bug-11--thống-kê-kpi-tính-trên-trang-hiện-tại-thay-vì-toàn-bộ-dữ-liệu-chức-năng-2-3-5)
12. [BUG-12 – Hiển Thị Bảng Đăng Ký Tạm Trú / Tạm Vắng Trên Các Màn Hình Responsive (Chức năng 3)](#bug-12--hiển-thị-bảng-đăng-ký-tạm-trú--tạm-vắng-trên-các-màn-hình-responsive-chức-năng-3)
13. [Bảng Tổng Hợp Xác Minh 12 Lỗi & Khuyến Nghị](#bảng-tổng-hợp-xác-minh-12-lỗi--khuyến-nghị)

---

### BUG-01 – Bảo Mật API Bưu Phẩm (Chức năng 9 & 11)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `CRITICAL`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: 10/13 API bưu phẩm trong `routes/web.php` thiếu middleware xác thực `auth.bearer`; controller/service không gọi kiểm tra quyền nhân viên (`assertStaffAuthorized`); hàm `receiveParcel()` tự động gán ID nhân viên đầu tiên trong database khi request không có token; và component `ResidentParcelWidget.tsx` trên cổng cư dân tải toàn bộ bưu phẩm của cả tòa nhà kèm theo mã PIN 6 số và mã QR.

**4. Bằng chứng trong source code:**
- **File 1**: `routes/web.php` (Dòng 219–231)
  ```php
  Route::get('/api/v1/reception/parcels', [ReceptionParcelController::class, 'index']);
  Route::get('/api/v1/reception/parcels/lookup', [ReceptionParcelController::class, 'lookup']);
  Route::post('/api/v1/reception/parcels/check-out', [ReceptionParcelController::class, 'checkOut'])->middleware('auth.bearer');
  Route::post('/api/v1/reception/parcels', [ReceptionParcelController::class, 'store']);
  Route::get('/api/v1/reception/parcels/recipients/search', [ReceptionParcelController::class, 'searchRecipients']);
  Route::get('/api/v1/reception/parcels/apartments/search', [ReceptionParcelController::class, 'searchApartments']);
  Route::post('/api/v1/reception/parcels/remind-expiring', [ReceptionParcelController::class, 'remindExpiring']);
  Route::get('/api/v1/reception/parcels/{id}', [ReceptionParcelController::class, 'show']);
  Route::match(['PUT', 'POST'], '/api/v1/reception/parcels/{id}', [ReceptionParcelController::class, 'update']);
  Route::post('/api/v1/reception/parcels/{id}/check-out', [ReceptionParcelController::class, 'checkOut'])->middleware('auth.bearer');
  Route::post('/api/v1/reception/parcels/{id}/handover', [ReceptionParcelController::class, 'checkOut'])->middleware('auth.bearer');
  Route::post('/api/v1/reception/parcels/{id}/remind', [ReceptionParcelController::class, 'remind']);
  Route::delete('/api/v1/reception/parcels/{id}', [ReceptionParcelController::class, 'destroy']);
  ```
- **File 2**: `app/Http/Controllers/ReceptionParcelController.php` (Dòng 74–80)
  ```php
  $staffUser = $request->user() ?? RbacService::resolveUser($request);
  $parcel = $this->service->receiveParcel(
      data: $validated,
      staffUser: $staffUser,
      photoFile: $photoFile
  );
  ```
- **File 3**: `app/Services/ReceptionParcelService.php` (Dòng 259–269)
  ```php
  $staffId = $staffUser?->id;
  if (! $staffId) {
      // Fallback lấy nhân viên lễ tân/admin đầu tiên nếu chạy CLI/Unit Test không có session
      $staffId = User::whereHas('roles', function ($q) {
          $q->whereIn('roles.role_code', ['RECEPTIONIST', 'STAFF', 'ADMIN', 'SUPER_ADMIN', 'SECURITY_GUARD']);
      })->value('id') ?? User::value('id');

      if (! $staffId) {
          throw new \RuntimeException('Không tìm thấy tài khoản nhân viên hợp lệ để ghi nhận phiếu tiếp nhận.');
      }
  }
  ```
- **File 4**: `resources/js/Components/ResidentParcelWidget.tsx` (Dòng 99–103)
  ```tsx
  const response = await api.getReceptionParcels({
    status: 'PENDING',
    limit: 20,
  });
  ```

**5. Kết quả truy vết:**  
- **Về Authentication & Authorization**: `routes/web.php` xác nhận chỉ có đúng 3 route (`check-out`, `{id}/check-out`, `{id}/handover`) là có `->middleware('auth.bearer')`. 10 route còn lại (`index`, `store`, `show`, `update`, `destroy`, `lookup`, `remind`, `searchRecipients`, `searchApartments`, `remind-expiring`) hoàn toàn không có bất kỳ middleware xác thực nào.
- **Về hàm `receiveParcel()`**: Trong `ReceptionParcelService.php`, khi `$staffUser === null` (do request không gửi token), code không trả về lỗi `401 Unauthorized` mà nhảy vào khối fallback ở dòng 260–265, tự động truy vấn tìm user đầu tiên có role nhân viên và lấy `id` của người đó gán vào `received_by_user_id`. Điều này cho phép một người ngoài Internet gửi request `POST /api/v1/reception/parcels` nặc danh và hệ thống vẫn tạo bưu phẩm thành công (HTTP 201).
- **Về việc lộ PIN/QR toàn tòa nhà**: `ResidentParcelWidget.tsx` gọi `api.getReceptionParcels({ status: 'PENDING', limit: 20 })`. Trong `ReceptionParcelService::getParcels()`, nếu không có `filters['apartment_id']`, câu query lấy toàn bộ bưu phẩm `status = PENDING` của tất cả các căn hộ, kèm theo đầy đủ trường `pickup_pin_code` (mã PIN 6 số) và `pickup_qr_code` (mã QR). Một cư dân bất kỳ đăng nhập vào trang cá nhân sẽ thấy toàn bộ bưu phẩm của hàng xóm kèm mã PIN để lấy hàng.

**6. Ảnh hưởng:**  
- Rủi ro an ninh nghiêm trọng: Bất kỳ ai cũng có thể gọi API xóa bưu phẩm (`DELETE /api/v1/reception/parcels/{id}`), sửa thông tin bưu phẩm (`PUT`), hoặc xem danh sách số điện thoại cư dân (`GET .../recipients/search`).
- Cư dân có thể lấy mã PIN/QR của căn hộ khác để nhận hàng trái phép tại quầy lễ tân.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Dùng công cụ cURL/Postman gửi request `POST /api/v1/reception/parcels` không có header `Authorization` với payload hợp lệ; hoặc gửi `GET /api/v1/reception/parcels?status=PENDING` bằng token của cư dân. Cả hai đều trả về HTTP 200/201 kèm dữ liệu bưu phẩm toàn tòa nhà.

**8. Hướng xử lý đề xuất:**  
- Nhóm toàn bộ route `/api/v1/reception/parcels*` vào `Route::middleware('auth.bearer')`.
- Trong `ReceptionParcelController`, bắt buộc kiểm tra `$staffUser = $request->user()`; nếu rỗng hoặc không có vai trò nhân viên thì từ chối HTTP 403 ngay tại controller.
- Trong `ReceptionParcelService::receiveParcel()`, bỏ hoàn toàn khối fallback lấy user đầu tiên trong database.
- Trong `ReceptionParcelService::getParcels()`, nếu người gọi có role cư dân (`RESIDENT*`), tự động ép điều kiện `whereIn('apartment_id', $userApartmentIds)`.

**9. Phạm vi ảnh hưởng:**  
- `routes/web.php`, `ReceptionParcelController.php`, `ReceptionParcelService.php`, `ResidentParcelWidget.tsx`.

**10. Rủi ro khi sửa:**  
- Cần đảm bảo các test case hiện tại trong `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` có truyền token nhân viên (qua `$this->authHeaders()`) để không bị gãy test khi bật middleware.

---

### BUG-02 – Bảo Mật Đăng Nhập, Demo Token & Mã OTP (Chức năng 1, 4 & 13)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `CRITICAL`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: `AuthController::login` có danh sách mật khẩu dự phòng hardcode cho 10 tài khoản hệ thống mà không giới hạn môi trường `local/testing`; `sendRegisterOtp` trả thẳng `debug_otp` trong JSON response; `ReceptionDashboardController` chấp nhận 3 demo token mà không kiểm tra môi trường.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Http/Controllers/AuthController.php` (Dòng 138–142)
  ```php
  // Kiểm tra mật khẩu chuẩn xác thực cryptographic hash hoặc fallback demo mật khẩu cho môi trường thử nghiệm
  $isDemoFallbackPass = in_array($user->username, ['admin', 'manager', 'receptionist', 'security', 'resident.owner', 'resident.member', 'accountant', 'nguyenvanan', 'quanly', 'letan'], true)
      && in_array($password, ['123567', '123456', 'Admin@123456', 'Manager@123456', 'Receptionist@123456', 'Security@123456', 'Resident@123456', 'Accountant@123456'], true);

  $isValidPassword = $isDemoFallbackPass || (! empty($user->password_hash) && rescue(fn () => Hash::check($password, $user->password_hash), false));
  ```
- **File 2**: `app/Http/Controllers/AuthController.php` (Dòng 407–413)
  ```php
  return response()->json([
      'success' => true,
      'message' => "Mã xác thực OTP đã được gửi tới email {$email}. Vui lòng kiểm tra hộp thư của bạn.",
      'email' => $email,
      'debug_otp' => $otp,
  ]);
  ```
- **File 3**: `app/Http/Controllers/ReceptionDashboardController.php` (Dòng 26–33)
  ```php
  if (! $user) {
      $bearer = $request->bearerToken();
      if ($bearer === 'smart_token_reception_demo' || $bearer === 'smart_token_admin_demo' || $bearer === 'smart_token_manager_demo') {
          $user = User::whereHas('roles', function ($q) {
              $q->whereIn('role_code', ['RECEPTIONIST', 'STAFF', 'ADMIN', 'SUPER_ADMIN', 'SECURITY_GUARD']);
          })->first() ?? User::where('status', 'ACTIVE')->first();
      }
  }
  ```

**5. Kết quả truy vết:**  
- **Về `login`**: Tại dòng 139–140 của `AuthController.php`, biến `$isDemoFallbackPass` hoàn toàn không có điều kiện kiểm tra môi trường `app()->environment('local', 'testing')`. Nếu hệ thống deploy lên production, bất kỳ ai nhập tài khoản `admin` kèm mật khẩu `Admin@123456` hoặc `123567` đều đăng nhập thành công, ngay cả khi người quản trị thực tế đã đổi mật khẩu khác.
- **Về `sendRegisterOtp`**: Dòng 411 trả về `'debug_otp' => $otp` vô điều kiện. Khi đối chiếu với hàm `forgotPassword()` ở dòng 771 cùng file, hàm `forgotPassword` có kiểm tra `'debug_otp' => app()->isLocal() ? $otp : null`, chứng tỏ `sendRegisterOtp` đã bị bỏ sót việc kiểm tra môi trường.
- **Về `ReceptionDashboardController`**: Trong khi `AuthenticateBearer` middleware (dòng 52) và `RbacService` (dòng 38) đều bọc `if (app()->environment('testing', 'local'))` trước khi chấp nhận demo token, thì tại `ReceptionDashboardController::resolveAuthUser()`, lập trình viên đã viết một hàm xác thực riêng biệt và bỏ qua kiểm tra môi trường.

**6. Ảnh hưởng:**  
- Nguy cơ chiếm đoạt tài khoản quản trị viên tối cao (`admin`, `manager`) trên môi trường production.
- Cơ chế gửi OTP qua email bị vô hiệu hóa vì client có thể đọc mã OTP trực tiếp từ response mạng.
- Người ngoài có thể truy cập toàn bộ số liệu dashboard lễ tân bằng demo token cố định.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Gọi `POST /api/v1/auth/login` với `{ "username": "admin", "password": "Admin@123456" }` hoặc gọi `POST /api/v1/auth/send-register-otp` với bất kỳ email nào và xem payload JSON trả về.

**8. Hướng xử lý đề xuất:**  
- Trong `AuthController::login`: bọc logic `$isDemoFallbackPass` bằng điều kiện `app()->environment('local', 'testing')`.
- Trong `AuthController::sendRegisterOtp`: sửa thành `'debug_otp' => app()->environment('local', 'testing') ? $otp : null`.
- Trong `ReceptionDashboardController::resolveAuthUser`: bọc điều kiện kiểm tra demo token bằng `app()->environment('local', 'testing')`.

**9. Phạm vi ảnh hưởng:**  
- `app/Http/Controllers/AuthController.php`, `app/Http/Controllers/ReceptionDashboardController.php`.

**10. Rủi ro khi sửa:**  
- Rất cẩn trọng: Việc sửa `AuthController::login` có thể ảnh hưởng đến các bài test tự động nếu test runner không thiết lập `APP_ENV=testing`. Cần đảm bảo `phpunit.xml` luôn có `<env name="APP_ENV" value="testing"/>`.

---

### BUG-03 – Đăng Ký Tạm Trú / Tạm Vắng Dành Cho Cư Dân (Chức năng 3)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `HIGH`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Cư dân được tạo ở Chức năng 2 không thể sử dụng Chức năng 3 (Đăng ký tạm trú / tạm vắng). Controller và FormRequest chặn cứng, chỉ cho phép `SUPER_ADMIN` và `BUILDING_MANAGER`.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Http/Requests/TemporaryRegistrationRequest.php` (Dòng 15–31)
  ```php
  public function authorize(): bool
  {
      $user = $this->user();
      if (! $user) {
          return false;
      }
      if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
          return true;
      }
      if (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])) {
          return true;
      }
      return false;
  }
  ```
- **File 2**: `app/Http/Controllers/TemporaryRegistrationController.php` (Dòng 25–44)
  ```php
  $isAdmin = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));

  if (! $isAdmin) {
      return response()->json([
          'success' => false,
          'message' => 'Bạn không có quyền quản trị để thực hiện thao tác này.',
          'error' => 'FORBIDDEN',
      ], Response::HTTP_FORBIDDEN);
  }
  ```
- **File 3**: `resources/js/Pages/ManagementHome.tsx` (Dòng 62 & 834)
  - `TemporaryRegistrationManagement` chỉ được nhúng trong trang quản trị Ban quản lý (`ManagementHome.tsx`), hoàn toàn không xuất hiện trên Cổng thông tin Cư dân (`ResidentHome.tsx`).

**5. Kết quả truy vết:**  
- Đối chiếu với yêu cầu tích hợp liên chức năng số 1 trong tài liệu yêu cầu: *"1. Cư dân được tạo trong chức năng 2 có thể sử dụng chức năng 3 và 7 đúng quyền."*
- Trong khi ở Chức năng 7 (Đăng ký khách), hệ thống có controller riêng `ResidentVisitorController` cho phép cư dân thao tác; thì ở Chức năng 3, cả controller, request validation và routing đều chỉ cho phép vai trò Quản trị viên (`SUPER_ADMIN`, `BUILDING_MANAGER`).
- Khi cư dân (`RESIDENT`, `RESIDENT_OWNER`, `RESIDENT_MEMBER`) gọi API `POST /api/v1/residents/temporary-registrations` hoặc `GET /api/v1/residents/temporary-registrations`, hệ thống lập tức trả về HTTP 403 Forbidden. Hoàn toàn không có luồng nào cho phép cư dân tự nộp giấy tạm trú/tạm vắng.

**6. Ảnh hưởng:**  
- Vi phạm luồng kiểm thử tích hợp liên chức năng số 1.
- Cư dân không thể chủ động khai báo tạm trú/tạm vắng trực tuyến mà phải nhờ Admin/Ban quản lý nhập hộ từ trang quản trị.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Dùng token của bất kỳ tài khoản cư dân nào gọi `GET` hoặc `POST` tới `/api/v1/residents/temporary-registrations`, kết quả nhận về luôn là HTTP 403 với thông báo: `"Bạn không có quyền quản trị để thực hiện thao tác này."`

**8. Hướng xử lý đề xuất:**  
- Mở rộng hàm `authorize()` trong `TemporaryRegistrationRequest` và hàm `checkAdminAuthorization()` trong `TemporaryRegistrationController`: cho phép thêm các vai trò `RESIDENT`, `RESIDENT_OWNER`, `RESIDENT_MEMBER` đối với các hành động Xem (`index`, `show`), Tạo (`store`), và Tải biểu mẫu CT01 (`downloadForm`, `exportForm`).
- Khi người gọi là cư dân, bắt buộc tự động gán và giới hạn theo đúng căn hộ của cư dân đó (`residents.user_id = $user->id`).
- Giữ nguyên việc chặn quyền đối với các thao tác xét duyệt nội bộ (`approve`, `reject`, `submitToPolice`).

**9. Phạm vi ảnh hưởng:**  
- `TemporaryRegistrationController.php`, `TemporaryRegistrationRequest.php`, `TemporaryRegistrationService.php`.

**10. Rủi ro khi sửa:**  
- Thấp. Chỉ mở rộng phạm vi ủy quyền cho role cư dân, không làm thay đổi các quy tắc duyệt của Ban quản lý.

---

### BUG-04 – RBAC Matrix & Kiểm Tra Quyền Hạn Ở Backend (Chức năng 1 & 2–13)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `HIGH`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: RBAC Matrix ở Chức năng 1 cung cấp ma trận quyền chi tiết (`VIEW`, `CREATE`, `UPDATE`, `DELETE`, `SPECIAL`, ...), nhưng các Controller của Chức năng 2–13 chỉ kiểm tra cứng `hasRole([...])` mà không kiểm tra `hasPermission(...)` từ RBAC Matrix. Việc cấp hoặc thu hồi quyền qua RBAC Matrix không có tác dụng khi gọi API trực tiếp.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Http/Controllers/ResidentController.php` (Dòng 35–37)
  ```php
  $isAdmin = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));
  ```
- **File 2**: `app/Http/Controllers/TemporaryRegistrationController.php` (Dòng 35–37)
  ```php
  $isAdmin = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));
  ```
- **File 3**: `app/Http/Controllers/AccessCardController.php` (Dòng 35–37)
  ```php
  $isAuthorized = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']));
  ```
- **File 4**: `app/Http/Controllers/StaffScheduleController.php` (Dòng 31–33)
  ```php
  if (
      method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ||
      (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'BUILDING_MANAGER', 'MANAGER']))
  ) {
      return $user;
  }
  ```
- **File 5**: `app/Http/Controllers/VehicleController.php` (Dòng 35–38)
  ```php
  $isAuthorized = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']))
      || ($requiredPermission && method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission))
      || (method_exists($user, 'hasPermission') && ($user->hasPermission('VEHICLE:VIEW') || $user->hasPermission('VEHICLE:CREATE')));
  ```

**5. Kết quả truy vết:**  
- **Về cấu trúc RBAC Matrix**: Chức năng 1 đã xây dựng hoàn chỉnh ma trận 10 module × 7 quyền tiêu chuẩn (`RbacService::ensureStandardPermissionsExist()`), lưu trữ quan hệ trong `role_permissions` và hỗ trợ cấp quyền tùy biến theo từng User thông qua role ẩn `CUSTOM_USER_{ID}`.
- **Về việc áp dụng ở Backend**:
  - Tại `ResidentController`, `TemporaryRegistrationController`, `AccessCardController`, `StaffScheduleController`: Toàn bộ các controller này chỉ kiểm tra `$user->hasRole([...])`. Hoàn toàn không có bất kỳ lệnh gọi nào tới `$user->hasPermission(...)` hay `RbacService::hasPermission(...)`.
  - Tại `VehicleController`: Dù có kiểm tra `hasPermission()`, nhưng lại dùng toán tử `||` nối với `hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', ...])`.
- **Hệ quả thực tế**:
  1. Khi Admin vào RBAC Matrix thu hồi quyền `Xóa cư dân` (`RESIDENT:DELETE`) hoặc `Xóa xe` (`VEHICLE:DELETE`) của vai trò `BUILDING_MANAGER`: Giao diện React có thể ẩn nút Xóa (nếu dùng hook `usePermission`), nhưng nếu tài khoản `BUILDING_MANAGER` đó gửi HTTP request trực tiếp bằng Postman tới `DELETE /api/v1/residents/{id}`, backend vẫn cho phép xóa vì `$user->hasRole('BUILDING_MANAGER')` vẫn trả về `true`.
  2. Khi Admin cấp thêm quyền `VEHICLE:VIEW` hoặc quyền `Người đặc biệt` (`SYSTEM:SPECIAL`) cho một nhân viên kế toán (`ACCOUNTANT`) trên RBAC Matrix: Tài khoản kế toán gọi API vẫn nhận HTTP 403 vì các controller không thừa nhận quyền được cấp.

**6. Ảnh hưởng:**  
- Vi phạm luồng kiểm thử tích hợp số 10: *"RBAC ở chức năng 1 áp dụng đúng cho các chức năng còn lại theo cấu hình quyền hiện có."*
- Mất khả năng phân quyền động (Dynamic RBAC) trên các API nghiệp vụ quan trọng.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Dùng tài khoản `BUILDING_MANAGER` đã bị xóa quyền `RESIDENT:DELETE` trong bảng `role_permissions` để gọi API `DELETE /api/v1/residents/{id}`; thao tác vẫn thành công với mã 200.

**8. Hướng xử lý đề xuất:**  
- Bổ sung kiểm tra `RbacService::hasPermission($user, 'RESIDENT:DELETE')` (hoặc middleware `permission:RESIDENT:DELETE`) vào các action cụ thể trong từng controller.
- Ưu tiên kiểm tra quyền trước; role chỉ đóng vai trò phân nhóm mặc định.

**9. Phạm vi ảnh hưởng:**  
- `ResidentController.php`, `TemporaryRegistrationController.php`, `VehicleController.php`, `AccessCardController.php`, `StaffScheduleController.php`.

**10. Rủi ro khi sửa:**  
- Trung bình. Cần rà soát danh mục permissions hiện có trong database (`permissions` table) để đảm bảo mọi action đều có mã permission tương ứng, tránh tình trạng chặn nhầm người dùng hợp lệ.

---

### BUG-05 – Phân Quyền Các Kênh SSE / Realtime Stream (Chức năng 6, 7, 8, 9, 11, 12)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `HIGH`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Trong `QuocTinRealtimeService::isUserAuthorizedForChannel()`, kênh `quoc-tin.rfid-cards` bị nối thêm `|| true`, và các kênh `quoc-tin.visitors`, `quoc-tin.parcels`, `quoc-tin.schedules` được gán cứng `=> true`. Mọi người dùng đã đăng nhập (kể cả cư dân) đều nghe được sự kiện realtime của các kênh này.

**4. Bằng chứng trong source code:**
- **File**: `app/Services/QuocTinRealtimeService.php` (Dòng 197–208)
  ```php
  'quoc-tin.rfid-cards', 'quoc-tin.rfid_cards' => (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']))
      || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || true,

  'quoc-tin.visitors' => true,

  'quoc-tin.parcels' => true,

  'quoc-tin.schedules', 'quoc-tin.staff_schedules' => true,

  default => false,
  ```

**5. Kết quả truy vết:**  
- **Tại dòng 199**: Biểu thức logic của kênh thẻ RFID có đoạn `|| true` ở cuối. Theo luật ngắn mạch (short-circuit evaluation) của PHP, toàn bộ biểu thức kiểm tra role phía trước bị vô hiệu hóa và luôn trả về `true`.
- **Tại dòng 201, 203, 205**: Kênh khách thăm (`visitors`), bưu phẩm (`parcels`) và lịch trực (`schedules`) đều trả về `true` trực tiếp mà không có bất kỳ điều kiện nào.
- **Tại `RealtimeStreamController::stream()`**: Khi một client kết nối tới `GET /api/realtime/stream?channels=quoc-tin.rfid-cards,quoc-tin.schedules,quoc-tin.parcels` với token của một Cư dân bình thường, hàm `getAuthorizedChannels()` gọi qua `isUserAuthorizedForChannel()` và cấp phép cho cả 3 kênh này.
- **Hệ quả**: Cư dân bình thường có thể mở stream SSE và nhận toàn bộ payload thông báo khi có cư dân khác làm mất thẻ RFID, khi bưu phẩm của các căn hộ khác cập nhật mã PIN, hoặc xem toàn bộ lịch trực và số điện thoại của nhân viên an ninh trong ca trực.

**6. Ảnh hưởng:**  
- Lộ lọt thông tin an ninh tòa nhà: Thao tác thẻ từ, khách ra vào, bưu phẩm và lịch trực bảo vệ bị phát tán công khai cho bất kỳ user nào có tài khoản trong hệ thống.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Dùng token của một tài khoản `RESIDENT_MEMBER` gọi `GET /api/realtime/stream?channels=quoc-tin.rfid-cards,quoc-tin.schedules`. Kết quả kết nối thành công (HTTP 200 `text/event-stream`) và nhận luồng dữ liệu sự kiện.

**8. Hướng xử lý đề xuất:**  
- Xóa bỏ `|| true` ở dòng 199.
- Kênh `quoc-tin.schedules`: Giới hạn cho các vai trò nhân viên (`SUPER_ADMIN`, `BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD`, `TECHNICIAN`).
- Kênh `quoc-tin.visitors` và `quoc-tin.parcels`: Chỉ cho phép nhân sự xem toàn bộ; nếu là cư dân, khi phát sự kiện cần lọc payload chỉ chứa bưu phẩm/khách thuộc căn hộ của cư dân đó.

**9. Phạm vi ảnh hưởng:**  
- `app/Services/QuocTinRealtimeService.php`.

**10. Rủi ro khi sửa:**  
- Rất thấp. Việc xóa `|| true` và thắt chặt phân quyền kênh SSE hoàn toàn không ảnh hưởng đến logic nghiệp vụ lưu trữ cơ sở dữ liệu.

---

### BUG-06 – Quy Trình Từ Chối Đăng Ký Phương Tiện (Chức năng 5)

**1. Kết quả:** `MISSING FEATURE`

**2. Mức độ:** `MEDIUM`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Hệ thống thiếu chức năng "Từ chối đăng ký phương tiện" (Reject) trên cả backend API và frontend; không có trường lưu trạng thái từ chối và lý do từ chối.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Models/Vehicle.php` (Dòng 18–34)
  ```php
  protected $fillable = [
      'apartment_id',
      'owner_user_id',
      'license_plate',
      'vehicle_category',
      'brand',
      'model',
      'color',
      'registration_certificate_number',
      'vehicle_photo_url',
      'registration_cert_photo_url',
      'monthly_parking_fee',
      'has_electric_charging_subscription',
      'is_active',
      'approved_by',
      'approved_at',
  ];
  ```
- **File 2**: `routes/web.php` (Dòng 554–567)
  - Chỉ có các route:
    - `POST /api/v1/vehicles` (Tạo)
    - `POST /api/v1/vehicles/{id}/approve` (Duyệt)
    - `PATCH /api/v1/vehicles/{id}/toggle-active` (Tạm ngưng / Kích hoạt)
    - `DELETE /api/v1/vehicles/{id}` (Xóa mềm)
    - Hoàn toàn không có route `reject`.
- **File 3**: `resources/js/Pages/Admin/VehicleManagement.tsx` (Dòng 990–1025)
  - Cột thao tác của danh sách phương tiện chỉ có 3 nút: **Duyệt** (`CheckCircle`), **Sửa** (`Pencil`), và **Xóa** (`Trash2`). Không có nút hoặc modal nhập lý do "Từ chối".

**5. Kết quả truy vết:**  
- Khi so sánh với Chức năng 3 (Đăng ký tạm trú / tạm vắng), Chức năng 3 có đầy đủ cặp thao tác Phê duyệt (`approve`) và Từ chối (`reject`) kèm lưu trường `police_status = REJECTED` và lý do từ chối.
- Tại Chức năng 5, database schema của bảng `vehicles` chỉ dùng cờ nhị phân `is_active` (0/1) và cặp trường `approved_by`/`approved_at`. Schema không hề có cột `status` (dạng enum `PENDING`, `APPROVED`, `REJECTED`) cũng như không có cột `rejection_reason`.
- Đây **không phải lỗi code bị vỡ hay logic bị hỏng**, mà là **tính năng Từ chối đăng ký xe chưa từng được thiết kế và cài đặt trong phiên bản hiện tại** (Missing Feature).

**6. Ảnh hưởng:**  
- Khi một đăng ký xe không đủ điều kiện (ví dụ: ảnh giấy tờ xe mờ, sai biển số), Ban quản lý không có cách nào đánh dấu "Từ chối" kèm lý do để cư dân biết, mà chỉ có thể bấm "Xóa" hồ sơ đó khỏi hệ thống.

**7. Khả năng tái hiện:**  
- Không áp dụng (tính năng chưa tồn tại).

**8. Hướng xử lý đề xuất:**  
- Bổ sung migration thêm cột `rejection_reason`, `rejected_by`, `rejected_at` vào bảng `vehicles`.
- Bổ sung API `POST /api/v1/vehicles/{id}/reject` trong `VehicleController` và `VehicleService`.
- Bổ sung nút "Từ chối" kèm dialog nhập lý do trên giao diện `VehicleManagement.tsx`.

**9. Phạm vi ảnh hưởng:**  
- Database migration, `Vehicle.php`, `VehicleController.php`, `VehicleService.php`, `VehicleManagement.tsx`.

**10. Rủi ro khi sửa:**  
- Cần chạy migration thêm cột mới và cập nhật logic đồng bộ hóa đơn (đảm bảo xe bị từ chối không sinh phí gửi xe vào bảng `invoices`).

---

### BUG-07 – Xem Lịch Trực Theo Tuần / Tháng (Chức năng 12)

**1. Kết quả:** `PARTIALLY CONFIRMED`

**2. Mức độ:** `MEDIUM`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Giao diện Xếp lịch trực nhân viên thiếu chế độ xem theo tuần (Week View); frontend chỉ có xem theo tháng.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Http/Controllers/StaffScheduleController.php` (Dòng 67–75)
  ```php
  $filters = [
      'start_date' => $request->query('start_date'),
      'end_date' => $request->query('end_date'),
      'role' => $request->query('role'),
      ...
  ];
  $data = $this->service->getSchedules($filters);
  ```
- **File 2**: `resources/js/Components/ManagementStaffSchedulePanel.tsx` (Dòng 290–305 & 832–860)
  ```tsx
  // Dòng 290-305: Handler điều hướng chỉ cộng/trừ Tháng
  const handlePrev = () => {
    setCurrentDate((prev) => {
      const next = new Date(prev);
      next.setMonth(next.getMonth() - 1);
      return next;
    });
  };

  const handleNext = () => {
    setCurrentDate((prev) => {
      const next = new Date(prev);
      next.setMonth(next.getMonth() + 1);
      return next;
    });
  };
  ```
  ```tsx
  // Dòng 832-860: Chú thích ghi "Bộ chuyển Tuần / Tháng" nhưng chỉ render điều hướng Tháng
  {/* Bộ chuyển Tuần / Tháng */}
  <div className="flex items-center gap-2">
    <button onClick={handlePrev} ... title="Kỳ trước"><ChevronLeft /></button>
    <button onClick={handleToday} ...>Hôm nay</button>
    <button onClick={handleNext} ... title="Kỳ kế tiếp"><ChevronRight /></button>
    <div className="ml-2 font-extrabold text-slate-800 ...">
      <Calendar className="w-4 h-4 text-sky-600" />
      <span>Tháng {currentDate.getMonth() + 1} / {currentDate.getFullYear()}</span>
    </div>
  </div>
  ```

**5. Kết quả truy vết:**  
- **Ở phía Backend**: API `GET /api/v1/management/schedules` nhận tham số `start_date` và `end_date` hoàn toàn linh hoạt. Nếu truyền khoảng 7 ngày, backend xử lý và trả về đúng dữ liệu của tuần đó. Do đó **Backend hoàn toàn không bị lỗi**.
- **Ở phía Frontend**: Component `ManagementStaffSchedulePanel.tsx` chỉ có state `currentDate` và các hàm `handlePrev`, `handleNext` nhảy theo từng bước 1 tháng (`setMonth +/- 1`). Giao diện lưới lịch cũng được render theo 35–42 ô của một tháng hoàn chỉnh. Dù tại dòng 832 lập trình viên có để comment `{/* Bộ chuyển Tuần / Tháng */}`, nhưng thực tế chưa cài đặt nút toggle chuyển đổi giữa chế độ Tuần và Tháng.
- **Kết luận**: Nhận định của báo cáo cũ là `PARTIALLY CONFIRMED` (đúng ở tầng giao diện Frontend, nhưng Backend đã hỗ trợ sẵn).

**6. Ảnh hưởng:**  
- Người quản lý xếp lịch không thể xem cận cảnh lịch trực theo từng tuần (7 ngày) trên giao diện để dễ điều phối ca trực ngắn hạn.

**7. Khả năng tái hiện:**  
- Mở trang Xếp lịch trực trên trình duyệt: Không tìm thấy nút chuyển sang chế độ "Xem theo tuần".

**8. Hướng xử lý đề xuất:**  
- Thêm state `viewMode: 'WEEK' | 'MONTH'` trong `ManagementStaffSchedulePanel.tsx`.
- Thêm nút bấm chuyển đổi giữa "Tuần" và "Tháng" tại thanh điều hướng.
- Khi chọn chế độ "Tuần", tính `startDate` và `endDate` theo Thứ 2 đến Chủ nhật của tuần hiện tại và hiển thị lưới 7 cột ngày.

**9. Phạm vi ảnh hưởng:**  
- Duy nhất file `resources/js/Components/ManagementStaffSchedulePanel.tsx`.

**10. Rủi ro khi sửa:**  
- Hoàn toàn không có rủi ro cho backend hay database.

---

### BUG-08 – Phạm Vi Danh Sách Khách & Gán Căn Hộ Fallback (Chức năng 7)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `MEDIUM`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: (1) Trong `ResidentVisitorService::listVisitors()`, điều kiện lọc biến đổi bất thường: nếu cư dân chưa tạo khách thì thấy khách của cả căn hộ, nhưng ngay khi tự tạo 1 khách thì chỉ thấy khách của mình và làm ẩn khách của các thành viên khác trong nhà; (2) Hàm `createVisitor()` tự gán căn hộ đầu tiên trong database (`apartments.first()`) nếu tài khoản chưa được liên kết căn hộ.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Services/ResidentVisitorService.php` (Dòng 45–75)
  ```php
  $hasOwn = DB::table('visitor_registrations')
      ->where('host_resident_user_id', $userId)
      ->exists();

  if ($hasOwn) {
      $q->where('visitor_registrations.host_resident_user_id', $userId);

      return;
  }

  $apartmentIds = DB::table('residents')
      ->where('user_id', $userId)
      ->where('is_active', 1)
      ->whereNull('deleted_at')
      ->pluck('apartment_id')
      ->toArray();

  if (! empty($apartmentIds)) {
      $hasApt = DB::table('visitor_registrations')
          ->whereIn('apartment_id', $apartmentIds)
          ->exists();

      if ($hasApt) {
          $q->whereIn('visitor_registrations.apartment_id', $apartmentIds);

          return;
      }
  }

  $q->where('visitor_registrations.host_resident_user_id', $userId);
  ```
- **File 2**: `app/Services/ResidentVisitorService.php` (Dòng 269–273)
  ```php
  if (! $apartmentId) {
      // Fallback căn hộ mặc định đầu tiên nếu chưa gán
      $firstApt = DB::table('apartments')->first();
      $apartmentId = $firstApt ? $firstApt->id : null;
  }
  ```

**5. Kết quả truy vết:**  
- **Về phạm vi danh sách khách**: Đoạn code dòng 45–53 kiểm tra: nếu user hiện tại đã từng tạo ít nhất 1 bản ghi khách (`$hasOwn = true`), query sẽ gán `where('host_resident_user_id', $userId)` và kết thúc hàm bằng lệnh `return`. Ngược lại, nếu user chưa từng tạo khách nào (`$hasOwn = false`), query mới tìm các căn hộ của user và lọc `whereIn('apartment_id', $apartmentIds)`.  
  *Hệ quả*: Trong cùng một căn hộ, nếu Vợ đã đăng ký 2 khách, Chồng vừa đăng nhập sẽ thấy 2 khách của Vợ. Nhưng ngay khi Chồng tạo 1 khách của mình, biến `$hasOwn` của Chồng thành `true`, khiến 2 khách của Vợ lập tức biến mất khỏi màn hình của Chồng!
- **Về việc gán fallback căn hộ đầu tiên**: Dòng 269–273 viết: nếu không xác định được `apartment_id` từ input và từ quan hệ cư dân, code tự động truy vấn `DB::table('apartments')->first()`. Nếu một tài khoản mới tạo (chưa gán vào căn hộ nào) gọi API đăng ký khách, giấy mời khách sẽ được tạo và gán vào căn hộ đầu tiên trong tòa nhà (ví dụ căn hộ của người khác).

**6. Ảnh hưởng:**  
- Trải nghiệm người dùng bị sai lệch: Các thành viên trong cùng một gia đình không thể quản lý chung danh sách khách đến thăm nhà mình.
- Dữ liệu sai lệch: Khách của tài khoản chưa xác định căn hộ bị gán nhầm cho căn hộ người khác.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Dùng 2 tài khoản cư dân thuộc cùng 1 căn hộ để tạo khách và kiểm tra danh sách trả về qua API.

**8. Hướng xử lý đề xuất:**  
- Sửa `listVisitors`: Luôn lọc theo căn hộ của cư dân (`whereIn('apartment_id', $apartmentIds)`), cho phép tất cả thành viên trong căn hộ cùng xem danh sách khách của nhà mình.
- Sửa `createVisitor`: Xóa bỏ đoạn fallback `DB::table('apartments')->first()`. Nếu không tìm thấy căn hộ hợp lệ của cư dân, ném `ValidationException` (HTTP 422) thông báo: *"Tài khoản của bạn chưa được liên kết với bất kỳ căn hộ nào."*

**9. Phạm vi ảnh hưởng:**  
- Duy nhất file `app/Services/ResidentVisitorService.php`.

**10. Rủi ro khi sửa:**  
- Rất thấp. Logic trở nên chặt chẽ và an toàn hơn.

---

### BUG-09 – Thêm Lại Cư Dân Đã Bị Xóa Mềm Vào Căn Hộ (Chức năng 2)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `MEDIUM`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Unique index trên bảng `residents` không phân biệt bản ghi đã xóa mềm (`deleted_at`). Khi thêm lại một cư dân đã bị soft-delete vào chính căn hộ cũ, hệ thống ném lỗi `ResidentConflictException` (HTTP 409/500) với thông báo sai thực tế thay vì khôi phục bản ghi.

**4. Bằng chứng trong source code:**
- **File 1**: Kiểm tra trực tiếp index trong database `quanly_toanha_test`:
  ```sql
  Table: residents
  Key_name: uq_resident_apt
  Columns: (user_id, apartment_id) -- Không chứa deleted_at
  ```
- **File 2**: `app/Services/ResidentService.php` (Dòng 213–218)
  ```php
  try {
      $res = Resident::create($data);
  } catch (QueryException $e) {
      if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
          throw new ResidentConflictException('Cư dân này đã được thêm vào căn hộ bởi quản trị viên khác.', 409);
      }
      throw $e;
  }
  ```
- **File 3**: Bằng chứng thực nghiệm từ bộ test của chính dự án (`tests/Feature/Residents/ResidentCreateTest.php` dòng 531–539):
  Khi chạy `test_case_14_soft_deleted_resident_behavior`, output thực tế ghi nhận:
  ```
  TEST CASE 14: SOFT DELETED RESIDENT
  HTTP status: 500
  Response body: {"message":"Cư dân này đã được thêm vào căn hộ bởi quản trị viên khác.","exception":"App\\Services\\ResidentConflictException"}
  ```

**5. Kết quả truy vết:**  
- Trong MySQL, ràng buộc `UNIQUE (user_id, apartment_id)` áp dụng cho mọi hàng. Khi một cư dân bị xóa mềm (`deleted_at = now()`), hàng đó vẫn tồn tại trong bảng.
- Khi quản trị viên thêm lại người đó vào căn hộ:
  - Hàm kiểm tra trùng trước khi lưu bỏ qua bản ghi đã xóa mềm (vì mặc định Eloquent thêm `WHERE deleted_at IS NULL`).
  - Lệnh `Resident::create($data)` thực thi lệnh `INSERT INTO residents ...`.
  - MySQL lập tức ném lỗi vi phạm khóa duy nhất `1062 Duplicate entry`.
  - Khối `catch (QueryException $e)` hiểu nhầm đây là tranh chấp đồng thời giữa 2 admin nên ném ra `ResidentConflictException` kèm thông báo không đúng sự thật: *"Cư dân này đã được thêm vào căn hộ bởi quản trị viên khác."*

**6. Ảnh hưởng:**  
- Không thể thêm lại một cư dân đã từng ở căn hộ này trong quá khứ quay trở lại thuê/ở.
- Quản trị viên nhận thông báo lỗi gây hoang mang vì không có admin nào khác đang thao tác.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Tạo 1 resident, gọi API xóa mềm resident đó, sau đó gọi lại `POST /api/v1/residents` với cùng cặp `user_id` và `apartment_id`. Kết quả trả về HTTP 409/500 lỗi conflict.

**8. Hướng xử lý đề xuất:**  
- Trong `ResidentService::create()`: Trước khi gọi `Resident::create()`, kiểm tra xem có bản ghi đã bị xóa mềm không:
  ```php
  $trashed = Resident::onlyTrashed()->where('user_id', $data['user_id'])->where('apartment_id', $data['apartment_id'])->first();
  if ($trashed) {
      $trashed->restore();
      $trashed->update($data);
      return $trashed;
  }
  ```

**9. Phạm vi ảnh hưởng:**  
- `app/Services/ResidentService.php`.

**10. Rủi ro khi sửa:**  
- Rất thấp. Việc restore bản ghi cũ và cập nhật thông tin mới vừa giải quyết được lỗi 1062, vừa bảo toàn được ID định danh và lịch sử quan hệ.

---

### BUG-10 – Ép Kiểu (int) Trên Khóa Chính UUID Người Thực Hiện (Chức năng 6)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `MEDIUM`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Trong `AccessCardService.php`, tham số `$actorId` bị ép kiểu `(int) $actorId` trước khi truyền vào `QuocTinRealtimeService::emit()`. Vì `users.id` trong hệ thống là UUID chuỗi, việc ép kiểu sang integer làm hỏng định danh người thực hiện trong payload audit/realtime.

**4. Bằng chứng trong source code:**
- **File 1**: `app/Services/AccessCardService.php` (Dòng 172, 224, 257, 296)
  ```php
  // Dòng 172 (createCard):
  QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'CREATED', $card->id, [
      ...
  ], $actorId ? (int) $actorId : null, false);

  // Dòng 224 (updateCard):
  ], $actorId ? (int) $actorId : null, false);

  // Dòng 257 (toggleStatus):
  ], $actorId ? (int) $actorId : null, false);

  // Dòng 296 (deleteCard):
  ], $actorId ? (int) $actorId : null, false);
  ```
- **File 2**: Định nghĩa phương thức trong `app/Services/QuocTinRealtimeService.php` (Dòng 287)
  ```php
  public static function emit(
      string $module,
      string $entity,
      string $action,
      ?string $entityId = null,
      array $extra = [],
      ?string $actorId = null, // <-- Chữ ký hàm nhận ?string
      bool $triggerCooldown = false
  ): void
  ```
- **File 3**: Bảng `users` trong database: Khóa chính `id` là kiểu `CHAR(36)` UUID (ví dụ: `019a8b78-6bd4-7301-962e-de2b4f1a5a46`).

**5. Kết quả truy vết:**  
- Trong PHP, khi ép kiểu một chuỗi UUID sang integer:
  - Nếu UUID bắt đầu bằng chữ số (ví dụ `019a...`), PHP ép thành `19`.
  - Nếu UUID bắt đầu bằng chữ cái (ví dụ `a3f8...`), PHP ép thành `0`.
- Chữ ký hàm `QuocTinRealtimeService::emit()` ở dòng 287 đã khai báo rõ ràng kiểu dữ liệu là `?string $actorId = null`.
- Việc lập trình viên viết `$actorId ? (int) $actorId : null` tại cả 4 hàm trong `AccessCardService.php` là một lỗi gõ nhầm (type casting error), dẫn tới trường `actor_id` trong payload sự kiện realtime của module thẻ RFID luôn bị biến thành các số nguyên vô nghĩa như `0` hoặc `19`.

**6. Ảnh hưởng:**  
- Làm sai lệch thông tin audit trail và realtime log: Không thể truy vết được nhân viên nào vừa thực hiện tạo thẻ, khóa thẻ hoặc xóa thẻ RFID.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Gọi hàm `toggleStatus($cardId, $adminUser->id)` và kiểm tra payload sự kiện SSE phát ra trên kênh `quoc-tin.rfid-cards`. Trường `actor_id` nhận được là `0` thay vì chuỗi UUID của Admin.

**8. Hướng xử lý đề xuất:**  
- Trong `AccessCardService.php` tại 4 dòng 172, 224, 257, 296: Bỏ ép kiểu `(int)`, truyền trực tiếp `$actorId`.

**9. Phạm vi ảnh hưởng:**  
- Duy nhất file `app/Services/AccessCardService.php`.

**10. Rủi ro khi sửa:**  
- Hoàn toàn không có rủi ro.

---

### BUG-11 – Thống Kê KPI Tính Trên Trang Hiện Tại Thay Vì Toàn Bộ Dữ Liệu (Chức năng 2, 3, 5)

**1. Kết quả:** `CONFIRMED`

**2. Mức độ:** `LOW`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Các thẻ KPI phụ trên giao diện Chức năng 2, 3 và 5 chỉ tính toán bằng cách `.filter()` trên mảng dữ liệu của trang hiện tại (15 bản ghi) thay vì toàn bộ dữ liệu, khiến số liệu thống kê bị sai lệch và thay đổi khi chuyển trang.

**4. Bằng chứng trong source code:**
- **File 1**: `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (Dòng 360–367 & 737–767)
  ```tsx
  // Dòng 360-367:
  const stats = useMemo(() => {
    const stayCount = registrations.filter((r) => r.registration_type === 'TEMPORARY_STAY').length;
    const absCount = registrations.filter((r) => r.registration_type === 'TEMPORARY_ABSENCE').length;
    const pendingCount = registrations.filter((r) => r.police_status === 'PENDING_POLICE_SUBMISSION' || r.police_status === 'PENDING').length;
    const approvedCount = registrations.filter((r) => r.police_status === 'APPROVED').length;
    const rejectedCount = registrations.filter((r) => r.police_status === 'REJECTED').length;
    return { stayCount, absCount, pendingCount, approvedCount, rejectedCount };
  }, [registrations]);
  ```
  ```tsx
  // Dòng 737: Thẻ 1 hiển thị tổng số toàn bộ database
  <p className="text-2xl font-black text-slate-900 mt-1">{totalCount}</p>

  // Dòng 747: Thẻ 2 (Tạm Trú) chỉ đếm trên mảng 15 phần tử của trang hiện tại!
  <p className="text-2xl font-black text-emerald-600 mt-1">{stats.stayCount}</p>
  ```
- **File 2**: `resources/js/Pages/Admin/ResidentManagement.tsx` (Dòng 240–248)
  ```tsx
  const headsCount = residents.filter(r => r.is_head_of_household).length;
  const ownersCount = residents.filter(r => r.resident_type === 'OWNER').length;
  const tenantsCount = residents.filter(r => r.resident_type === 'TENANT').length;
  const membersCount = residents.filter(r => r.resident_type === 'FAMILY_MEMBER' || r.resident_type === 'DEPENDENT').length;
  ```
- **File 3**: `resources/js/Pages/Admin/VehicleManagement.tsx` (Dòng 856–865)
  ```tsx
  const motorbikes = vehicles.filter((v) => v.vehicle_category === 'MOTORBIKE').length;
  const cars = vehicles.filter((v) => v.vehicle_category === 'CAR').length;
  ```

**5. Kết quả truy vết:**  
- API backend trả về phân trang 15 bản ghi/trang (`registrations`, `residents`, `vehicles`).
- Thẻ đầu tiên ("Tổng Số") lấy từ `pagination.total` (ví dụ: `150` hồ sơ).
- Nhưng các thẻ phân loại bên cạnh lại dùng hàm JavaScript `.filter()` trên mảng của trang hiện tại (tối đa 15 phần tử).
- Khi người dùng bấm sang Trang 2 hoặc Trang 3, các thẻ "Tạm trú", "Tạm vắng", "Chủ hộ", "Xe máy", "Ô tô" lập tức thay đổi con số theo 15 dòng của trang mới. Tổng các thẻ thành phần không bao giờ bằng thẻ Tổng số nếu dữ liệu vượt quá 1 trang.

**6. Ảnh hưởng:**  
- Lỗi hiển thị giao diện (UI display inconsistency): Gây hiểu nhầm cho người quản lý về số lượng hồ sơ thực tế trong tòa nhà.

**7. Khả năng tái hiện:**  
- Tái hiện 100%: Khi có nhiều hơn 15 hồ sơ trong hệ thống, quan sát thẻ KPI ở Trang 1 và Trang 2: các con số thống kê thành phần bị nhảy sai lệch.

**8. Hướng xử lý đề xuất:**  
- Backend: Trong API `index()` của Resident, TemporaryRegistration và Vehicle, bổ sung trả về trường `summary` (chạy query `selectRaw count` gom nhóm theo trạng thái/loại), tương tự như cách `AccessCardService` (Chức năng 6) và `ReceptionParcelService` (Chức năng 9) đã làm.
- Frontend: Đọc số liệu từ `res.summary` thay vì `.filter()` trên mảng trang hiện tại.

**9. Phạm vi ảnh hưởng:**  
- `ResidentManagement.tsx`, `TemporaryRegistrationManagement.tsx`, `VehicleManagement.tsx`, kèm các Service backend tương ứng.

**10. Rủi ro khi sửa:**  
- Rất thấp.

---

### BUG-12 – Hiển Thị Bảng Đăng Ký Tạm Trú / Tạm Vắng Trên Các Màn Hình Responsive (Chức năng 3)

**1. Kết quả:** `PARTIALLY CONFIRMED`

**2. Mức độ:** `LOW`

**3. Mô tả nhận định ban đầu:**  
Báo cáo cũ cho rằng: Tại độ phân giải 1024px, 768px, 390px, bảng dữ liệu bị cắt chữ (`line-clamp-3`, `truncate`) và ẩn `<table>` để chuyển sang dạng thẻ (Card View), không đáp ứng yêu cầu đọc đầy đủ thông tin mà không được ẩn cột.

**4. Bằng chứng trong source code:**
- **File**: `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx`
  - Dòng 861: `<div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hidden lg:block">` (Bảng `<table>` bị ẩn dưới màn hình 1024px `lg`).
  - Dòng 952 (Cột Lý do trên Desktop Table):
    ```tsx
    <div className="text-xs text-slate-600 break-words line-clamp-3 leading-relaxed" title={item.reason}>
      {item.reason || '—'}
    </div>
    ```
  - Dòng 1092: `<div className="space-y-3 block lg:hidden">` (Dưới 1024px, 768px, 390px tự động chuyển sang layout Card dọc).
  - Dòng 1121 (Tiêu đề cư dân trên Card View):
    ```tsx
    <div className="font-bold text-slate-900 text-sm leading-tight truncate">
      {user?.full_name || 'N/A'}
    </div>
    ```

**5. Kết quả truy vết:**  
- **Tại 1920px, 1440px, 1280px**: Bảng desktop hiển thị đầy đủ 5 cột theo tỷ lệ `<colgroup>` (`28%`, `18%`, `21%`, `18%`, `15%`). Bảng có định dạng ngày giờ chuẩn `dd/MM/yyyy`, không sinh thanh cuộn ngang, tất cả các trường chính đều đọc được rõ ràng.
- **Tại 1024px**: Do layout trang Admin có Sidebar bên trái chiếm ~260px, diện tích thực của bảng bị co lại; các lý do dài hơn 3 dòng bị áp `line-clamp-3` nên bị ẩn phần đuôi bằng dấu ba chấm `...` (chỉ xem được khi hover chuột qua thuộc tính `title`).
- **Tại 768px và 390px**: Lập trình viên chủ động dùng giải pháp Responsive Card View (`block lg:hidden`), giấu thẻ `<table>` để chuyển toàn bộ thông tin của từng hồ sơ thành một thẻ dọc (Card). Trên các thẻ này, tất cả thông tin quan trọng (Tên, Căn hộ, CCCD, SĐT, Loại, Ngày bắt đầu/kết thúc, Lý do, Trạng thái, Mã Công An) đều được hiển thị đầy đủ, ngoại trừ tên cư dân có class `truncate`.
- **Kết luận**: Nhận định của báo cáo cũ là `PARTIALLY CONFIRMED`. Thiết kế Responsive hiện tại không bị lỗi vỡ khung hay lỗi thanh cuộn ngang, mà là lựa chọn chuyển từ Table sang Card View trên thiết bị di động, kèm một vài class giới hạn dòng (`line-clamp-3`, `truncate`).

**6. Ảnh hưởng:**  
- Ở màn hình 1024px, người dùng phải hover chuột vào tooltip để đọc hết lý do nếu lý do dài quá 3 dòng.
- Ở màn hình mobile 390px, tên cư dân quá dài có thể bị cắt bớt bằng dấu ba chấm.

**7. Khả năng tái hiện:**  
- Bật DevTools (F12) chuyển chế độ Responsive ở 1024px và 390px với dữ liệu có lý do dài và tên dài.

**8. Hướng xử lý đề xuất:**  
- Bỏ class `line-clamp-3` tại dòng 952 và `truncate` tại dòng 1121, thay bằng `break-words whitespace-normal` để chữ tự động xuống dòng đầy đủ.

**9. Phạm vi ảnh hưởng:**  
- Duy nhất file `TemporaryRegistrationManagement.tsx`.

**10. Rủi ro khi sửa:**  
- Hoàn toàn không có rủi ro.

---

## BẢNG TỔNG HỢP XÁC MINH 12 LỖI & KHUYẾN NGHỊ

| BUG | Tên lỗi | Kết quả xác minh | Mức độ thực tế | Có cần sửa? | Ưu tiên |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **BUG-01** | Lỗ hổng bảo mật 10 API Bưu phẩm & Lộ mã PIN/QR cho Cư dân | `CONFIRMED` | **CRITICAL** | **CÓ** | **Ưu tiên 1 (Khẩn cấp)** |
| **BUG-02** | Mật khẩu dự phòng hardcode, lộ `debug_otp` & demo token production | `CONFIRMED` | **CRITICAL** | **CÓ** | **Ưu tiên 2 (Khẩn cấp)** |
| **BUG-03** | Cư dân bị chặn hoàn toàn (403) ở Đăng ký tạm trú / tạm vắng | `CONFIRMED` | **HIGH** | **CÓ** | **Ưu tiên 3 (Cao)** |
| **BUG-04** | RBAC Matrix permissions bị bỏ qua tại các Controller nghiệp vụ | `CONFIRMED` | **HIGH** | **CÓ** | **Ưu tiên 4 (Cao)** |
| **BUG-05** | Bỏ qua phân quyền trên các kênh SSE Realtime (`\|\| true`, `=> true`) | `CONFIRMED` | **HIGH** | **CÓ** | **Ưu tiên 5 (Cao)** |
| **BUG-06** | Thiếu quy trình Từ chối đăng ký phương tiện (Reject) | `MISSING FEATURE` | **MEDIUM** | **CÓ** (Bổ sung tính năng) | **Ưu tiên 7 (Trung bình)** |
| **BUG-07** | Thiếu chế độ Xem lịch trực tuần (Week View) trên Frontend | `PARTIALLY CONFIRMED` | **MEDIUM** | **CÓ** (Hoàn thiện UI) | **Ưu tiên 8 (Trung bình)** |
| **BUG-08** | Lọc danh sách khách không nhất quán & Fallback căn hộ đầu tiên | `CONFIRMED` | **MEDIUM** | **CÓ** | **Ưu tiên 6 (Trung bình)** |
| **BUG-09** | Lỗi 1062 khi thêm lại cư dân đã bị xóa mềm vào căn hộ | `CONFIRMED` | **MEDIUM** | **CÓ** | **Ưu tiên 9 (Trung bình)** |
| **BUG-10** | Ép kiểu `(int) $actorId` làm hỏng UUID người thực hiện ở thẻ RFID | `CONFIRMED` | **MEDIUM** | **CÓ** | **Ưu tiên 10 (Trung bình)** |
| **BUG-11** | KPI phụ tính trên mảng trang hiện tại (15 bản ghi) thay vì toàn bộ DB | `CONFIRMED` | **LOW** | **CÓ** (Cải thiện UI/UX) | **Ưu tiên 11 (Thấp)** |
| **BUG-12** | Cắt bớt văn bản (`line-clamp-3`, `truncate`) trên màn hình responsive | `PARTIALLY CONFIRMED` | **LOW** | **NÊN** (Cải thiện UI/UX) | **Ưu tiên 12 (Thấp)** |

---

## TỔNG KẾT BÁO CÁO

### 1. Thống kê trạng thái xác minh (12/12 BUG)
- **CONFIRMED**: **9** lỗi (`BUG-01`, `BUG-02`, `BUG-03`, `BUG-04`, `BUG-05`, `BUG-08`, `BUG-09`, `BUG-10`, `BUG-11`).
- **PARTIALLY CONFIRMED**: **2** lỗi (`BUG-07`, `BUG-12`) — do backend hoặc giải pháp responsive đã có một phần nhưng giao diện chưa triệt để.
- **MISSING FEATURE**: **1** vấn đề (`BUG-06`) — tính năng Từ chối đăng ký xe chưa từng được thiết kế trong DB schema/API, không phải lỗi code bị vỡ.
- **FALSE POSITIVE**: **0** vấn đề.
- **UNVERIFIED**: **0** vấn đề (tất cả 12 lỗi đều được truy vết và xác minh trực tiếp bằng mã nguồn thực tế).

### 2. Danh sách lỗi nguy hiểm nhất cần xử lý trước
1. **BUG-01 (CRITICAL)**: Phải gắn ngay middleware `auth.bearer` và chặn quyền cư dân xem mã PIN/QR của căn hộ khác để tránh nguy cơ thất thoát kiện hàng và lộ dữ liệu cá nhân.
2. **BUG-02 (CRITICAL)**: Phải bọc điều kiện môi trường `app()->environment('local', 'testing')` cho cơ chế mật khẩu fallback trong `AuthController::login` và `ReceptionDashboardController` để ngăn chặn backdoor chiếm đoạt quyền Admin trên production.
3. **BUG-05 (HIGH)**: Phải gỡ bỏ ngay `|| true` ở kênh SSE `quoc-tin.rfid-cards` và thắt chặt quyền các kênh realtime để tránh rò rỉ dữ liệu vận hành tòa nhà.

### 3. Danh sách lỗi có thể xem xét xử lý sau
- `BUG-06`, `BUG-07`: Là các hạng mục hoàn thiện thêm tính năng mới (thêm luồng Từ chối đăng ký xe, thêm nút bấm Xem tuần cho lịch trực).
- `BUG-11`, `BUG-12`: Là các tinh chỉnh hiển thị số liệu thống kê và chữ hiển thị trên giao diện người dùng.

---
*Báo cáo được hoàn thành vào lúc 2026-10-30. Toàn bộ source code của dự án được bảo đảm giữ nguyên vẹn 100%, không có bất kỳ file mã nguồn nào bị thay đổi.*

