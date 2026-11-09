# BÁO CÁO KIỂM THỬ RUNTIME – BUG-04 STAGE 2A
## KIỂM THỬ AUTHORIZATION HÓA ĐƠN VÀ HẠ TẦNG TÒA NHÀ

**Repository:** `UMISORA09/QUANLY_TOANHA-DANCU`  
**Giai đoạn:** STAGE 2A RUNTIME VERIFICATION (REVIEW ONLY / RUNTIME TEST ONLY)  
**Thời gian thực hiện:** 2026-11-06  
**Nguyên tắc tuân thủ:** Tuyệt đối không sửa production source code hoặc test; không tự ý chuyển sang Stage 2B; báo cáo trung thực, không ghi PASS nếu chưa chạy thành công.

---

## 1. TRẠNG THÁI DOCKER & CONTAINER

### 1.1. Lệnh kiểm tra thực tế
```powershell
docker version
docker ps
docker ps -a
```

### 1.2. Kết quả đầu ra
```text
Client:
 Version:           29.7.2
 API version:       1.55
 Go version:        go1.26.5
 Git commit:        a7dcaa6
 Built:             Wed Aug  5 18:31:33 2026
 OS/Arch:           windows/amd64
 Context:           desktop-linux
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
```

### 1.3. Đánh giá trạng thái
- **Trạng thái:** `DOCKER DAEMON OFFLINE`.
- **Lỗi chính xác:** `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.` (Exit Code 1).
- **Hành động thực hiện:**
  - Tuân thủ nghiêm ngặt chỉ dẫn: Không reset Docker, không xóa volume, không thay đổi database.
  - Tạm dừng (Stop) các lệnh thực thi `docker exec ...` đối với PHPUnit runtime, PHP syntax `-l` và Pint trong container.

---

## 2. KẾT QUẢ CÁC TEST SUITE PHPUNIT

Do Docker daemon đang tắt, các lệnh thực thi runtime trực tiếp qua container không thể chạy tại thời điểm này. Trạng thái kiểm thử runtime được ghi nhận trung thực như sau:

| Test Suite | Đường dẫn chỉ định | Trạng thái Runtime | Ghi chú & Đối chiếu mã nguồn |
| :--- | :--- | :---: | :--- |
| **Stage 2A Suite** | `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` | **DEFERRED (TẠM HOÃN)** | File test mới tạo (596 dòng, 17 test cases). Sẵn sàng chạy khi Docker bật lại. |
| **Regression Suite 1** | `tests/Feature/Invoices/InvoiceStatusManagementTest.php` | **DEFERRED (TẠM HOÃN)** | File tồn tại trong repository. (Xem phân tích mã nguồn bên dưới). |
| **Regression Suite 2** | `tests/Feature/Rbac/RbacSecurityTest.php` | **PATH NOT FOUND** | **Đường dẫn không tồn tại**. Đường dẫn thực tế trong repo là: `tests/Feature/RbacSecurityTest.php`. |

### Chi tiết phát hiện mã nguồn đối với các test hồi quy:
1. **`tests/Feature/Rbac/RbacSecurityTest.php`:**
   - Trong cây thư mục dự án, không có thư mục con `tests/Feature/Rbac/RbacSecurityTest.php`.
   - File test tồn tại thực tế là `tests/Feature/RbacSecurityTest.php` (chứa 13 test cases về Guest 401, User View/Create/Update/Delete 403/200, Super Admin bypass, System role protection, Login response).
   - Lệnh đúng cần chạy khi Docker online:
     ```powershell
     docker exec smart_cassavas_app php artisan test tests/Feature/RbacSecurityTest.php
     ```

2. **`tests/Feature/Invoices/InvoiceStatusManagementTest.php`:**
   - Phân tích mã nguồn file: Test này được viết từ giai đoạn phát triển ban đầu, sử dụng mã giả token (`$this->token = 'smart_token_'.$this->adminUser->id.'_invoice'`) nhưng **chưa tạo bản ghi phiên đăng nhập trong bảng `user_sessions`**, đồng thời `$this->adminUser` được tạo bằng `User::factory()` mà không được gán vai trò RBAC hay quyền `INVOICE:VIEW`.
   - Khi chạy với `InvoiceManagementController` đã được siết chặt bảo mật ở Stage 2A, test này sẽ cần dữ liệu session hợp lệ để vượt qua tầng Bearer Auth.

---

## 3. KIỂM TRA SPECIAL VÀ OR PERMISSION (COVERAGE ANALYSIS)

Dựa trên việc đọc và phân tích 17 test case trong `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` và mã nguồn 4 Controllers:

### 3.1. Người chỉ có `INVOICE:UPDATE` có được gửi nhắc nợ không?
- **Logic Backend:** **CÓ**. Trong `DebtReminderController`, mảng `['INVOICE:UPDATE', 'INVOICE:APPROVE']` được duyệt qua vòng lặp OR. Nếu người dùng sở hữu `INVOICE:UPDATE` và là `isStaffUser`, request được cấp quyền (`return null`).
- **Tình trạng Test Coverage:** **TEST COVERAGE GAP**. Test 08 hiện tại chỉ kiểm tra trường hợp người có `INVOICE:VIEW` bị từ chối 403; chưa có test case cấp riêng rẽ độc lập duy nhất quyền `INVOICE:UPDATE` để assert gửi nhắc nợ thành công.

### 3.2. Người chỉ có `INVOICE:APPROVE` có được phép không?
- **Logic Backend:** **CÓ**. Logic OR tương tự cho phép người có `INVOICE:APPROVE` và là `isStaffUser` gửi nhắc nợ.
- **Tình trạng Test Coverage:** **TEST COVERAGE GAP**. Chưa có test case cấp riêng rẽ độc lập duy nhất quyền `INVOICE:APPROVE` để assert gửi nhắc nợ thành công.

### 3.3. Người có `INVOICE:SPECIAL` được thực hiện chính xác những thao tác nào?
- **Logic Backend:**
  - Trong `InvoiceManagementController`: Thỏa mãn quyền truy cập xem danh sách, chi tiết, sao kê. Tuy nhiên, thao tác hủy hóa đơn (`cancel`, `bulkCancel`) và xem `summary` vẫn bắt buộc thêm điều kiện `isStaffUser = true`. Cư dân sở hữu `INVOICE:SPECIAL` vẫn bị chặn hủy hóa đơn.
  - Trong `InvoiceGenerationController`: Thỏa mãn quyền, nhưng sinh hóa đơn và xem lịch sử batch vẫn đòi hỏi `isStaffUser = true`.
  - Trong `DebtReminderController`: Thỏa mãn quyền, nhưng gửi nhắc nợ vẫn đòi hỏi `isStaffUser = true`.
  - Trong `BuildingStructureController`: **BỊ CHẶN 403**. Controller hạ tầng chỉ chấp nhận `BUILDING:SPECIAL`, hoàn toàn không nhận `INVOICE:SPECIAL`.
- **Tình trạng Test Coverage:** **TEST COVERAGE GAP**. Test suite hiện tại chưa có test case assert riêng biệt cho người dùng mang quyền `INVOICE:SPECIAL`.

### 3.4. `SYSTEM:SPECIAL` có vô tình trở thành wildcard cho mọi phân hệ không?
- **Logic Backend:** **KHÔNG TRỞ THÀNH WILDCARD NGUY HIỂM**.
  - `SYSTEM:SPECIAL` chỉ vượt qua điều kiện kiểm tra permission ở tầng Controller nhằm phục vụ quản trị viên cấp cao cứu hộ hệ thống.
  - Nó không bypass được domain validation, quy tắc dữ liệu CSDL và điều kiện `isStaffUser`.
- **Tình trạng Test Coverage:** **TEST COVERAGE GAP**. Chưa có test case kiểm thử riêng cho tài khoản chỉ mang quyền `SYSTEM:SPECIAL`.

### 3.5. Người chỉ có VIEW không thể thực hiện thao tác ghi/xóa
- **Logic Backend & Test Coverage:** **ĐÃ ĐƯỢC BAO PHỦ ĐẦY ĐỦ (COVERED)**.
  - Test 05: Người chỉ có `INVOICE:VIEW` bị từ chối 403 khi gọi `preview` và `generate` hóa đơn.
  - Test 07: Người có `INVOICE:VIEW`, `INVOICE:UPDATE`, `INVOICE:APPROVE` (không có `DELETE`) bị từ chối 403 khi hủy hóa đơn.
  - Test 08: Người có `INVOICE:VIEW` bị từ chối 403 khi gửi nhắc nợ.
  - Test 13: Người có `BUILDING:VIEW` bị từ chối 403 khi tạo, sửa, xóa tầng.
  - Test 15: Người có `APARTMENT:VIEW` bị từ chối 403 khi tạo hoặc xóa căn hộ.

---

## 4. KIỂM TRA PHP SYNTAX VÀ PINT STYLE

### 4.1. Cú pháp PHP
- Các file Controller trong Stage 2A:
  - `app/Http/Controllers/InvoiceManagementController.php`
  - `app/Http/Controllers/InvoiceGenerationController.php`
  - `app/Http/Controllers/DebtReminderController.php`
  - `app/Http/Controllers/BuildingStructureController.php`
- Cả 4 file đều tuân thủ nghiêm ngặt cú pháp PHP 8.2+, có đầy đủ kiểu dữ liệu tham số, return type hints, và cấu trúc khối mở/đóng chuẩn xác. Lệnh `docker exec smart_cassavas_app php -l ...` sẵn sàng chạy khi container online.

### 4.2. Định dạng Pint
- Đã được định dạng bằng Laravel Pint ở phiên trước.
- Lệnh sẵn sàng kiểm tra chế độ test (không sửa file):
  ```powershell
  docker exec smart_cassavas_app vendor/bin/pint --test app/Http/Controllers/InvoiceManagementController.php app/Http/Controllers/InvoiceGenerationController.php app/Http/Controllers/DebtReminderController.php app/Http/Controllers/BuildingStructureController.php
  ```

---

## 5. KIỂM TRA PHẠM VI GIT (GIT AUDIT)

### 5.1. Kết quả lệnh Git
```powershell
git status --short
git diff --name-only
git diff --stat
git diff --check
git ls-files --others --exclude-standard
```

### 5.2. Thống kê file thay đổi trong Stage 2A
1. `app/Http/Controllers/BuildingStructureController.php` (+100 lines, -1 line)
2. `app/Http/Controllers/DebtReminderController.php` (+172 lines, -1 line)
3. `app/Http/Controllers/InvoiceGenerationController.php` (+117 lines, -6 lines)
4. `app/Http/Controllers/InvoiceManagementController.php` (+231 lines, -16 lines)

### 5.3. File Test mới (Untracked - không nằm trong diff stat)
- `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` (596 dòng, 17 test cases).

### 5.4. Độ sạch mã nguồn (Cleanliness)
- `git diff --check` trên 4 files Stage 2A trả về **exit code 0** (không có trailing whitespace, không có blank lines at EOF).
- Không thực hiện reset, checkout, clean, commit, push, git add . hay hoàn tác file của bất kỳ thành viên nào.

---

## 6. LỖI VÀ RỦI RO CÒN LẠI (REMAINING RISKS)

1. **Docker Daemon chưa khởi chạy trên host:** Cần bật Docker Desktop để chạy test thực tế trên container.
2. **Sai lệch đường dẫn test hồi quy:** `tests/Feature/Rbac/RbacSecurityTest.php` không tồn tại; cần chạy đúng đường dẫn `tests/Feature/RbacSecurityTest.php`.
3. **Các khoảng trống Test Coverage (Coverage Gaps):**
   - Chưa có test case cho trường hợp cấp độc lập `INVOICE:UPDATE` hoặc `INVOICE:APPROVE` gửi nhắc nợ thành công.
   - Chưa có test case trực tiếp cho `INVOICE:SPECIAL` và `SYSTEM:SPECIAL`.
   - *Lưu ý:* Theo chỉ thị nghiêm ngặt của giai đoạn này ("Không tự ý sửa test hoặc source"), các khoảng trống này được ghi nhận chính xác để phục vụ giai đoạn bổ sung test sau, không tự ý can thiệp mã nguồn lúc này.

---

## 7. KẾT LUẬN VÀ TRẠNG THÁI NGHIỆM THU

> **KẾT LUẬN: RUNTIME PENDING (CHỜ CHẠY RUNTIME KHI DOCKER ONLINE)**

- **Không ghi nhận PASS** cho phần thực thi runtime vì Docker daemon hiện đang offline.
- Toàn bộ phân tích mã nguồn, đối chiếu logic OR/AND, kiểm tra semantics của SPECIAL, và rà soát Git scope đã hoàn tất đầy đủ, minh bạch.
- **Dừng lại tại Stage 2A, không chuyển sang Stage 2B** để chờ quyết định tiếp theo từ quản lý.

