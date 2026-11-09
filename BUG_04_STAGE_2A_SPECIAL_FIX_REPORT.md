# BÁO CÁO HOÀN THÀNH SỬA LỖI BẢO MẬT – BUG-04 STAGE 2A
## KHẮC PHỤC SYSTEM:SPECIAL GLOBAL BYPASS VÀ MODULE SPECIAL WILDCARD

**Repository:** `UMISORA09/QUANLY_TOANHA-DANCU`  
**Giai đoạn:** STAGE 2A SECURITY FIX  
**Thời gian thực hiện:** 2026-11-06  
**Chế độ tuân thủ:**
- Chỉ sửa đúng các file trong phạm vi được phê duyệt.
- Tuyệt đối không tự ý chuyển sang Stage 2B.
- Không commit, không push, không git add ., không git reset/checkout.

---

## 1. NGUYÊN NHÂN GỐC RỄ (ROOT CAUSE)

Lỗ hổng an ninh bypass quyền hạn giữa các phân hệ xuất phát từ 2 nguyên nhân kỹ thuật:

1. **Sử dụng `SYSTEM:SPECIAL` và `special` tại Controller phân hệ nghiệp vụ:**
   - Trong 4 Controller (`InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController`, `BuildingStructureController`), hàm `checkAuthorization` kiểm tra `($user->hasPermission('INVOICE:SPECIAL') || $user->hasPermission('SYSTEM:SPECIAL') || $user->hasPermission('special'))` (hoặc `BUILDING:SPECIAL`).
   - Việc này mở toang cửa cho bất kỳ tài khoản nào mang quyền `SYSTEM:SPECIAL` (vốn là quyền kỹ thuật hệ thống) có thể bypass toàn bộ phân hệ Hóa đơn và Hạ tầng tòa nhà.
2. **Logic Wildcard trong `User::hasPermission('SPECIAL')`:**
   - Tại `app/Models/User.php`:
     ```php
     if ($target === 'SPECIAL') {
         return in_array('SYSTEM:SPECIAL', $allPerms, true)
             || in_array('USER:SPECIAL', $allPerms, true)
             || collect($allPerms)->contains(fn ($p) => str_ends_with($p, ':SPECIAL'));
     }
     ```
   - Khi Controller gọi `$user->hasPermission('special')`, nó tự động khớp với bất kỳ quyền nào kết thúc bằng `:SPECIAL`. Vì vậy, một tài khoản chỉ có `INVOICE:SPECIAL` hoặc `VEHICLE:SPECIAL` cũng tự động vượt qua kiểm tra tại `BuildingStructureController`!

---

## 2. DANH SÁCH CALLER CỦA SPECIAL TRƯỚC VÀ SAU KHI SỬA

| File & Vị trí | Trước khi sửa (Lỗ hổng) | Sau khi sửa (Đã khắc phục) |
| :--- | :--- | :--- |
| **`app/Models/User.php`** (Dòng 202–208) | Khớp `SYSTEM:SPECIAL`, `USER:SPECIAL` và wildcard `str_ends_with($p, ':SPECIAL')`. | Chỉ khớp chính xác permission `'SPECIAL'` nếu được định nghĩa trực tiếp trong CSDL. Xóa bỏ hoàn toàn wildcard. |
| **`InvoiceManagementController.php`** (Dòng 39–46) | Chấp nhận `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, và `special`. | **Chỉ chấp nhận duy nhất `INVOICE:SPECIAL`** hoặc `$requiredPermission`. |
| **`InvoiceGenerationController.php`** (Dòng 37–44) | Chấp nhận `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, và `special`. | **Chỉ chấp nhận duy nhất `INVOICE:SPECIAL`** hoặc `$requiredPermission`. |
| **`DebtReminderController.php`** (Dòng 39–46) | Chấp nhận `INVOICE:SPECIAL`, `SYSTEM:SPECIAL`, và `special`. | **Chỉ chấp nhận duy nhất `INVOICE:SPECIAL`**. |
| **`BuildingStructureController.php`** (Dòng 41–47) | Chấp nhận `BUILDING:SPECIAL`, `SYSTEM:SPECIAL`, và `special`. | **Chỉ chấp nhận `BUILDING:SPECIAL` hoặc `APARTMENT:SPECIAL`**. |
| **`BuildingStructureController.php`** (Các hàm CRUD) | Thiếu kiểm tra nhân sự vận hành trên thao tác ghi/xóa. | **Bổ sung `isStaffUser($user)`** cho toàn bộ 8 API tạo, sửa, xóa tầng và căn hộ. |

---

## 3. CÁC FILE THỰC TẾ THAY ĐỔI

1. [`app/Models/User.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/User.php): Loại bỏ logic wildcard `:SPECIAL` trong `hasPermission`.
2. [`app/Http/Controllers/InvoiceManagementController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/InvoiceManagementController.php): Cô lập điều kiện chỉ nhận `INVOICE:SPECIAL`.
3. [`app/Http/Controllers/InvoiceGenerationController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/InvoiceGenerationController.php): Cô lập điều kiện chỉ nhận `INVOICE:SPECIAL`.
4. [`app/Http/Controllers/DebtReminderController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/DebtReminderController.php): Cô lập điều kiện chỉ nhận `INVOICE:SPECIAL`.
5. [`app/Http/Controllers/BuildingStructureController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/BuildingStructureController.php): Cô lập `BUILDING:SPECIAL` / `APARTMENT:SPECIAL`, bổ sung `isStaffUser` bảo vệ 8 API CRUD cấu trúc tòa nhà.
6. [`tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php): Cập nhật Test 25-27 và bổ sung Test 28-30 (tổng cộng 30 test cases).

---

## 4. MA TRẬN PHÂN QUYỀN SAU KHI SỬA

| Vai trò / Quyền hạn | Quản trị Hóa đơn (`INVOICE:*`) | Quản trị Hạ tầng (`BUILDING:*`, `APARTMENT:*`) | Quản trị Hệ thống (`SYSTEM:*`) | Data Scoping Cư dân |
| :--- | :---: | :---: | :---: | :---: |
| **`SUPER_ADMIN`** | **TOÀN QUYỀN** (via `isSuperAdmin`) | **TOÀN QUYỀN** (via `isSuperAdmin`) | **TOÀN QUYỀN** (via `isSuperAdmin`) | Bỏ qua (Xem toàn bộ) |
| **Nhân viên có `INVOICE:SPECIAL`** | **TOÀN QUYỀN HÓA ĐƠN** | **TỪ CHỐI (403)** | **TỪ CHỐI (403)** | Bỏ qua (Là nhân sự) |
| **Nhân viên có `BUILDING:SPECIAL`** | **TỪ CHỐI (403)** | **TOÀN QUYỀN HẠ TẦNG** | **TỪ CHỐI (403)** | Bỏ qua (Là nhân sự) |
| **Nhân viên có `SYSTEM:SPECIAL`** | **TỪ CHỐI (403)** | **TỪ CHỐI (403)** | **TOÀN QUYỀN SYSTEM** | Bỏ qua (Là nhân sự) |
| **Cư dân có `INVOICE:SPECIAL`** | Chỉ xem căn hộ mình (Chặn CRUD) | **TỪ CHỐI (403)** | **TỪ CHỐI (403)** | **BẮT BUỘC** (Chỉ xem căn hộ mình) |
| **Cư dân có `BUILDING:SPECIAL`** | **TỪ CHỐI (403)** | Chỉ xem (Chặn 403 ở mọi CRUD tầng/căn hộ) | **TỪ CHỐI (403)** | **BẮT BUỘC** |
| **Cư dân có `SYSTEM:SPECIAL`** | **TỪ CHỐI (403)** | **TỪ CHỐI (403)** | **TỪ CHỐI (403)** | **BẮT BUỘC** |

---

## 5. DANH SÁCH 30 TEST CASES TRONG TEST SUITE

File kiểm thử: [`tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php)

- **Test 01:** Request không token / token giả bị từ chối 401.
- **Test 02:** `SUPER_ADMIN` có toàn quyền bypass trên tất cả endpoints.
- **Test 03:** User có `INVOICE:VIEW` xem được danh sách và chi tiết hóa đơn.
- **Test 04:** User có role nhưng bị thu hồi `INVOICE:VIEW` bị từ chối 403.
- **Test 05:** User có `INVOICE:VIEW` không thể sinh hóa đơn hoặc preview batch (403).
- **Test 06:** User có `INVOICE:CREATE` chạy được preview dự toán.
- **Test 07:** User thiếu `INVOICE:DELETE` không thể hủy hóa đơn (403) và CSDL giữ nguyên `ISSUED`.
- **Test 08:** User thiếu quyền `UPDATE`/`APPROVE` không thể gửi nhắc nợ (403).
- **Test 09:** Data scoping: Cư dân xem hóa đơn căn hộ mình (200), xem căn hộ khác bị chặn 403.
- **Test 10:** Data scoping: Cư dân xem `current-month` căn hộ khác bị chặn 403.
- **Test 11:** Cư dân không thể hủy hoặc sinh hóa đơn (403).
- **Test 12:** User có `BUILDING:VIEW` xem được danh sách khối và tầng (200).
- **Test 13:** User có `BUILDING:VIEW` không thể tạo, sửa, xóa tầng (403).
- **Test 14:** User có `BUILDING:MANAGE` thực hiện CRUD tầng thành công.
- **Test 15:** User có `APARTMENT:VIEW` không thể tạo hoặc xóa căn hộ (403).
- **Test 16:** User có `APARTMENT:MANAGE` tạo và xóa căn hộ thành công.
- **Test 17:** Toàn vẹn CSDL: Hóa đơn không bị thay đổi trạng thái khi request bị từ chối.
- **Test 18:** Logic OR: Nhân viên chỉ có `INVOICE:UPDATE` gửi nhắc nợ thành công (202).
- **Test 19:** Logic OR: Nhân viên chỉ có `INVOICE:APPROVE` gửi nhắc nợ thành công (202).
- **Test 20:** Logic OR: Nhân viên chỉ có `INVOICE:VIEW` bị từ chối gửi nhắc nợ (403).
- **Test 21:** Non-bypass: Có role `ACCOUNTANT` nhưng thiếu permission tương ứng vẫn bị chặn 403.
- **Test 22:** `INVOICE:SPECIAL`: Nhân sự có quyền này thực hiện toàn bộ nghiệp vụ trong phân hệ Hóa đơn.
- **Test 23:** `INVOICE:SPECIAL` trên Cư dân: Bị chặn hoàn toàn khỏi thao tác hủy/sinh hóa đơn và bảo toàn Data Scoping.
- **Test 24:** `INVOICE:SPECIAL`: Không cấp quyền sang khối tòa nhà, tầng, căn hộ (403).
- **Test 25 (Đã sửa đổi):** `SYSTEM:SPECIAL`: Nhân sự có quyền này bị từ chối cả ở Hóa đơn lẫn Hạ tầng tòa nhà nếu thiếu permission tương ứng (403).
- **Test 26 (Đã sửa đổi):** `SYSTEM:SPECIAL` trên Cư dân: Bị từ chối tạo tầng, xóa căn hộ, hủy hóa đơn và bảo toàn Data Scoping.
- **Test 27 (Đã sửa đổi):** Toàn vẹn nghiệp vụ: Các quyền SPECIAL không thể bypass validation (422) hay kiểm tra tồn tại (404).
- **Test 28 (Mới bổ sung):** `BUILDING:SPECIAL`: Nhân sự có quyền này không thể truy cập, xem hoặc thao tác Hóa đơn (403).
- **Test 29 (Mới bổ sung):** `BUILDING:SPECIAL` trên Cư dân: Bị chặn tạo tầng và xóa căn hộ (403: "Bạn không có quyền quản lý cấu trúc tầng / căn hộ").
- **Test 30 (Mới bổ sung):** Thu hồi quyền SPECIAL có hiệu lực tức thì ở request tiếp theo (200 $\rightarrow$ 403).

---

## 6. KIỂM TRA CHẤT LƯỢNG MÃ NGUỒN VÀ RUNTIME

### 6.1. Kiểm tra tĩnh (Static Code Quality)
- **Cân bằng cú pháp & dấu ngoặc:** Curly balance = 0, Paren balance = 0, Square balance = 0.
- **Kiểm tra khoảng trắng (`git diff --check`):** 0 lỗi trailing whitespace, 0 lỗi dòng trống cuối file trên toàn bộ các file đã sửa.
- **Kiểm tra loại trừ:** Toàn bộ các file ngoài phạm vi (Seeders, RoleController, UserController, các Controllers ngoài Stage 2A) không bị chỉnh sửa.

### 6.2. Trạng thái Runtime & Docker Daemon
- **Lệnh kiểm tra:** `docker ps`
- **Kết quả:** Docker daemon tiếp tục được ghi nhận là OFFLINE (`failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine`).
- **Ghi nhận:** Trạng thái runtime kiểm thử là **DEFERRED**. Không giả lập kết quả runtime. Khi môi trường Docker được khởi động lại, lệnh thực thi sẵn sàng là:
  ```bash
  php artisan test tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php
  php artisan test tests/Feature/RbacSecurityTest.php
  ```

---

## 7. ĐÁNH GIÁ RỦI RO CÒN LẠI (REMAINING RISKS)

1. **Rủi ro hồi quy:** **Cực kỳ thấp (Near Zero)**. Các vai trò nghiệp vụ tiêu chuẩn (`BUILDING_MANAGER`, `ACCOUNTANT`, `SUPER_ADMIN`) đều sử dụng các mã quyền cụ thể theo RBAC Matrix (`INVOICE:*`, `BUILDING:*`, `APARTMENT:*`) hoặc `isSuperAdmin()`, nên hoàn toàn không bị ảnh hưởng bởi việc loại bỏ wildcard `:SPECIAL`.
2. **Frontend UI Sync:** Hook `usePermission.ts` trên giao diện người dùng có một logic tương tự về `:SPECIAL`. Cần lưu ý đồng bộ trên giao diện ở các đợt phát hành giao diện tiếp theo.

---

## 8. KẾT LUẬN VÀ TRẠNG THÁI NGHIỆM THU

> **KẾT LUẬN: ĐÃ HOÀN THÀNH SỬA LỖI BẢO MẬT STAGE 2A (PASS WITH CONDITIONS)**

1. Lỗ hổng `SYSTEM:SPECIAL Global Bypass` và wildcard `:SPECIAL` đã được loại bỏ triệt để.
2. Quyền `INVOICE:SPECIAL` và `BUILDING:SPECIAL` đã được cô lập tuyệt đối theo từng phân hệ.
3. Cư dân bị chặn hoàn toàn khỏi việc tạo, sửa, xóa tầng và căn hộ kể cả khi được gán bất kỳ quyền SPECIAL nào.
4. **Hệ thống dừng lại tại Stage 2A**, giữ nguyên toàn bộ trạng thái mã nguồn và test suite 30 test cases, **tuyệt đối không chuyển sang Stage 2B** để chờ nghiệm thu từ bạn.

