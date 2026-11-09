# BUG-04: BÁO CÁO PHÂN TÍCH LỖI RBAC MATRIX & BACKEND KIỂM TRA QUYỀN KHÔNG NHẤT QUÁN
**Hệ thống**: Quản lý Tòa nhà & Cư dân (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Chức năng**: Phân quyền tài khoản (RBAC Matrix - Chức năng 1) & Tích hợp liên chức năng (Chức năng 2–13)  
**Phân loại mức độ**: **HIGH / CRITICAL** (Broken Access Control & Dynamic RBAC Bypass)  
**Chế độ thực hiện**: **STAGE 1 – REVIEW ONLY** (Chỉ rà soát, đánh giá toàn diện; tuyệt đối không sửa code production, test, migration, seeder hay database)  
**Ngày lập**: 06/11/2026  

---

## 1. TÓM TẮT ĐIỀU HÀNH (EXECUTIVE SUMMARY)

Báo cáo phân tích chuyên sâu toàn bộ kiến trúc phân quyền RBAC (Role-Based Access Control) từ cơ sở dữ liệu, model Eloquent, service xử lý nghiệp vụ, middleware phân quyền, các controller nghiệp vụ chức năng 2–13 cho đến giao diện người dùng React và các kênh SSE Realtime.

### Kết quả xác minh nguyên nhân chính (Root Cause Status)
- **CONFIRMED (Xác nhận 100%)**: Backend đang tồn tại **sự đứt gãy kiến trúc nghiêm trọng (Architectural Disconnect)** giữa động cơ RBAC Matrix tại Chức năng 1 và các Controller/Service tại Chức năng 2–13:
  1. **Động cơ RBAC tại Chức năng 1 được thiết kế bài bản và hoàn chỉnh**: Đã xây dựng đầy đủ các bảng dữ liệu `roles`, `permissions`, `role_permissions`, `user_roles`, `user_sessions`, `audit_logs`; hỗ trợ cơ chế phân quyền theo vai trò hệ thống lẫn phân quyền tùy biến riêng cho từng tài khoản người dùng (`CUSTOM_USER_{userId}`); hỗ trợ chuẩn hóa ma trận 7 nhóm quyền (`create`, `delete`, `edit`, `download`, `upload`, `view`, `special`); có cơ chế xóa bộ nhớ đệm cache và phát sự kiện đồng bộ Realtime.
  2. **Các Controller nghiệp vụ (Chức năng 2–13) bỏ qua RBAC Matrix**: Hầu hết các controller không gọi `hasPermission()` hoặc middleware `CheckPermission`, mà sử dụng kiểm tra cứng mã vai trò bằng `hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', ...])`.
  3. **Hệ quả thực tế**:
     - Khi Quản trị viên vào màn hình RBAC Matrix thu hồi quyền xóa (`DELETE`) hoặc quyền duyệt (`APPROVE`) của một tài khoản Quản lý hoặc Nhân viên: Giao diện React có thể ẩn nút thao tác, nhưng khi người dùng gửi HTTP request trực tiếp qua Postman, cURL hoặc API call, Backend **vẫn cho phép thực hiện thành công** do `$user->hasRole(...)` trả về `true`.
     - Khi Quản trị viên cấp thêm quyền xem (`VIEW`) hoặc quyền người đặc biệt (`SYSTEM:SPECIAL`) cho một vai trò khác (ví dụ Kế toán `ACCOUNTANT` xem danh sách xe hoặc cư dân): Backend **từ chối 403 Forbidden** vì controller chỉ chấp nhận danh sách role được hardcode cố định trong mã nguồn.
     - Một số controller tài chính và hạ tầng (`InvoiceManagementController`, `InvoiceGenerationController`, `BuildingStructureController`) **hoàn toàn không có bất kỳ kiểm tra phân quyền nào** ở cấp Controller; bất kỳ tài khoản nào đăng nhập hợp lệ (kể cả cư dân) đều có thể gọi API quản trị.

---

## 2. KIỂM TRA CƠ CHẾ RBAC HIỆN TẠI

### 2.1. Cấu trúc Bảng dữ liệu và Quan hệ Eloquent

Hệ thống lưu trữ RBAC trong 5 bảng quan hệ cốt lõi:
1. `roles`: Lưu danh mục vai trò (`id` UUID, `role_code` VARCHAR(50) UNIQUE, `role_name`, `description`, `is_system_role` BOOLEAN, `deleted_at`).
2. `permissions`: Lưu danh mục quyền hạn (`id` UUID, `module` VARCHAR(50), `permission_code` VARCHAR(100) UNIQUE, `permission_name`, `description`).
3. `role_permissions`: Bảng trung gian n-n giữa `roles` và `permissions` (`id` UUID, `role_id`, `permission_id`).
4. `user_roles`: Bảng trung gian n-n giữa `users` và `roles` (`id` UUID, `user_id`, `role_id`, `is_primary` BOOLEAN, `assigned_at`, `assigned_by`).
5. `audit_logs`: Bảng lưu vết kiểm toán (`table_name`, `record_id`, `action`, `performed_by_user_id`, `old_data`, `new_data`).

### 2.2. Cơ chế phân quyền riêng cho từng User (`CUSTOM_USER_{id}`)

Hệ thống sở hữu cơ chế tùy biến phân quyền riêng cho từng tài khoản rất linh hoạt:
- Khi Admin cấu hình phân quyền riêng cho tài khoản tại màn hình RBAC, hệ thống tự động sinh một vai trò ẩn có mã:  
  `CUSTOM_USER_` + `12 ký tự hex của user_id` (ví dụ: `CUSTOM_USER_3a8f1b2c4d5e`).
- Gán vai trò này vào `user_roles` và đồng bộ chính xác các permission được chọn vào `role_permissions`.
- Tại `app/Models/User.php` (`getAllPermissions()` dòng 147–160):
  ```php
  $customRole = $this->roles()->where('role_code', 'LIKE', 'CUSTOM_USER_%')->first();
  if ($customRole) {
      $permissions = Permission::query()
          ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
          ->where('role_permissions.role_id', $customRole->id)
          ->pluck('permissions.permission_code')
          ->unique()->values()->toArray();
      $this->cachedPermissions = $permissions;
      return $this->cachedPermissions;
  }
  ```
- Nếu tài khoản có vai trò tùy chỉnh riêng, `getAllPermissions()` chỉ lấy danh sách quyền trong vai trò riêng này, ghi đè toàn bộ quyền của các nhóm vai trò thông thường.
- Khi người dùng bấm "Khôi phục mặc định" (`resetUserPermissions`), vai trò riêng bị xóa và người dùng quay lại thừa hưởng quyền của các vai trò gốc.

### 2.3. Luồng kiểm tra quyền `hasPermission()` tại `app/Models/User.php`

Phương thức `hasPermission(string $permission): bool` (dòng 188–210):
1. **Tier 1 - Super Admin Bypass**:  
   `if ($this->isSuperAdmin()) return true;`  
   Quản trị viên cấp cao (`SUPER_ADMIN`, `SUPER_ADMI`) có toàn quyền tuyệt đối trên toàn hệ thống.
2. **Tier 2 - Kiểm tra trực tiếp mã quyền**:  
   So sánh mã quyền dạng chữ hoa (`strtoupper(trim($permission))`) với danh sách `getAllPermissions()`.
3. **Tier 3 - Hỗ trợ quyền người đặc biệt (`special`)**:  
   Nếu kiểm tra `'SPECIAL'`, hàm sẽ trả về `true` nếu người dùng có bất kỳ quyền nào: `SYSTEM:SPECIAL`, `USER:SPECIAL` hoặc mã quyền kết thúc bằng `:SPECIAL`.

### 2.4. Middleware `CheckPermission` (`app/Http/Middleware/CheckPermission.php`)

- Định danh alias: `'permission' => CheckPermission::class` trong `bootstrap/app.php`.
- Tự động bỏ qua kiểm tra nếu `$user->isSuperAdmin()`.
- Hỗ trợ kiểm tra đa quyền với toán tử OR bằng dấu gạch đứng `|` (ví dụ `permission:USER:UPDATE|ROLE:ASSIGN_PERMISSION`).
- Trả về mã lỗi chuẩn `401 UNAUTHORIZED` nếu chưa đăng nhập, hoặc `403 FORBIDDEN` kèm thông báo rõ ràng mã quyền bị thiếu nếu không đủ thẩm quyền.

---

## 3. PHÂN TÍCH MA TRẬN 7 NHÓM QUYỀN CHUẨN HÓA

Tại `resources/js/Pages/Admin/RbacManagement.tsx` (dòng 437–530), giao diện RBAC Matrix chuẩn hóa 7 hành động cốt lõi cho mọi phân hệ:

| STT | Hành động (Core Action) | Key UI | Hậu tố mã quyền chuẩn | Mã đại diện mẫu | Ý nghĩa nghiệp vụ |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **1** | **Thêm** | `create` | `:CREATE` | `RESIDENT:CREATE`, `VEHICLE:CREATE`, `USER:CREATE` | Thêm mới bản ghi, tạo đối tượng trong module |
| **2** | **Xóa** | `delete` | `:DELETE` | `RESIDENT:DELETE`, `VEHICLE:DELETE`, `USER:DELETE` | Xóa, hủy hoặc vô hiệu hóa bản ghi |
| **3** | **Sửa** | `edit` | `:UPDATE` / `:EDIT` | `RESIDENT:UPDATE`, `VEHICLE:UPDATE`, `USER:UPDATE` | Chỉnh sửa, cập nhật thông tin trong module |
| **4** | **Tải về** | `download` | `:DOWNLOAD` / `:EXPORT` | `REPORT:EXPORT`, `VEHICLE:DOWNLOAD`, `USER:DOWNLOAD` | Tải xuống, xuất dữ liệu Excel/PDF/CSV |
| **5** | **Tải lên** | `upload` | `:UPLOAD` / `:IMPORT` | `USER:UPLOAD`, `VEHICLE:UPLOAD`, `REPORT:UPLOAD` | Tải lên tệp tin, nhập dữ liệu hàng loạt |
| **6** | **Xem** | `view` | `:VIEW` / `:READ` | `RESIDENT:VIEW`, `VEHICLE:VIEW`, `INVOICE:VIEW` | Tra cứu, xem danh sách và chi tiết dữ liệu |
| **7** | **Người đặc biệt** | `special` | `:SPECIAL` / `SPECIAL` | `SYSTEM:SPECIAL`, `USER:SPECIAL`, `VEHICLE:SPECIAL` | Thẩm quyền quản trị đặc thù / phê duyệt ngoại lệ |

Ngoài 7 quyền cốt lõi, ma trận còn hỗ trợ các **Quyền mở rộng theo nghiệp vụ chuyên biệt (Extended Permissions)**:
- `USER:ASSIGN_ROLE`: Gán vai trò cho tài khoản.
- `ROLE:ASSIGN_PERMISSION`: Cấu hình quyền hạn vai trò.
- `AMENITY:CONFIG_SLOT`: Cấu hình khung giờ và ngày bảo trì.
- `AMENITY:BOOK`: Đặt chỗ tiện ích.
- `INVOICE:APPROVE`: Phê duyệt phát hành hóa đơn.
- `INVOICE:PAY`: Xác nhận thanh toán hóa đơn.
- `TICKET:RESOLVE`: Nghiệm thu và đóng sự cố kỹ thuật.
- `TICKET:ASSIGN`: Phân công kỹ thuật viên.
- `VISITOR:CHECKIN`: Lễ tân check-in cấp thẻ cho khách.
- `VEHICLE:APPROVE`: Duyệt xe và kích hoạt thu phí.

---

## 4. BẢNG THỐNG KÊ CHI TIẾT CÁC VỊ TRÍ VI PHẠM PHÂN QUYỀN TRÊN BACKEND

Dưới đây là danh sách toàn diện các Controller và Service thuộc Chức năng 2–13 đang vi phạm kiểm tra quyền, kèm dẫn chứng dòng code cụ thể:

| Phân hệ / Chức năng | File mã nguồn | Hàm vi phạm & Dòng code | Hiện trạng vi phạm (Bug Pattern) | Quyền RBAC đúng cần kiểm tra |
| :--- | :--- | :--- | :--- | :--- |
| **Chức năng 2**: Cư dân & Hộ khẩu | `app/Http/Controllers/ResidentController.php` | `checkAdminAuthorization()` (L35–37) | Hardcode `$user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])`. Thu hồi quyền xóa/sửa trên RBAC Matrix không có tác dụng; vai trò khác được cấp `RESIDENT:VIEW` vẫn bị 403. | `RESIDENT:VIEW`, `RESIDENT:CREATE`, `RESIDENT:UPDATE`, `RESIDENT:DELETE` |
| **Chức năng 2**: Tòa nhà, Tầng, Căn hộ | `app/Http/Controllers/BuildingStructureController.php` | Toàn bộ các action CRUD (L97–503) | **Hoàn toàn không có kiểm tra quyền**. Bất kỳ user nào đăng nhập (kể cả cư dân) đều gọi được API tạo tầng, tạo căn hộ, xóa căn hộ, sinh căn hộ hàng loạt. | `BUILDING:VIEW`, `BUILDING:MANAGE` (hoặc `APARTMENT:CREATE`, `APARTMENT:UPDATE`, `APARTMENT:DELETE`) |
| **Chức năng 3**: Tạm trú / Tạm vắng | `app/Http/Controllers/TemporaryRegistrationController.php` | `isUserAdmin()` (L32–34) | Hardcode `$user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])`. Bỏ qua quyền duyệt/xóa trong RBAC Matrix. | `TEMP_REG:VIEW`, `TEMP_REG:APPROVE`, `TEMP_REG:DELETE` |
| **Chức năng 4**: Cấp phát tài khoản | `app/Http/Controllers/AccountProvisioningController.php` | `checkAdminAuthorization()` (L39–42) | Sử dụng `hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'ADMIN'])` làm cửa sau (bypass). Cho phép user có `USER:CREATE` làm mọi thao tác gồm xem danh sách, xuất log. | `USER:VIEW`, `USER:CREATE`, `USER:DOWNLOAD` |
| **Chức năng 5**: Đăng ký xe | `app/Http/Controllers/VehicleController.php` | `checkAdminAuthorization()` (L35–38) & các action (L56, 79, 128, 163, 201, 226, 254) | 1. Hardcode role cho phép cả `SECURITY_GUARD` và `RECEPTIONIST`.<br>2. Tất cả action đều gọi `checkAdminAuthorization($request)` mà **không truyền tham số permission** (mặc định null).<br>3. Fallback `VEHICLE:VIEW` cho phép người chỉ có quyền xem xóa hoặc duyệt được xe! | `VEHICLE:VIEW`, `VEHICLE:CREATE`, `VEHICLE:UPDATE`, `VEHICLE:DELETE`, `VEHICLE:APPROVE` |
| **Chức năng 6**: Thẻ RFID | `app/Http/Controllers/AccessCardController.php` | `checkAuthorization()` (L35–37) | Hardcode `hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD'])`. Nhân viên bảo vệ có toàn quyền xóa thẻ, gán lại căn hộ mà không cần quyền trong RBAC. | `RFID:VIEW`, `RFID:CREATE`, `RFID:UPDATE`, `RFID:DELETE` (hoặc `ACCESS_CARD:*`) |
| **Chức năng 7**: Khách viếng thăm (Lễ tân) | `app/Services/ReceptionVisitorService.php` | `assertStaffAuthorized()` (L33–54) | Kiểm tra `ALLOWED_STAFF_ROLES`. Nếu trúng vai trò thì return ngay lập tức mà không xét quyền `VISITOR:CHECKIN` hay `VISITOR:VIEW`. | `VISITOR:VIEW`, `VISITOR:CHECKIN` |
| **Chức năng 7**: Khách viếng thăm (Cư dân) | `app/Services/ResidentVisitorService.php` | `listVisitors()` (L26–35) | Hardcode `$isAdminOrStaff = $user->hasRole([...])` mở toàn bộ phạm vi xem khách cả tòa nhà cho bất kỳ nhân viên nào mà không cần quyền. | `VISITOR:VIEW` |
| **Chức năng 8**: Dashboard Tiếp tân | `app/Services/ReceptionDashboardService.php` | `getDashboardMetrics()` (L25–30) | Hardcode danh sách staff roles, không kiểm tra quyền xem tổng quan tiếp tân. | `RECEPTION:VIEW` (hoặc `REPORT:VIEW`) |
| **Chức năng 9 & 11**: Bưu phẩm | `app/Services/ReceptionParcelService.php` | `assertStaffAuthorized()` (L820–840) | Kiểm tra `ALLOWED_STAFF_ROLES`. Nếu trúng thì thoát kiểm tra, khiến bước kiểm tra quyền `PARCEL:CHECKOUT`, `PARCEL:RECEIVE`, `PARCEL:DELETE` (L843–853) trở thành vô nghĩa. | `PARCEL:VIEW`, `PARCEL:RECEIVE`, `PARCEL:CHECKOUT`, `PARCEL:UPDATE`, `PARCEL:DELETE` |
| **Chức năng 10**: Hóa đơn & Thu phí | `app/Http/Controllers/InvoiceManagementController.php` & `InvoiceGenerationController.php` | Toàn bộ controller (L16–171, L18–104) | **Hoàn toàn không có kiểm tra quyền**. Cư dân hoặc người dùng vãng lai có bearer token có thể chạy sinh hóa đơn hàng loạt, hủy hóa đơn tòa nhà, gửi thông báo nhắc nợ toàn bộ cư dân. | `INVOICE:VIEW`, `INVOICE:CREATE`, `INVOICE:UPDATE`, `INVOICE:DELETE`, `INVOICE:APPROVE` |
| **Chức năng 12**: Lịch trực nhân viên | `app/Http/Controllers/StaffScheduleController.php` | `authorizeManager()` (L30–35), `authorizeViewer()` (L50–55) | 100% kiểm tra `hasRole()`. Thu hồi quyền xếp lịch trên RBAC Matrix không có tác dụng. | `STAFF_SCHEDULE:VIEW`, `STAFF_SCHEDULE:CREATE`, `STAFF_SCHEDULE:UPDATE`, `STAFF_SCHEDULE:DELETE` |
| **Chức năng 13**: Biểu phí dịch vụ | `app/Http/Controllers/ServicePricingController.php` | `checkAdminAuthorization()` (L26–42), `index()` (L58) | 1. Bỏ qua kiểm tra nếu token có tiền tố `smart_token_` (L26–28).<br>2. Bỏ qua kiểm tra nếu có role `SUPER_ADMIN`, `BUILDING_MANAGER`, `ACCOUNTANT` (L39).<br>3. `index()` hoàn toàn không gọi hàm kiểm tra. | `PRICING:VIEW`, `PRICING:CREATE`, `PRICING:UPDATE`, `PRICING:DELETE` (hoặc `INVOICE:*`) |

---

## 5. PHÂN TÍCH LỖ HỔNG BẢO MẬT & RỦI RO NGHIỆP VỤ

### 5.1. Lỗ hổng Thu hồi quyền "ảo" (Ghost Revocation / False Sense of Security)
- **Kịch bản**: Quản trị viên nghi ngờ một nhân viên Ban Quản Lý (vai trò `BUILDING_MANAGER`) có hành vi sai phạm, nên vào trang **Phân quyền tài khoản (RBAC Matrix)** bỏ tích chọn các quyền:
  - `RESIDENT:DELETE` (Xóa nhân khẩu)
  - `VEHICLE:DELETE` (Xóa phương tiện)
  - `INVOICE:DELETE` (Xóa/Hủy hóa đơn)
- **Thực tế xảy ra**:
  - Tại giao diện Web: Các nút bấm "Xóa" biến mất do hook `usePermission` kiểm tra `can('RESIDENT:DELETE') === false`.
  - Tại Backend API: Kẻ tấn công mở Postman/Fiddler gửi trực tiếp lệnh `DELETE /api/v1/residents/{id}` kèm token của nhân viên đó.
  - Kết quả: `ResidentController::checkAdminAuthorization()` kiểm tra `$user->hasRole(['BUILDING_MANAGER'])` -> Trả về `true`! Bản ghi cư dân bị xóa vĩnh viễn khỏi database. Thao tác thu hồi quyền của Quản trị viên hoàn toàn bị vô hiệu hóa!

### 5.2. Lỗ hổng Leo thang đặc quyền theo chiều ngang & chéo vai trò (Privilege Escalation)
- Tại `AccessCardController` và `VehicleController`:
  Cả nhân viên `SECURITY_GUARD` (Bảo vệ) và `RECEPTIONIST` (Lễ tân) đều được gom chung vào điều kiện `$user->hasRole(...)`.
  Một nhân viên bảo vệ chỉ được cấp quyền tra cứu xe tại chốt cổng hoàn toàn có thể gửi request gọi API `POST /api/v1/vehicles/{id}/approve` để tự duyệt xe miễn phí cho căn hộ quen biết, hoặc `DELETE /api/v1/rfid-cards/{id}` để hủy thẻ từ cư dân.

### 5.3. Nguy cơ rò rỉ và can thiệp tài chính trên Phân hệ Hóa đơn (Chức năng 10)
- Tuyến đường `/api/v1/invoices*` chỉ được bảo vệ bởi middleware `auth.bearer`.
- Các controller `InvoiceManagementController` và `InvoiceGenerationController` không chứa bất kỳ câu lệnh kiểm tra quyền hay kiểm tra vai trò nào.
- Một tài khoản Cư dân thông thường sau khi đăng nhập có thể:
  1. Gửi `GET /api/v1/invoices` để xem danh sách hóa đơn, nợ đọng, số điện thoại, chi tiết tiền sử dụng điện nước của tất cả các căn hộ trong tòa nhà.
  2. Gửi `POST /api/v1/invoices/{id}/cancel` để hủy bỏ nghĩa vụ thanh toán của bất kỳ hóa đơn nào.
  3. Gửi `POST /api/v1/invoices/batch/generate` để kích hoạt tiến trình sinh hóa đơn giả lập làm sai lệch số liệu kế toán.

---

## 6. ĐỐI SOÁT DANH MỤC PERMISSION TRONG DATABASE & SEEDERS

Qua kiểm tra đối chiếu giữa các seeder và source code thực tế:

1. **`database/seeders/RbacSeeder.php`**:
   - Hiện chỉ khai báo 10 module: `USER`, `ROLE`, `PERMISSION`, `AMENITY`, `INVOICE`, `TICKET`, `VISITOR`, `REPORT`, `VEHICLE`, `SYSTEM`.
   - Đã hỗ trợ đầy đủ ma trận 7 quyền chuẩn và quyền `special` cho 10 module này.
2. **`database/seeders/AnnouncementPermissionsSeeder.php`**:
   - Khai báo bổ sung module `ANNOUNCEMENT` (`VIEW`, `CREATE`, `UPDATE`, `PUBLISH`, `DELETE`).
3. **`database/seeders/SharedDemoDataSeeder.php`**:
   - Khai báo một số quyền rời rạc kiểu cũ: `RESIDENT:VIEW`, `RESIDENT:MANAGE`, `BUILDING:VIEW`, `BUILDING:MANAGE`, `APARTMENT:VIEW`, `APARTMENT:MANAGE`.
4. **Các module bị thiếu hụt danh mục quyền chuẩn hóa trong `RbacSeeder`**:
   - `RESIDENT`: Chưa có `RESIDENT:CREATE`, `RESIDENT:UPDATE`, `RESIDENT:DELETE`, `RESIDENT:DOWNLOAD`, `RESIDENT:UPLOAD`, `RESIDENT:SPECIAL`.
   - `RFID` / `ACCESS_CARD`: Chưa có trong danh mục của `RbacSeeder`.
   - `TEMP_REG`: Chưa có trong danh mục của `RbacSeeder`.
   - `PARCEL`: Đã được `ReceptionParcelService` và `QuocTinRealtimeService` định nghĩa (`PARCEL:VIEW`, `PARCEL:RECEIVE`, `PARCEL:CHECKOUT`, `PARCEL:UPDATE`, `PARCEL:DELETE`) nhưng chưa được nạp vào seeder RBAC.
   - `STAFF_SCHEDULE`: Chưa được nạp vào seeder RBAC.
   - `PRICING`: Chưa được nạp vào seeder RBAC.

> **Đánh giá**: Để backend kiểm tra quyền nhất quán mà không làm gãy ứng dụng, danh mục Permission trong database phải được đồng bộ đầy đủ các module của Chức năng 2–13 theo chuẩn 7 quyền.

---

## 7. ĐỀ XUẤT GIẢI PHÁP KIẾN TRÚC & KẾ HOẠCH TRIỂN KHAI (STAGE 2)

### 7.1. Nguyên tắc triển khai bắt buộc
1. **Zero Downtime & Zero Regression**: Giữ nguyên toàn bộ logic nghiệp vụ đang chạy ổn định của các chức năng; chỉ thay đổi lớp kiểm tra ủy quyền (Authorization Guard).
2. **Tuân thủ triệt để nguyên tắc Minimal Diff**: Không viết lại cấu trúc controller; chỉ chuẩn hóa phương thức kiểm tra quyền hiện có (ví dụ `checkAdminAuthorization`).
3. **Bảo toàn quyền tối cao của Quản trị viên cấp cao**: Tài khoản `SUPER_ADMIN` luôn được bỏ qua kiểm tra tự động qua `isSuperAdmin()`.
4. **Hỗ trợ quyền Người đặc biệt (`SYSTEM:SPECIAL` / `SPECIAL`)**: Bất kỳ người dùng nào được cấp quyền đặc biệt đều có thể vượt qua kiểm tra quản trị theo đúng thiết kế của RBAC Matrix.
5. **Đồng bộ quyền mặc định cho Role**: Đảm bảo các vai trò mặc định (`BUILDING_MANAGER`, `RECEPTIONIST`, `ACCOUNTANT`, ...) được cấp đủ quyền mặc định tương ứng trong database để người dùng hợp lệ không bị chặn nhầm.

### 7.2. Chuẩn hóa hàm kiểm tra ủy quyền tại các Controller

Thay thế mô hình hardcode `hasRole()` bằng mô hình kiểm tra phân tầng chặt chẽ:

```php
/**
 * Mô hình chuẩn hóa kiểm tra phân quyền tại Controller
 */
protected function checkAuthorization(Request $request, string $requiredPermission): ?JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'Yêu cầu xác thực tài khoản.',
            'error' => 'UNAUTHORIZED',
        ], Response::HTTP_UNAUTHORIZED);
    }

    // 1. Quản trị viên cấp cao (SUPER_ADMIN) luôn có quyền tối cao
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return null;
    }

    // 2. Kiểm tra quyền cụ thể từ RBAC Matrix hoặc quyền Người đặc biệt
    if (
        (method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission)) ||
        (method_exists($user, 'hasPermission') && ($user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special')))
    ) {
        return null;
    }

    // 3. Từ chối truy cập nếu không thỏa mãn
    return response()->json([
        'success' => false,
        'message' => "Bạn không có quyền thực hiện thao tác này (yêu cầu quyền: {$requiredPermission}).",
        'error' => 'FORBIDDEN',
    ], Response::HTTP_FORBIDDEN);
}
```

### 7.3. Ánh xạ quyền tương ứng trong từng Controller

Tại mỗi action của Controller, truyền mã permission tương ứng:
- `index()`, `show()` -> truyền mã quyền `:VIEW` (ví dụ `RESIDENT:VIEW`, `VEHICLE:VIEW`, `INVOICE:VIEW`).
- `store()` -> truyền mã quyền `:CREATE` (ví dụ `RESIDENT:CREATE`, `VEHICLE:CREATE`).
- `update()` -> truyền mã quyền `:UPDATE` (ví dụ `RESIDENT:UPDATE`, `VEHICLE:UPDATE`).
- `destroy()` -> truyền mã quyền `:DELETE` (ví dụ `RESIDENT:DELETE`, `VEHICLE:DELETE`).
- `approve()` -> truyền mã quyền `:APPROVE` (hoặc `:UPDATE`).
- `export()`, `download()` -> truyền mã quyền `:DOWNLOAD` (hoặc `:EXPORT`).
- `import()`, `upload()` -> truyền mã quyền `:UPLOAD` (hoặc `:IMPORT`).

### 7.4. Bảo vệ các Route Hóa đơn & Hạ tầng còn hở
- Bổ sung kiểm tra quyền trong `InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController` và `BuildingStructureController`.
- Riêng đối với Cư dân khi xem hóa đơn của chính mình (`/api/v1/invoices/current-month` hoặc chi tiết hóa đơn căn hộ mình sở hữu): Cho phép truy cập nếu căn hộ thuộc sở hữu hợp lệ của cư dân, ngược lại nếu truy cập hóa đơn căn hộ khác thì bắt buộc phải có quyền `INVOICE:VIEW`.

### 7.5. Kế hoạch Kiểm thử & Xác minh (Verification Plan)
1. **Pre-fix Test (Tái hiện lỗi trên mã hiện tại)**:
   - Dùng tài khoản `BUILDING_MANAGER` đã bị thu hồi quyền `RESIDENT:DELETE` trong RBAC Matrix.
   - Gửi `DELETE /api/v1/residents/{id}` -> Hiện tại mã trả về `200 OK` (Khẳng định lỗi).
2. **Post-fix Test (Xác nhận sau khi sửa)**:
   - Test 1: Khi quyền bị thu hồi trong RBAC Matrix -> API trả về `403 FORBIDDEN`.
   - Test 2: Khi quyền được cấp riêng qua vai trò tùy biến `CUSTOM_USER_{id}` -> API trả về `200 OK`.
   - Test 3: Tài khoản có quyền `SYSTEM:SPECIAL` -> API chấp nhận thao tác.
   - Test 4: Tài khoản `SUPER_ADMIN` -> Luôn thực hiện được mọi thao tác.
   - Test 5: Không làm gián đoạn quyền của Cư dân hợp lệ trong phạm vi căn hộ của họ.
3. **Chạy toàn bộ bộ test hồi quy**:
   - `tests/Feature/Rbac/RbacManagementTest.php`
   - `tests/Feature/RbacSecurityTest.php`
   - Tất cả các Feature Test của Chức năng 2–13.

---

## 8. KẾT LUẬN & KHUYẾN NGHỊ

Giai đoạn **STAGE 1 – PHÂN TÍCH LỖI RBAC MATRIX (BUG-04)** đã hoàn thành toàn diện, xác định chính xác 100% nguyên nhân cốt lõi và lập danh mục toàn bộ các vị trí vi phạm phân quyền trên toàn bộ hệ thống.

**Khuyến nghị**:  
Kính đề nghị Quản trị viên xem xét và phê duyệt bản báo cáo phân tích này để chuyển sang **STAGE 2 – IMPLEMENTATION (Triển khai khắc phục)** theo phương án chuẩn hóa phân quyền nhất quán đã đề xuất.

