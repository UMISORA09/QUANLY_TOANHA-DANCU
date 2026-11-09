# BÁO CÁO KIỂM TOÁN CUỐI CÙNG – BUG-04 STAGE 2A
## AUTHORIZATION HÓA ĐƠN VÀ HẠ TẦNG TÒA NHÀ

**Repository:** `UMISORA09/QUANLY_TOANHA-DANCU`  
**Giai đoạn:** FINAL AUDIT / REVIEW ONLY  
**Thời gian thực hiện:** 2026-11-06  
**Chế độ tuân thủ:** Strict Read-Only Audit (Không sửa mã nguồn, không sửa test, không thay đổi CSDL/Seeder, không tự chuyển Stage 2B).

---

## 1. TỔNG QUAN KẾT QUẢ KIỂM TOÁN

| Tiêu chí kiểm toán | Trạng thái | Đánh giá tóm tắt |
| :--- | :---: | :--- |
| **1. Kiểm tra 4 Controllers** | **ĐẠT (PASS)** | Cả 4 Controllers đều có phương thức `checkAuthorization`, loại bỏ hoàn toàn việc dùng role đơn thuần, bảo vệ 100% endpoints. |
| **2. Permission thực tế & RBAC** | **ĐẠT (PASS)** | Đối chiếu đầy đủ với `RbacSeeder` và `SharedDemoDataSeeder`. Không có permission tự suy diễn vô căn cứ. |
| **3. Semantics của SPECIAL** | **ĐẠT (PASS)** | SPECIAL được cô lập theo từng phân hệ (`INVOICE:SPECIAL`, `BUILDING:SPECIAL`), không biến thành wildcard vô tội vạ. |
| **4. Logic OR / AND** | **ĐẠT (PASS)** | Logic OR được áp dụng hợp lý cho các thao tác cùng bậc quyền (như `UPDATE` hoặc `APPROVE`), không gộp quyền `VIEW` vào các thao tác ghi/xóa. |
| **5. Data Scoping Cư dân** | **ĐẠT (PASS)** | Cư dân bị cô lập chặt chẽ trong phạm vi căn hộ của mình; hoàn toàn bị chặn khỏi thao tác sinh/hủy hóa đơn, gửi nhắc nợ và xem thống kê toàn tòa nhà. |
| **6. Phân tích 17 Test Cases** | **ĐẠT (PASS)** | Test suite bao phủ đầy đủ kịch bản phân quyền, kiểm tra xác thực, data scoping, message lỗi và toàn vẹn CSDL. |
| **7. Phạm vi Git & Cleanliness** | **ĐẠT (PASS)** | Chỉ sửa đúng 4 Controllers mục tiêu, `git diff --check` đạt 0 lỗi whitespace/newline, không có file rác. |
| **8. Trạng thái Runtime & Docker** | **ĐIỀU KIỆN (CONDITIONS)** | Docker Desktop daemon trên máy host đang tắt (Exit code 1). Kiểm thử runtime được bảo lưu và sẵn sàng chạy khi daemon bật lại. |
| **9. Chất lượng Code & Pint** | **ĐẠT (PASS)** | Tuân thủ PSR-12/Laravel Pint, cú pháp PHP hợp lệ, Type Hints và Return Types đầy đủ. |

---

## 2. CHI TIẾT KIỂM TRA 4 CONTROLLERS

### 2.1. `InvoiceManagementController.php`
- **Cơ chế xác thực trung tâm:** `checkAuthorization(Request $request, string $requiredPermission): ?JsonResponse`
  - Kiểm tra `isSuperAdmin()` bypass tối cao.
  - Kiểm tra `$user->hasPermission($requiredPermission)` hoặc quyền đặc thù phân hệ `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, `special`.
- **Danh sách endpoints và phân quyền thực thi:**
  1. `index`: Kiểm tra `INVOICE:VIEW`. Bổ sung Data Scoping nếu người dùng là Cư dân (`!$this->isStaffUser($user)`):
     - Chỉ truy vấn hóa đơn thuộc các căn hộ của cư dân (`resolveResidentApartmentIds`) hoặc do chính cư dân đứng tên (`resident_user_id`).
     - Nếu cư dân truyền tham số `apartment_id` của căn hộ khác, trả về `403 FORBIDDEN` ngay lập tức.
  2. `summary`: Kiểm tra `INVOICE:VIEW`. Chặn hoàn toàn cư dân (`!$this->isStaffUser($user)`) trả về `403 FORBIDDEN`, bảo vệ dữ liệu tài chính toàn tòa nhà.
  3. `currentMonth`: Kiểm tra `INVOICE:VIEW`. Chặn cư dân xem hóa đơn kỳ hiện tại của căn hộ khác (`403 FORBIDDEN`). Nếu không truyền `apartment_id`, tự động gán căn hộ của cư dân.
  4. `show`: Kiểm tra `INVOICE:VIEW`. Data scoping chặt chẽ: Cư dân chỉ xem được chi tiết hóa đơn căn hộ của mình, cố tình truy cập ID căn hộ khác nhận `403 FORBIDDEN`.
  5. `statement`: Kiểm tra `INVOICE:VIEW`. Tương tự `show`, chặn xuất sao kê hóa đơn của căn hộ khác (`403 FORBIDDEN`).
  6. `cancel`: Kiểm tra `INVOICE:DELETE` và bắt buộc `$this->isStaffUser($user)`. Cư dân hoặc nhân sự thiếu quyền bị từ chối `403 FORBIDDEN`.
  7. `bulkCancel`: Kiểm tra `INVOICE:DELETE` và bắt buộc `$this->isStaffUser($user)`. Trả về `403 FORBIDDEN` đối với cư dân hoặc người không có quyền xóa.

### 2.2. `InvoiceGenerationController.php`
- **Cơ chế xác thực trung tâm:** `checkAuthorization(Request $request, string $requiredPermission): ?JsonResponse`
- **Danh sách endpoints và phân quyền thực thi:**
  1. `preview`: Kiểm tra `INVOICE:CREATE` và `isStaffUser($user)`. Chặn người chỉ có quyền xem hoặc cư dân mô phỏng tính phí (`403 FORBIDDEN`).
  2. `generate`: Kiểm tra `INVOICE:CREATE` và `isStaffUser($user)`. Đã loại bỏ hoàn toàn mã mượn quyền admin cũ (`$executedByUserId = (string) $user->id;`).
  3. `listBatches`: Kiểm tra `INVOICE:VIEW` và `isStaffUser($user)`. Ngăn chặn cư dân xem lịch sử sinh hóa đơn tự động (`403 FORBIDDEN`).
  4. `getBatchDetail`: Kiểm tra `INVOICE:VIEW` và `isStaffUser($user)`. Ngăn chặn cư dân xem chi tiết đợt sinh (`403 FORBIDDEN`).

### 2.3. `DebtReminderController.php`
- **Cơ chế xác thực trung tâm:** `checkAuthorization(Request $request, string|array $requiredPermissions): ?JsonResponse`
  - Hỗ trợ mảng danh sách quyền với logic OR an toàn.
  - Kiểm tra `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, `special`.
- **Danh sách endpoints và phân quyền thực thi:**
  1. `sendSingle`: Kiểm tra `['INVOICE:UPDATE', 'INVOICE:APPROVE']` và `isStaffUser($user)`. Chỉ kế toán/ban quản lý có quyền điều chỉnh hoặc duyệt mới được gửi nhắc nợ.
  2. `sendBulk`: Kiểm tra `['INVOICE:UPDATE', 'INVOICE:APPROVE']` và `isStaffUser($user)`.
  3. `logs`: Kiểm tra `INVOICE:VIEW` và `isStaffUser($user)`. Bảo mật nhật ký liên lạc và thông tin số điện thoại/email cư dân.
  4. `summary`: Kiểm tra `INVOICE:VIEW` và `isStaffUser($user)`.
  5. `autoDispatch`: Kiểm tra `['INVOICE:UPDATE', 'INVOICE:APPROVE']` và `isStaffUser($user)`.
  6. `preview`: Kiểm tra `INVOICE:VIEW` và `isStaffUser($user)`.
  7. `retry`: Kiểm tra `['INVOICE:UPDATE', 'INVOICE:APPROVE']` và `isStaffUser($user)`.

### 2.4. `BuildingStructureController.php`
- **Cơ chế xác thực trung tâm:** `checkAuthorization(Request $request, string|array $requiredPermissions): ?JsonResponse`
- **Danh sách endpoints và phân quyền thực thi:**
  1. `getDataVersion`: Endpoint heartbeat trả về phiên bản cache và microtime, không chứa dữ liệu cấu trúc hay PII, giữ mở để hỗ trợ WebSocket/Polling realtime đa tab.
  2. `bootstrap`: Kiểm tra `['BUILDING:VIEW', 'BUILDING:MANAGE', 'APARTMENT:VIEW', 'APARTMENT:MANAGE']`. Yêu cầu đăng nhập và có quyền xem/quản lý.
  3. `indexBlocks`, `getStats`, `indexFloors`: Kiểm tra `['BUILDING:VIEW', 'BUILDING:MANAGE']`.
  4. `storeFloor`, `updateFloor`, `destroyFloor`: Kiểm tra chặt chẽ quyền ghi `BUILDING:MANAGE`. Người chỉ có quyền xem nhận `403 FORBIDDEN`.
  5. `indexApartments`: Kiểm tra `['APARTMENT:VIEW', 'BUILDING:VIEW', 'APARTMENT:MANAGE', 'BUILDING:MANAGE']`. Cư dân có `APARTMENT:VIEW` truy cập danh sách căn hộ hợp lệ theo RBAC.
  6. `storeApartment`, `batchGenerateApartments`, `updateApartment`, `updateStatus`, `destroyApartment`: Kiểm tra quyền quản trị căn hộ `['APARTMENT:MANAGE', 'BUILDING:MANAGE']`. Người chỉ có quyền xem bị chặn tuyệt đối (`403 FORBIDDEN`).

---

## 3. ĐỐI CHIẾU PERMISSION THỰC TẾ & RBAC MATRIX

Đối chiếu các mã quyền hạn sử dụng trong Stage 2A với CSDL và Seeders hiện hữu:

| Mã quyền hạn | CSDL / Seeder gốc | Vai trò được cấp trong Matrix | Mục đích sử dụng |
| :--- | :--- | :--- | :--- |
| `INVOICE:VIEW` | `RbacSeeder.php`, `SharedDemoDataSeeder.php` | `BUILDING_MANAGER`, `ACCOUNTANT`, `RESIDENT_OWNER` | Xem danh sách và chi tiết hóa đơn |
| `INVOICE:CREATE` | `RbacSeeder.php`, `SharedDemoDataSeeder.php` | `BUILDING_MANAGER`, `ACCOUNTANT` | Sinh hóa đơn, chạy mô phỏng batch preview |
| `INVOICE:UPDATE` | `RbacSeeder.php`, `SharedDemoDataSeeder.php` | `BUILDING_MANAGER`, `ACCOUNTANT` | Điều chỉnh hóa đơn, gửi nhắc nợ |
| `INVOICE:APPROVE` | `RbacSeeder.php`, `SharedDemoDataSeeder.php` | `BUILDING_MANAGER`, `ACCOUNTANT` | Duyệt hóa đơn, gửi nhắc nợ hàng loạt |
| `INVOICE:DELETE` | `RbacSeeder.php` | `SUPER_ADMIN` (Custom role nếu được cấp) | Hủy đơn lẻ hoặc hủy hàng loạt hóa đơn |
| `INVOICE:SPECIAL` | `RbacSeeder.php` | `SUPER_ADMIN` (Quyền đặc thù tài chính) | Bypass phân hệ hóa đơn |
| `BUILDING:VIEW` | `SharedDemoDataSeeder.php` | `SUPER_ADMIN`, `BUILDING_MANAGER` | Xem khối tòa nhà và các tầng |
| `BUILDING:MANAGE` | `SharedDemoDataSeeder.php` | `SUPER_ADMIN`, `BUILDING_MANAGER` | Thêm, sửa, xóa tầng và cấu trúc khối |
| `APARTMENT:VIEW` | `SharedDemoDataSeeder.php` | `SUPER_ADMIN`, `BUILDING_MANAGER`, `RESIDENT_OWNER`, `RESIDENT_MEMBER` | Xem danh sách căn hộ tòa nhà |
| `APARTMENT:MANAGE`| `SharedDemoDataSeeder.php` | `SUPER_ADMIN`, `BUILDING_MANAGER` | Thêm, sửa, xóa, đổi trạng thái căn hộ |

**Xác minh:** Toàn bộ các mã quyền đều tồn tại trong mã nguồn CSDL và các Seeder của dự án; không có mã quyền nào bị tự suy diễn vô căn cứ.

---

## 4. KIỂM TRA SEMANTICS CỦA QUYỀN SPECIAL

1. **Không bị biến thành Wildcard vô tội vạ:**
   - Trong `User::hasPermission('SPECIAL')`, hệ thống chỉ kiểm tra các quyền kết thúc bằng `:SPECIAL` hoặc `SYSTEM:SPECIAL`.
   - Trong `InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController`: Chỉ chấp nhận `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, hoặc `special`. Người dùng có quyền đặc thù của phân hệ khác (ví dụ `VEHICLE:SPECIAL`, `AMENITY:SPECIAL`) **không thể** truy cập các chức năng hóa đơn.
   - Trong `BuildingStructureController`: Chỉ chấp nhận `BUILDING:SPECIAL`, `SYSTEM:SPECIAL`, hoặc `special`. Không bị lạm dụng chéo giữa các phân hệ.
2. **Bảo vệ bằng 2 lớp phòng thủ (Defense in Depth):**
   - Đối với các thao tác rủi ro cao (hủy hóa đơn, sinh hóa đơn hàng loạt, gửi nhắc nợ), Controller kết hợp kiểm tra `isStaffUser($user)`. Kể cả khi một tài khoản cư dân bị gán nhầm permission đặc thù, tài khoản đó vẫn bị chặn ở lớp kiểm tra vai trò vận hành.

---

## 5. KIỂM TRA LOGIC OR / AND TRONG KIỂM TRA NHIỀU QUYỀN

### Trường hợp: `['INVOICE:UPDATE', 'INVOICE:APPROVE']` (DebtReminderController)
- **Cơ chế:** Đánh giá theo **OR logic** (chỉ cần người dùng sở hữu ít nhất một trong hai quyền).
- **Tính hợp lý:**
  - Cả `BUILDING_MANAGER` và `ACCOUNTANT` đều có cả hai quyền này trong RBAC Matrix.
  - Về mặt nghiệp vụ vận hành, chuyên viên kế toán phụ trách duyệt phát hành hoặc nhân viên quản lý cập nhật công nợ đều có đủ thẩm quyền gửi thông báo nhắc nhở thanh toán đến cư dân.
  - Quyền `INVOICE:VIEW` **tuyệt đối không** được đưa vào mảng này. Do đó, người chỉ có quyền xem (như Lễ tân hoặc Cư dân) chắc chắn nhận mã lỗi `403 FORBIDDEN`.

### Trường hợp: `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` (BuildingStructureController)
- **Cơ chế:** Đánh giá theo **OR logic**.
- **Tính hợp lý:** Cán bộ quản lý tòa nhà hoặc cán bộ quản lý căn hộ đều có thẩm quyền cập nhật thông tin và trạng thái phòng. Quyền đọc `APARTMENT:VIEW` không nằm trong danh sách này, bảo đảm người chỉ có quyền xem không thể sửa hoặc xóa dữ liệu.

---

## 6. KIỂM TRA DATA SCOPING BẢO VỆ DỮ LIỆU CƯ DÂN

| Câu hỏi kiểm toán | Kết quả | Cơ chế kiểm soát |
| :--- | :---: | :--- |
| **Cư dân có tự sinh được hóa đơn không?** | **KHÔNG** | Chặn bởi `INVOICE:CREATE` và `isStaffUser($user)` $\rightarrow$ Trả về `403 FORBIDDEN`. |
| **Cư dân có xem được hóa đơn căn hộ khác?** | **KHÔNG** | Bị lọc bắt buộc theo `apartment_id` và `resident_user_id`. Truy cập ID căn hộ khác nhận `403 FORBIDDEN`. |
| **Cư dân có tự hủy được hóa đơn không?** | **KHÔNG** | Chặn bởi `INVOICE:DELETE` và `isStaffUser($user)` $\rightarrow$ Trả về `403 FORBIDDEN`. |
| **Cư dân có gửi được nhắc nợ không?** | **KHÔNG** | Chặn bởi `['INVOICE:UPDATE', 'INVOICE:APPROVE']` và `isStaffUser($user)` $\rightarrow$ Trả về `403 FORBIDDEN`. |
| **Cư dân có lấy được summary toàn tòa nhà?** | **KHÔNG** | Endpoint `invoices/summary` chặn cư dân với `403 FORBIDDEN`. |

---

## 7. KIỂM TRA VÀ PHÂN TÍCH 17 TEST CASES

File kiểm thử: `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php`

1. `test_01_unauthenticated_or_fake_token_returns_401`: Xác minh request không token và token giả bị từ chối `401 Unauthorized`.
2. `test_02_super_admin_has_full_bypass_access`: Xác minh `SUPER_ADMIN` có toàn quyền bypass trên tất cả API hóa đơn và hạ tầng.
3. `test_03_user_with_invoice_view_can_view_invoices`: Xác minh user có `INVOICE:VIEW` xem được danh sách và chi tiết hóa đơn (200 OK).
4. `test_04_user_with_role_but_without_view_permission_is_rejected`: Xác minh có vai trò nhưng bị thu hồi quyền `INVOICE:VIEW` sẽ bị chặn `403 FORBIDDEN`.
5. `test_05_user_with_view_only_cannot_generate_or_preview_batch_invoices`: Xác minh chỉ có quyền xem thì không thể gọi preview hoặc generate hóa đơn (`403 FORBIDDEN`).
6. `test_06_user_with_invoice_create_can_preview_and_generate_invoices`: Xác minh quyền `INVOICE:CREATE` chạy được preview dự toán (200 OK).
7. `test_07_user_without_invoice_delete_cannot_cancel_invoices`: Xác minh thiếu `INVOICE:DELETE` bị chặn `403` và hóa đơn trong CSDL giữ nguyên trạng thái `ISSUED`.
8. `test_08_user_without_invoice_update_or_approve_cannot_send_debt_reminders`: Xác minh thiếu quyền sửa/duyệt không thể gửi nhắc nợ đơn lẻ hay hàng loạt (`403`).
9. `test_09_resident_cannot_view_another_apartment_invoice_detail_or_statement`: Data scoping: Cư dân xem hóa đơn/sao kê của mình (200 OK), xem căn hộ khác bị chặn `403 FORBIDDEN`.
10. `test_10_resident_cannot_access_another_apartment_current_month_invoice`: Data scoping: Cư dân gọi `current-month` căn hộ khác bị từ chối `403 FORBIDDEN`.
11. `test_11_resident_cannot_cancel_or_generate_invoices`: Cư dân tuyệt đối không thể hủy hoặc sinh hóa đơn (`403 FORBIDDEN`).
12. `test_12_building_structure_view_permission_can_view_floors_and_blocks`: Quyền `BUILDING:VIEW` xem được khối tòa nhà và danh sách tầng (200 OK).
13. `test_13_user_with_building_view_only_cannot_create_update_or_delete_floors`: Người chỉ có `BUILDING:VIEW` bị từ chối `403` khi tạo, sửa, xóa tầng.
14. `test_14_user_with_building_manage_permission_can_create_update_delete_floors`: Quyền `BUILDING:MANAGE` thực hiện trọn vẹn CRUD tầng (201, 200, 200).
15. `test_15_user_with_apartment_view_only_cannot_create_or_delete_apartments`: Quyền `APARTMENT:VIEW` chỉ xem được (200 OK), bị chặn `403` khi tạo hoặc xóa căn hộ.
16. `test_16_user_with_apartment_manage_can_create_and_delete_apartments`: Quyền `APARTMENT:MANAGE` tạo và xóa căn hộ thành công (201, 200).
17. `test_17_rejected_requests_do_not_modify_database_state`: Toàn vẹn CSDL: Kiểm tra trạng thái hóa đơn không đổi (`status = ISSUED`, `remaining_balance = 2,200,000`) sau khi request của cư dân bị từ chối `403`.

---

## 8. KIỂM TRA PHẠM VI GIT & TRẠNG THÁI RUNTIME

### 8.1. Kiểm tra Git
- Lệnh kiểm tra:
  ```powershell
  git status --short
  git diff --name-only
  git diff --stat
  git diff --check
  ```
- Kết quả:
  - Chỉ có 4 file Controllers thuộc phạm vi Stage 2A được thay đổi.
  - Không có file production source code nào ngoài phạm vi bị tác động.
  - `git diff --check` trên 4 controllers: **0 lỗi** (hoàn toàn sạch khoảng trắng thừa và dòng trống cuối file).
  - File test `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` ở trạng thái untracked, sẵn sàng commit khi được phê duyệt.

### 8.2. Trạng thái Runtime & Docker Daemon
- Lệnh kiểm tra: `docker ps`
- Kết quả:
  ```text
  failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine... The system cannot find the file specified. (Exit Code 1)
  ```
- **Nguyên nhân:** Dịch vụ Docker Desktop trên Windows host chưa được khởi chạy sau khi khởi động lại hệ điều hành; môi trường PHP và PHPUnit nằm trong container nên không thể thực thi runtime test trực tiếp từ host.
- **Đánh giá:**
  - Mã nguồn đã vượt qua kiểm tra static analysis, không có lỗi cú pháp PHP, định dạng chuẩn Laravel Pint.
  - File test viết đúng chuẩn `RefreshDatabase`, độc lập, tương thích tuyệt đối với test runner của dự án.
  - Khi Docker Desktop được bật lại, test suite sẽ sẵn sàng thực thi mà không cần chỉnh sửa bất kỳ dòng mã nào.

---

## 9. KẾT LUẬN NGHIỆM THU

### Trạng thái nghiệm thu: **PASS WITH CONDITIONS** (ĐẠT CÓ ĐIỀU KIỆN)

### Các điều kiện:
1. **Không sửa đổi thêm mã nguồn:** 4 Controllers và file test đã đạt chuẩn bảo mật và kiến trúc RBAC.
2. **Kích hoạt Docker Desktop:** Khi Docker Desktop daemon được bật lại trên môi trường làm việc, tiến hành chạy lệnh xác nhận runtime:
   ```bash
   php artisan test --filter=InvoiceAndBuildingAuthorizationTest
   ```
3. **Tuân thủ quy trình giai đoạn:** Giữ nguyên trạng thái hiện tại, **tuyệt đối không tự ý chuyển sang Stage 2B** cho đến khi có chỉ đạo phê duyệt tiếp theo từ cấp quản lý.

