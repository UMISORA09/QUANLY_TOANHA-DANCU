# BÁO CÁO TRIỂN KHAI BUG-03: MỞ QUYỀN ĐĂNG KÝ TẠM TRÚ / TẠM VẮNG CHO CƯ DÂN
**Repository**: `UMISORA09/QUANLY_TOANHA-DANCU`  
**Chức năng**: Quản lý Đăng ký Tạm Trú / Tạm Vắng (Chức năng 3)  
**Phân loại mức độ**: HIGH (Lỗi phân quyền & IDOR Security Gap)  
**Trạng thái**: IMPLEMENTED & FULLY VERIFIED (59/59 tests PASS)  
**Ngày hoàn thành**: 04/11/2026  

---

## 1. NGUYÊN NHÂN ĐÃ KHẮC PHỤC

1. **Nguyên nhân cốt lõi (Root Cause)**:
   - Trước đây, `TemporaryRegistrationRequest::authorize()` và `TemporaryRegistrationController::checkAdminAuthorization()` ép cứng chỉ cho phép `SUPER_ADMIN` và `BUILDING_MANAGER`.
   - Mọi tài khoản cư dân hợp lệ (`RESIDENT_OWNER`, `RESIDENT_MEMBER`) khi gửi request tạo (`POST`), sửa (`PUT/PATCH`), xem danh sách (`index`), xem chi tiết (`show`), xóa (`destroy`), hoặc tải ảnh CCCD (`uploadCccd`) đều bị chặn ngay lập tức với lỗi `403 Forbidden` (`"Bạn không có quyền quản trị để thực hiện thao tác này."`).

2. **Lỗ hổng bảo mật tiềm ẩn (IDOR & Mass Assignment Gap)**:
   - Trước đây backend tin cậy trực tiếp các tham số `resident_id` và `apartment_id` gửi lên từ client mà không ràng buộc theo tài khoản đăng nhập (`$request->user()`).
   - Nếu mở quyền cho Cư dân mà không siết chặt Scoping, cư dân có thể khai báo thay cho cư dân phòng khác (IDOR), hoặc xem/sửa hồ sơ của căn hộ khác trong tòa nhà.

---

## 2. DANH SÁCH FILE THAY ĐỔI THỰC TẾ

### Phía Backend (3 files):
1. **[app/Http/Requests/TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php)**:
   - Cập nhật `authorize()`: Cho phép Admin/Manager và Cư dân có quan hệ cư trú hợp lệ (`is_active = true`).
   - Thêm kiểm tra quyền truy cập chi tiết theo thao tác (`POST`: kiểm tra `apartment_id` và `resident_id` thuộc căn hộ của user; `PUT/PATCH`: kiểm tra quyền sở hữu hồ sơ).
   - Thêm `prepareForValidation()`: Tự động hỗ trợ điền `apartment_id` và `resident_id` hợp lệ nếu cư dân không truyền.
2. **[app/Http/Controllers/TemporaryRegistrationController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/TemporaryRegistrationController.php)**:
   - Giữ nguyên `checkAdminAuthorization()` cho các quyền quản trị độc quyền: `approve()`, `reject()`, `submitToPolice()`.
   - Bổ sung các helper phân quyền: `isUserAdmin()`, `isUserActiveResident()`, `canAccessRegistration()`, `canModifyRegistration()`.
   - `index()`: Truyền `$scopedUser` cho `TemporaryRegistrationService::list()` để áp dụng Query Scoping cho cư dân, trong khi Admin vẫn xem toàn bộ tòa nhà.
   - `show()`, `update()`, `destroy()`, `exportForm()`, `downloadForm()`: Xác thực quyền sở hữu hồ sơ của cư dân; chặn 403 Forbidden nếu cố truy cập hồ sơ căn hộ khác.
   - `uploadCccd()`: Cho phép cư dân tải ảnh CCCD cho hồ sơ của mình; chặn 403 Forbidden nếu tải vào hồ sơ của người khác.
3. **[app/Services/TemporaryRegistrationService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/TemporaryRegistrationService.php)**:
   - Bổ sung tham số `?User $scopedUser = null` vào phương thức `list()`.
   - Khi `$scopedUser` là Cư dân: Tự động lọc truy vấn chỉ lấy hồ sơ thuộc các căn hộ mà người dùng là chủ hộ/chủ sở hữu, hoặc các hồ sơ của chính nhân khẩu đó (`whereIn('apartment_id', $headApartmentIds)` hoặc `orWhereIn('resident_id', $ownResidentIds)`).

### Phía Frontend (3 files):
1. **[resources/js/Components/ResidentTemporaryRegistrationPanel.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/ResidentTemporaryRegistrationPanel.tsx)** *(Tạo mới)*:
   - Giao diện dành riêng cho cư dân: Thống kê KPI cá nhân (Chờ xử lý, Đã gửi CA, Đã duyệt, Từ chối).
   - Form khai báo Tạm trú / Tạm vắng trực quan kèm upload ảnh CCCD 2 mặt và xem preview.
   - Danh sách thẻ hồ sơ kèm trạng thái xử lý, mã hồ sơ Công An, lý do.
   - Nút Chỉnh sửa / Xóa chỉ hiển thị khi hồ sơ còn ở trạng thái `PENDING` / `PENDING_POLICE_SUBMISSION`.
   - Nút Tải Biểu mẫu CT01 chuẩn Công An khi hồ sơ đã được duyệt.
   - Tích hợp Realtime SSE qua `useRealtimeSync` tự động cập nhật khi Admin duyệt hoặc nộp Công An.
2. **[resources/js/Components/Layout/navigationConfig.ts](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Components/Layout/navigationConfig.ts)**:
   - Bổ sung menu item `temporary_registrations` vào mục Menu Cư dân (Resident Portal).
3. **[resources/js/Pages/ResidentHome.tsx](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Pages/ResidentHome.tsx)**:
   - Nhúng `ResidentTemporaryRegistrationPanel` vào cây điều hướng tab của Cổng Cư Dân.

### Phía Kiểm Thử (1 file mới):
1. **[tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php)** *(Tạo mới)*:
   - Bao phủ 22 kịch bản kiểm thử toàn diện cho cư dân, chống IDOR, kiểm soát đa thành viên hộ gia đình, và bảo vệ quyền quản trị.

---

## 3. SO SÁNH CƠ CHẾ PHÂN QUYỀN TRƯỚC VÀ SAU

| Endpoint & Hành động | Cơ chế Trước đây | Cơ chế Sau khi khắc phục BUG-03 |
| :--- | :--- | :--- |
| **`GET /residents/temporary-registrations`** (Xem danh sách) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Admin**: Xem toàn bộ tòa nhà.<br>**Cư dân**: Tự động Query Scoping chỉ hiển thị hồ sơ thuộc căn hộ/nhân khẩu của mình. |
| **`POST /residents/temporary-registrations`** (Tạo mới) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Cư dân**: Được phép tạo cho căn hộ của mình. Backend xác thực nghiêm ngặt quan hệ sở hữu. Chặn mạo danh 403. |
| **`GET /residents/temporary-registrations/{id}`** (Chi tiết) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Cư dân**: Xem được hồ sơ của mình/căn hộ mình. Cố tình truy cập hồ sơ căn hộ khác bị chặn **403 Forbidden**. |
| **`PUT /residents/temporary-registrations/{id}`** (Cập nhật) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Cư dân**: Sửa hồ sơ của mình khi còn chờ duyệt (`PENDING`). Cố sửa của người khác bị **403**; sửa khi đã duyệt bị **409**. |
| **`DELETE /residents/temporary-registrations/{id}`** (Xóa) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Cư dân**: Xóa hồ sơ của mình khi còn chờ duyệt. Cố xóa của người khác bị **403**; xóa khi đã duyệt bị **409**. |
| **`POST /upload-cccd`** (Tải ảnh CCCD) | Chỉ Admin/Manager. Cư dân bị **403 Forbidden**. | **Cư dân**: Tải ảnh CCCD cho hồ sơ của mình. Tải vào hồ sơ người khác bị chặn **403 Forbidden**. |
| **`POST /{id}/approve`** (Phê duyệt) | Chỉ Admin/Manager. | **Admin/Manager Only**: Cư dân gọi bị chặn **403 Forbidden**. |
| **`POST /{id}/reject`** (Từ chối) | Chỉ Admin/Manager. | **Admin/Manager Only**: Cư dân gọi bị chặn **403 Forbidden**. |
| **`POST /{id}/submit-police`** (Nộp CA) | Chỉ Admin/Manager. | **Admin/Manager Only**: Cư dân gọi bị chặn **403 Forbidden**. |
| **`GET /{id}/export`, `download`** (Biểu mẫu CT01) | Chỉ Admin/Manager. | **Cư dân**: Xem và tải biểu mẫu CT01 của hồ sơ thuộc quyền sở hữu của mình. Không xem được của người khác. |

---

## 4. CƠ CHẾ XÁC MINH DANH TÍNH & BẢO MẬT (CHỐNG IDOR & MASS ASSIGNMENT)

### 4.1. Xác minh từ Database Session
- Backend sử dụng `$request->user()` đã được xác thực an toàn qua middleware `auth.bearer`.
- Tuyệt đối không đọc danh tính từ tham số `user_id` của request. Mọi tham số `user_id` truyền lên từ client đều bị FormRequest loại bỏ hoàn toàn khỏi mảng validated.

### 4.2. Chống IDOR (Insecure Direct Object References)
- **Khi tạo mới (`store`)**:
  - `apartment_id` gửi lên phải tương ứng với bản ghi trong bảng `residents` có `user_id = $currentUser->id` và `is_active = true`. Nếu không thuộc -> Chặn **403 Forbidden**.
  - `resident_id` gửi lên:
    - Nếu là chính user (`resident.user_id === $currentUser->id`) -> Chấp nhận.
    - Nếu là người khác trong căn hộ: Chỉ chấp nhận nếu người dùng hiện tại là Chủ hộ (`is_head_of_household = true`, `resident_type = 'OWNER'`, hoặc vai trò `RESIDENT_OWNER`) và đối tượng đăng ký có `apartment_id` cùng căn hộ. Nếu là thành viên thường đăng ký cho người khác -> Chặn **403 Forbidden**.
- **Khi truy cập / cập nhật / xóa (`show`, `update`, `destroy`, `uploadCccd`)**:
  - Controller tra cứu bản ghi và xác minh quan hệ qua `canAccessRegistration()` / `canModifyRegistration()`. Cư dân khác căn hộ không thể can thiệp dữ liệu -> Chặn **403 Forbidden**.

### 4.3. Chống Mass Assignment & Bảo toàn Trạng thái
- Khi tạo mới: Backend ép cứng `police_status = 'PENDING_POLICE_SUBMISSION'`, `reviewed_by = null`, `reviewed_at = null`. Client không thể tự đặt trạng thái đã duyệt hoặc gán tên người duyệt.
- Khi cập nhật: `TemporaryRegistrationService::update()` loại bỏ triệt để các trường nhạy cảm: `reviewed_by`, `reviewed_at`, `police_status`, `resident_id`, `apartment_id`.

---

## 5. KẾT QUẢ KIỂM THỬ THỰC TẾ (PHPUNIT TEST RESULTS)

### 5.1. Bộ test mới: `ResidentTemporaryRegistrationTest.php` (22/22 PASS)
```
   PASS  Tests\Feature\TemporaryRegistrations\ResidentTemporaryRegistrationTest
  ✓ 01 resident creates temporary stay successfully
  ✓ 02 resident creates temporary absence successfully
  ✓ 03 resident cannot spoof user id or admin reviewer
  ✓ 04 resident cannot spoof resident id of another resident
  ✓ 05 resident cannot register for unauthorized apartment
  ✓ 06 resident list scoped cannot see other apartments
  ✓ 07 resident cannot view detail of another apartment record
  ✓ 08 resident cannot update another resident record
  ✓ 09 resident cannot delete another resident record
  ✓ 10 resident cannot upload cccd to another resident record
  ✓ 11 resident cannot approve reject or submit police
  ✓ 12 unauthenticated guest cannot access apis
  ✓ 13 admin retains full management and approval rights
  ✓ 14 legacy records and locked status rules
  ✓ 15 head of household can register for member in same apartment
  ✓ 16 resident member can register for themselves
  ✓ 17 resident cannot update record once approved
  ✓ 18 resident cannot delete record once approved
  ✓ 19 resident can download ct01 form for own approved record
  ✓ 20 resident cannot download ct01 form for another apartment record
  ✓ 21 resident can view own export ct01 data
  ✓ 22 resident cannot view export ct01 data for another apartment

  Tests: 22 passed (59 assertions)
```

### 5.2. Toàn bộ các bộ test Temporary Registrations (59/59 PASS)
```
  ResidentTemporaryRegistrationTest:        22 passed (59 assertions)
  TemporaryRegistrationTest:                17 passed (80 assertions)
  TemporaryRegistrationUpdateDeleteTest:    20 passed (71 assertions)
  -------------------------------------------------------------------
  Tổng cộng Temporary Registrations:        59 passed (210 assertions), 0 failed
```

### 5.3. Kiểm thử hồi quy các phân hệ liên quan (100% PASS)
- **AuthSecurityRegressionTest (BUG-02)**: **18/18 PASS** (44 assertions).
- **ReceptionParcelTest (BUG-01 & BUG-05)**: **22/22 PASS** (89 assertions).
- **RealtimeSyncTest (BUG-05 SSE & Broadcast)**: **81/81 PASS** (218 assertions).
- **PHP Syntax Check (`php -l`)**: 100% PASS, không có lỗi cú pháp.
- **TypeScript Typecheck (`tsc --noEmit`)**: 100% PASS, không có lỗi kiểu dữ liệu.

---

## 6. TÓM TẮT THAY ĐỔI GIT (GIT DIFF STAT)

```
 git diff --stat app/Http/Controllers/TemporaryRegistrationController.php app/Http/Requests/TemporaryRegistrationRequest.php app/Services/TemporaryRegistrationService.php resources/js/Components/Layout/navigationConfig.ts resources/js/Pages/ResidentHome.tsx

 app/Http/Controllers/TemporaryRegistrationController.php | 271 ++++++++++++++++++---
 app/Http/Requests/TemporaryRegistrationRequest.php      | 175 ++++++++++++-
 app/Services/TemporaryRegistrationService.php           |  41 +++-
 resources/js/Components/Layout/navigationConfig.ts      |   1 +
 resources/js/Pages/ResidentHome.tsx                     |   4 +
 5 files changed, 457 insertions(+), 35 deletions(-)

 New files (untracked):
 resources/js/Components/ResidentTemporaryRegistrationPanel.tsx
 tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php
 BUG_03_IMPLEMENTATION_REPORT.md
```
- `git diff --check`: **Không có cảnh báo trailing whitespace hay conflict marker nào**.
- **Working tree được bảo toàn nguyên vẹn 100%**: Không sử dụng `git reset`, `git clean` hay đụng chạm đến code của các thành viên khác.

---

## 7. RỦI RO CÒN LẠI & KẾT LUẬN

- **Rủi ro còn lại**: Rất thấp (None). Các luồng Admin và Concurrency Locking (`lockForUpdate`, `updated_at`) được giữ nguyên vẹn 100%. Cư dân được mở quyền đúng phạm vi căn hộ và hồ sơ hợp lệ.
- **Kết luận**: BUG-03 đã được xử lý triệt để, bảo mật, đáp ứng đầy đủ tất cả 22 kịch bản kiểm thử bắt buộc và giữ vững sự ổn định của toàn bộ dự án.

