# BÁO CÁO ĐÁNH GIÁ AN NINH VÀ PHÂN TÍCH LỖ HỔNG BẢO MẬT
## XỬ LÝ LỖ HỔNG GLOBAL BYPASS CỦA QUYỀN SYSTEM:SPECIAL

**Repository:** `UMISORA09/QUANLY_TOANHA-DANCU`  
**Giai đoạn:** STAGE 2A SECURITY REVIEW ONLY  
**Thời gian thực hiện:** 2026-11-06  
**Chế độ tuân thủ:** Tuyệt đối KHÔNG sửa source code, KHÔNG sửa test, KHÔNG thay đổi CSDL/Migration/Seeder, KHÔNG commit/push/PR.

---

## 1. NGUYÊN NHÂN GỐC RỄ (ROOT CAUSE ANALYSIS)

Lỗ hổng **SYSTEM:SPECIAL Global Bypass** và hiện tượng rò rỉ quyền hạn chéo giữa các phân hệ (Cross-module Permission Leakage) xuất phát từ 2 nguyên nhân cốt lõi trong kiến trúc RBAC hiện tại:

### Nguyên nhân 1: Kiểm tra `SYSTEM:SPECIAL` trong các Controller nghiệp vụ độc lập
Tại 4 Controller triển khai ở Stage 2A, phương thức `checkAuthorization` đều bổ sung điều kiện:
```php
// Trong InvoiceManagementController, InvoiceGenerationController, DebtReminderController:
($user->hasPermission('INVOICE:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special'))

// Trong BuildingStructureController:
($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special'))
```
- Việc chấp nhận `SYSTEM:SPECIAL` trong các controller này đã biến `SYSTEM:SPECIAL` từ một quyền kỹ thuật thuộc Module `SYSTEM` (theo danh mục `RbacSeeder`) thành một "Master Key" thứ hai bên cạnh `SUPER_ADMIN`, cho phép nhân viên thuộc phân hệ này can thiệp vào phân hệ khác.

### Nguyên nhân 2: Logic Wildcard nguy hiểm trong `User::hasPermission('SPECIAL')`
Tại `app/Models/User.php` (dòng 203–207):
```php
if ($target === 'SPECIAL') {
    return in_array('SYSTEM:SPECIAL', $allPerms, true)
        || in_array('USER:SPECIAL', $allPerms, true)
        || collect($allPerms)->contains(fn ($p) => str_ends_with($p, ':SPECIAL'));
}
```
- Khi Controller gọi `$user->hasPermission('special')`:
  - Biến `$target` được chuẩn hóa thành `'SPECIAL'`.
  - `User.php` kiểm tra `str_ends_with($p, ':SPECIAL')`.
  - **Hệ quả cực kỳ nghiêm trọng:** Bất kỳ người dùng nào sở hữu **BẤT KỲ** quyền đặc biệt nào của phân hệ bất kỳ (như `INVOICE:SPECIAL`, `VEHICLE:SPECIAL`, `AMENITY:SPECIAL`, `TICKET:SPECIAL`) đều làm cho `$user->hasPermission('special')` trả về `true`.
  - Do đó, một Kế toán chỉ có `INVOICE:SPECIAL` sẽ tự động vượt qua `checkAuthorization` tại `BuildingStructureController` thông qua điều kiện `|| $user->hasPermission('special')`.

---

## 2. TRUY TÌM TOÀN BỘ NƠI CẤP VÀ QUẢN LÝ SYSTEM:SPECIAL

| Nơi kiểm tra | Tồn tại `SYSTEM:SPECIAL`? | Chi tiết đối chiếu mã nguồn |
| :--- | :---: | :--- |
| **`RbacSeeder.php`** | **CÓ** | Khai báo tại dòng 202 (`module = 'SYSTEM'`). Trong ma trận mặc định, chỉ gán duy nhất cho vai trò `SUPER_ADMIN`. Các vai trò nghiệp vụ khác (`ACCOUNTANT`, `BUILDING_MANAGER`, `RECEPTIONIST`, `RESIDENT_*`) không được gán mặc định. |
| **`SharedDemoDataSeeder.php`** | **KHÔNG** | File seeder demo dùng chung không khai báo và không gán `SYSTEM:SPECIAL`. |
| **`RoleController.php` & `RbacService.php`** | **CÓ (Nguy cơ cấp phát)** | API `PUT /api/v1/roles/{id}/permissions` nhận mảng `permissions`. Hàm `syncRolePermissions` chấp nhận mã `SYSTEM:SPECIAL` hoặc alias `SPECIAL` cho bất kỳ vai trò nào mà không có ràng buộc vai trò. |
| **`UserController.php` (`CUSTOM_USER_{userId}`)** | **CÓ (Nguy cơ cấp phát)** | API `PUT /api/v1/users/{id}/permissions` cho phép Quản trị viên tùy biến quyền riêng cho từng cá nhân thông qua cơ chế `CUSTOM_USER_{userId}`. Admin có thể gán trực tiếp `SYSTEM:SPECIAL` cho bất kỳ tài khoản nào. |
| **Giao diện `RbacManagement.tsx`** | **CÓ** | Cột thứ 7 trong ma trận phân quyền là cột "Special" (`key: 'special'`). Dòng module `SYSTEM` hiển thị checkbox của `SYSTEM:SPECIAL`. Nút "Bật toàn bộ cột Special" sẽ chọn tất cả quyền `:SPECIAL` bao gồm cả `SYSTEM:SPECIAL`. |
| **Hiệu lực khi thu hồi** | **TỨC THÌ** | Khi thu hồi quyền, `RoleController` xóa cache `rbac_roles_all` và `UserController` gọi `$user->clearPermissionCache()`. Do mỗi HTTP request trong Laravel tải mới phiên người dùng từ CSDL, quyền bị thu hồi có hiệu lực ngay ở request tiếp theo. |
| **Cơ chế tự động cấp lại (Fallback)** | **KHÔNG CÓ** | Không có cron job hay middleware nào tự động cấp lại `SYSTEM:SPECIAL` khi đã bị admin thu hồi. |

---

## 3. DANH SÁCH TOÀN BỘ VỊ TRÍ BYPASS VÀ PHẠM VI ẢNH HƯỞNG

### 3.1. Phân hệ Hạ tầng tòa nhà & Căn hộ (`BuildingStructureController.php`)
- **Vị trí kiểm tra:** Dòng 44 (`checkAuthorization`).
- **Các API bị bypass:**
  1. `POST /api/v1/blocks/{id}/floors` (Tạo tầng mới - `BUILDING:MANAGE`) $\rightarrow$ **Bypass**
  2. `PUT /api/v1/floors/{id}` (Sửa thông tin tầng - `BUILDING:MANAGE`) $\rightarrow$ **Bypass**
  3. `DELETE /api/v1/floors/{id}` (Xóa tầng - `BUILDING:MANAGE`) $\rightarrow$ **Bypass**
  4. `POST /api/v1/apartments` (Tạo căn hộ đơn lẻ - `APARTMENT:MANAGE`) $\rightarrow$ **Bypass**
  5. `POST /api/v1/apartments/batch-generate` (Khởi tạo căn hộ hàng loạt - `APARTMENT:MANAGE`) $\rightarrow$ **Bypass**
  6. `PUT /api/v1/apartments/{id}` (Cập nhật căn hộ - `APARTMENT:MANAGE`) $\rightarrow$ **Bypass**
  7. `PATCH /api/v1/apartments/{id}/status` (Đổi trạng thái căn hộ - `APARTMENT:MANAGE`) $\rightarrow$ **Bypass**
  8. `DELETE /api/v1/apartments/{id}` (Xóa căn hộ - `APARTMENT:MANAGE`) $\rightarrow$ **Bypass**
  9. `GET /api/v1/blocks`, `GET /api/v1/apartments`, `GET /api/v1/buildings/bootstrap` (Xem dữ liệu) $\rightarrow$ **Bypass**
- **Đặc biệt nghiêm trọng:** Trong `BuildingStructureController`, các thao tác ghi/xóa trên **không có lớp kiểm tra `$this->isStaffUser($user)`**. Do đó, nếu một Cư dân (`RESIDENT_OWNER`) được gán `SYSTEM:SPECIAL` hoặc bất kỳ `:SPECIAL` nào, họ có thể **tạo, sửa và xóa căn hộ của cả tòa nhà**!

### 3.2. Phân hệ Quản lý Hóa đơn (`InvoiceManagementController.php`)
- **Vị trí kiểm tra:** Dòng 42 (`checkAuthorization`).
- **Các API bị bypass:**
  1. `POST /api/v1/invoices/{id}/cancel` (Hủy hóa đơn - `INVOICE:DELETE`) $\rightarrow$ Bypass kiểm tra quyền (được chặn thứ cấp bởi `isStaffUser`).
  2. `POST /api/v1/invoices/bulk-cancel` (Hủy hóa đơn hàng loạt - `INVOICE:DELETE`) $\rightarrow$ Bypass kiểm tra quyền (được chặn thứ cấp bởi `isStaffUser`).
  3. `GET /api/v1/invoices/summary` (Xem thống kê tài chính toàn tòa nhà) $\rightarrow$ Bypass kiểm tra quyền (được chặn thứ cấp bởi `isStaffUser`).
  4. `GET /api/v1/invoices` (Danh sách hóa đơn) $\rightarrow$ Bypass kiểm tra quyền.

### 3.3. Phân hệ Sinh hóa đơn tự động (`InvoiceGenerationController.php`)
- **Vị trí kiểm tra:** Dòng 40 (`checkAuthorization`).
- **Các API bị bypass:**
  1. `POST /api/v1/invoices/batch/preview` (Mô phỏng sinh hóa đơn) $\rightarrow$ Bypass kiểm tra quyền.
  2. `POST /api/v1/invoices/batch/generate` (Sinh hóa đơn hàng loạt) $\rightarrow$ Bypass kiểm tra quyền.
  3. `GET /api/v1/invoices/batches`, `GET /api/v1/invoices/batches/{id}` $\rightarrow$ Bypass kiểm tra quyền.

### 3.4. Phân hệ Quản lý Nhắc nợ (`DebtReminderController.php`)
- **Vị trí kiểm tra:** Dòng 42 (`checkAuthorization`).
- **Các API bị bypass:**
  1. `POST /api/v1/invoices/{id}/send-reminder`, `POST /api/v1/invoices/bulk-send-reminders` (Gửi nhắc nợ) $\rightarrow$ Bypass kiểm tra quyền.
  2. `POST /api/v1/invoices/reminders/auto-dispatch` (Quét nhắc nợ tự động) $\rightarrow$ Bypass kiểm tra quyền.
  3. `GET /api/v1/invoices/reminders/history`, `GET /api/v1/invoices/reminders/summary` $\rightarrow$ Bypass kiểm tra quyền.

---

## 4. ĐỀ XUẤT NGUYÊN TẮC PHÂN QUYỀN CHUẨN XÁC

Nhằm triệt tiêu lỗ hổng bypass mà không làm ảnh hưởng đến quy trình nghiệp vụ hiện hữu, nguyên tắc phân quyền chuẩn cần được thiết lập như sau:

```
[ SUPER_ADMIN ]
      │ (Toàn quyền tối cao hệ thống via isSuperAdmin())
      ├──> [ Phân hệ Hóa đơn ]  ──> Chỉ chấp nhận: INVOICE:* hoặc INVOICE:SPECIAL
      ├──> [ Phân hệ Hạ tầng ]  ──> Chỉ chấp nhận: BUILDING:* / APARTMENT:* hoặc BUILDING:SPECIAL
      └──> [ Phân hệ Hệ thống ] ──> Chỉ chấp nhận: SYSTEM:* hoặc SYSTEM:SPECIAL
```

1. **`SUPER_ADMIN`:** Giữ nguyên đặc quyền tối cao thông qua `isSuperAdmin()`. Đây là vai trò duy nhất được phép bypass trên toàn bộ hệ thống.
2. **`INVOICE:SPECIAL`:** Là đặc quyền **riêng biệt duy nhất** của phân hệ Hóa đơn & Nhắc nợ. Chỉ có hiệu lực trong `InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController`. Tuyệt đối không có hiệu lực sang Hạ tầng tòa nhà.
3. **`BUILDING:SPECIAL`:** Là đặc quyền **riêng biệt duy nhất** của phân hệ Hạ tầng tòa nhà & Căn hộ (`BuildingStructureController`). Tuyệt đối không có hiệu lực sang Hóa đơn.
4. **`SYSTEM:SPECIAL`:** Thuộc phân hệ Kỹ thuật Hệ thống (`module = 'SYSTEM'`). **Tuyệt đối không được dùng làm điều kiện bypass tại các Controller nghiệp vụ độc lập** (Hóa đơn, Hạ tầng, Tiện ích, Bưu phẩm...). Quyền này chỉ có giá trị cho các thao tác cấu hình hệ thống, sao lưu và nhật ký kiểm toán.
5. **Loại bỏ hoàn toàn logic Wildcard của `'SPECIAL'`:** Xóa bỏ việc kiểm tra `$user->hasPermission('special')` chung chung trong các Controller để ngăn chặn việc gom tất cả các quyền `:SPECIAL` thành một.

---

## 5. PHÂN TÍCH 7 TRƯỜNG HỢP CỤ THỂ

| Trường hợp | Quyền & Vai trò thực tế | Hiện trạng (Trạng thái lỗi) | Đề xuất khắc phục (Kỳ vọng) |
| :--- | :--- | :--- | :--- |
| **1. Kế toán có `SYSTEM:SPECIAL`** | Role `ACCOUNTANT`, có `SYSTEM:SPECIAL`, không có `BUILDING:MANAGE`. | **Bypass thành công:** Kế toán tạo/sửa/xóa được tầng và căn hộ tại `BuildingStructureController`. | **Bị chặn 403 Forbidden:** `BuildingStructureController` chỉ chấp nhận quyền hạ tầng hoặc `BUILDING:SPECIAL`. |
| **2. Lễ tân có `SYSTEM:SPECIAL`** | Role `RECEPTIONIST`, có `SYSTEM:SPECIAL`, không có `INVOICE:DELETE`. | **Bypass quyền:** Vượt qua `checkAuthorization` ở Controller Hóa đơn (bị chặn thứ cấp ở tầng `isStaffUser` cho thao tác hủy, nhưng xem được danh sách hóa đơn). | **Bị chặn 403 Forbidden ngay từ đầu:** Không vượt qua được `checkAuthorization` vì thiếu `INVOICE:DELETE` / `INVOICE:SPECIAL`. |
| **3. Cư dân có `SYSTEM:SPECIAL`** | Role `RESIDENT_OWNER`, có `SYSTEM:SPECIAL`. | **Lỗ hổng nghiêm trọng:** Cư dân tạo/xóa được tầng và căn hộ tại `BuildingStructureController` (do thiếu kiểm tra `isStaffUser`). | **Bị chặn 403 Forbidden:** Không thể can thiệp vào bất kỳ dữ liệu hạ tầng hay tài chính nào. |
| **4. Nhân viên chỉ có `INVOICE:SPECIAL`** | Role `ACCOUNTANT`, có `INVOICE:SPECIAL`. | **Bị rò rỉ quyền:** Thao tác được Hóa đơn, nhưng cũng bypass được `BuildingStructureController` do hàm `hasPermission('special')` trả về true. | **Cô lập phân hệ:** Thao tác đầy đủ trong phân hệ Hóa đơn; bị chặn 403 hoàn toàn khi gọi sang Hạ tầng tòa nhà. |
| **5. Nhân viên chỉ có `BUILDING:SPECIAL`** | Role `BUILDING_MANAGER`, có `BUILDING:SPECIAL`. | **Bị rò rỉ quyền:** Thao tác được Hạ tầng, nhưng cũng bypass được các Controller Hóa đơn do hàm `hasPermission('special')` trả về true. | **Cô lập phân hệ:** Thao tác đầy đủ trong phân hệ Hạ tầng; bị chặn 403 hoàn toàn khi gọi sang Hóa đơn. |
| **6. Quản trị viên `SUPER_ADMIN`** | Role `SUPER_ADMIN`, `isSuperAdmin() = true`. | **Bypass tối cao:** Toàn quyền truy cập tất cả API theo chính sách. | **Giữ nguyên 100%:** Toàn quyền quản trị hệ thống. |
| **7. Tài khoản bị thu hồi `SYSTEM:SPECIAL`** | Admin gọi API xóa quyền `SYSTEM:SPECIAL`. | **Có hiệu lực ngay:** Cache in-memory xóa, CSDL cập nhật, request tiếp theo bị chặn 403. | **Giữ nguyên cơ chế:** Đảm bảo tính tức thì và bảo mật khi thu hồi quyền. |

---

## 6. PHƯƠNG ÁN SỬA ĐỔI TỐI THIỂU (LEAST INVASIVE SOLUTION)

Để khắc phục triệt để lỗ hổng trên với mức độ thay đổi mã nguồn nhỏ nhất, chỉ cần điều chỉnh **5 vị trí** (mỗi vị trí 1 dòng mã) mà không làm thay đổi luồng xử lý chính:

### Vị trí 1: `app/Http/Controllers/InvoiceManagementController.php` (Dòng 40–45)
- **Hiện tại:**
  ```php
  (method_exists($user, 'hasPermission') && ($user->hasPermission('INVOICE:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special')))
  ```
- **Sửa thành:**
  ```php
  (method_exists($user, 'hasPermission') && $user->hasPermission('INVOICE:SPECIAL'))
  ```

### Vị trí 2: `app/Http/Controllers/InvoiceGenerationController.php` (Dòng 38–43)
- **Hiện tại:**
  ```php
  (method_exists($user, 'hasPermission') && ($user->hasPermission('INVOICE:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special')))
  ```
- **Sửa thành:**
  ```php
  (method_exists($user, 'hasPermission') && $user->hasPermission('INVOICE:SPECIAL'))
  ```

### Vị trí 3: `app/Http/Controllers/DebtReminderController.php` (Dòng 40–45)
- **Hiện tại:**
  ```php
  ($user->hasPermission('INVOICE:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special'))
  ```
- **Sửa thành:**
  ```php
  $user->hasPermission('INVOICE:SPECIAL')
  ```

### Vị trí 4: `app/Http/Controllers/BuildingStructureController.php` (Dòng 42–47)
- **Hiện tại:**
  ```php
  ($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special'))
  ```
- **Sửa thành:**
  ```php
  $user->hasPermission('BUILDING:SPECIAL')
  ```
  *(Đồng thời bổ sung kiểm tra `$this->isStaffUser($user)` cho các thao tác ghi/xóa tầng và căn hộ để bảo vệ tuyệt đối trước tài khoản cư dân).*

### Vị trí 5: `app/Models/User.php` (Dòng 203–207)
- **Hiện tại:**
  ```php
  if ($target === 'SPECIAL') {
      return in_array('SYSTEM:SPECIAL', $allPerms, true)
          || in_array('USER:SPECIAL', $allPerms, true)
          || collect($allPerms)->contains(fn ($p) => str_ends_with($p, ':SPECIAL'));
  }
  ```
- **Sửa thành:**
  ```php
  if ($target === 'SPECIAL') {
      return in_array('SPECIAL', $allPerms, true)
          || in_array('SYSTEM:SPECIAL', $allPerms, true);
  }
  ```
  *(Loại bỏ `collect($allPerms)->contains(fn ($p) => str_ends_with($p, ':SPECIAL'))` để ngăn chặn việc sở hữu một quyền `:SPECIAL` cục bộ biến thành quyền `'SPECIAL'` toàn cục).*

---

## 7. KẾ HOẠCH KIỂM THỬ XÁC MINH (TEST PLAN)

Khi được phê duyệt triển khai phương án sửa đổi, test suite sẽ được bổ sung các ca kiểm thử tự động sau:

1. `test_accountant_with_system_special_cannot_create_floor_or_apartments`:
   - Cấp `SYSTEM:SPECIAL` cho tài khoản có role `ACCOUNTANT`.
   - Gửi request `POST /api/v1/blocks/{id}/floors`.
   - **Kỳ vọng:** Trả về `403 FORBIDDEN`.
2. `test_staff_with_invoice_special_cannot_access_building_structure`:
   - Cấp `INVOICE:SPECIAL` cho tài khoản nhân viên.
   - Gửi request `GET /api/v1/blocks`, `POST /api/v1/apartments`.
   - **Kỳ vọng:** Trả về `403 FORBIDDEN`.
3. `test_staff_with_building_special_cannot_access_invoices`:
   - Cấp `BUILDING:SPECIAL` cho tài khoản nhân viên.
   - Gửi request `GET /api/v1/invoices`, `POST /api/v1/invoices/batch/preview`.
   - **Kỳ vọng:** Trả về `403 FORBIDDEN`.
4. `test_resident_with_system_special_cannot_access_building_crud`:
   - Cấp `SYSTEM:SPECIAL` cho tài khoản cư dân.
   - Gửi request `POST /api/v1/blocks/{id}/floors`, `DELETE /api/v1/apartments/{id}`.
   - **Kỳ vọng:** Trả về `403 FORBIDDEN`.
5. `test_super_admin_still_has_full_bypass_access`:
   - Kiểm tra tài khoản `SUPER_ADMIN` gọi tất cả các API trên.
   - **Kỳ vọng:** Đều trả về `200 OK` hoặc `201 Created`.

---

## 8. ĐÁNH GIÁ RỦI RO HỒI QUY (REGRESSION RISK ASSESSMENT)

| Khu vực đánh giá | Mức độ rủi ro | Biện pháp giảm thiểu |
| :--- | :---: | :--- |
| **Quyền quản trị của Super Admin** | **Không có (Zero Risk)** | `SUPER_ADMIN` được xử lý qua `isSuperAdmin()` ở dòng đầu tiên của `checkAuthorization`, hoàn toàn không phụ thuộc vào `SYSTEM:SPECIAL`. |
| **Nghiệp vụ Hóa đơn thông thường** | **Rất thấp (Very Low)** | Kế toán và Ban quản lý được cấp các quyền chuẩn (`INVOICE:VIEW`, `INVOICE:CREATE`, `INVOICE:UPDATE`, `INVOICE:APPROVE`) vẫn hoạt động bình thường theo RBAC Matrix. |
| **Nghiệp vụ Hạ tầng thông thường** | **Rất thấp (Very Low)** | Ban quản lý có quyền `BUILDING:VIEW`, `BUILDING:MANAGE`, `APARTMENT:MANAGE` vẫn quản trị khối, tầng, căn hộ bình thường. |
| **Hệ thống Frontend (`usePermission.ts`)** | **Thấp (Low)** | Hook `usePermission` trên Frontend đang kiểm tra `hasPermission`. Sau khi sửa Backend, cần đồng bộ loại bỏ logic wildcard tương ứng trên Frontend để hiển thị nút bấm chính xác. |

---

## 9. KẾT LUẬN VÀ KIẾN NGHỊ

1. **Xác nhận phát hiện:** Lỗ hổng `SYSTEM:SPECIAL` và `special` wildcard là **Security Finding có thật**, cho phép rò rỉ quyền hạn chéo giữa các phân hệ và có nguy cơ để tài khoản cư dân can thiệp vào hạ tầng tòa nhà nếu bị cấp nhầm quyền.
2. **Phương án đề xuất:** Áp dụng phương án sửa tối thiểu tại mục 6 (chỉ sửa 5 dòng code ở 5 file) để cô lập quyền đặc biệt về đúng phân hệ tương ứng.
3. **Trạng thái tuân thủ:**
   - **Không có bất kỳ dòng code nào bị thay đổi** trong giai đoạn Review này.
   - Giữ nguyên trạng thái Stage 2A.
   - Dừng lại chờ quyết định phê duyệt phương án khắc phục từ bạn trước khi thực hiện bất kỳ thao tác nào tiếp theo.

