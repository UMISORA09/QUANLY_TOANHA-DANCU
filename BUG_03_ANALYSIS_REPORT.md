# BUG-03: BÁO CÁO PHÂN TÍCH LỖI ĐĂNG KÝ TẠM TRÚ / TẠM VẮNG
**Hệ thống**: Quản lý Tòa nhà & Cư dân (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Chức năng**: Quản lý Đăng ký Tạm Trú / Tạm Vắng (Chức năng 3)  
**Phân loại mức độ**: HIGH (Lỗi phân quyền & IDOR Security Gap)  
**Chế độ thực hiện**: STAGE 1 – REVIEW ONLY (Tuyệt đối không sửa mã nguồn trước khi duyệt)  
**Ngày lập**: 04/11/2026  

---

## 1. TÓM TẮT ĐIỀU HÀNH (EXECUTIVE SUMMARY)

Báo cáo phân tích toàn diện luồng nghiệp vụ Đăng ký Tạm trú (`TEMPORARY_STAY`) và Tạm vắng (`TEMPORARY_ABSENCE`) từ tầng Routing, Middleware, Controller, FormRequest, Service, Model cho đến RBAC và giao diện Frontend.

### Kết quả xác minh nguyên nhân chính (Root Cause Status)
- **CONFIRMED**: Cư dân (`RESIDENT_OWNER`, `RESIDENT_MEMBER`) hoàn toàn **bị chặn truy cập** tại hai tầng:
  1. `TemporaryRegistrationRequest::authorize()` (Dòng 14–31): Chỉ cấp phép cho `SUPER_ADMIN` và `BUILDING_MANAGER`. Cư dân gửi request tạo (`POST`) hoặc cập nhật (`PUT/PATCH`) hồ sơ đều bị Laravel chặn ngay lập tức và trả về **HTTP 403 Forbidden**.
  2. `TemporaryRegistrationController::checkAdminAuthorization()` (Dòng 23–47): Kiểm tra cứng vai trò Quản trị viên (`SUPER_ADMIN`, `BUILDING_MANAGER`). Chặn cư dân ở hầu hết các endpoint: xem danh sách (`index`), xem chi tiết (`show`), xóa (`destroy`), và upload ảnh CCCD (`uploadCccd`).
- **CONFIRMED (Lỗ hổng bảo mật tiềm ẩn - IDOR & Scope Leaking)**:
  - Backend đang hoàn toàn tin tưởng `resident_id` và `apartment_id` gửi lên từ client trong payload.
  - `TemporaryRegistrationService::create()` chỉ kiểm tra tính hợp lệ nội tại giữa `Resident` và `Apartment` (`Resident::apartment_id === $apartmentId`), **hoàn toàn không xác thực** mối quan hệ sở hữu/lưu trú giữa Người dùng đang đăng nhập (`$request->user()`) với cư dân hoặc căn hộ đó.
  - Nếu chỉ mở quyền role cho Cư dân một cách thô sơ mà không siết chặt Scoping, cư dân có thể khai báo mạo danh cho bất kỳ ai, xem dữ liệu của toàn bộ cư dân trong tòa nhà, hoặc sửa/xóa hồ sơ của căn hộ khác.

---

## 2. TRẢ LỜI 9 CÂU HỎI XÁC MINH CỐT LÕI

| STT | Câu hỏi kiểm tra | Kết luận | Chi tiết kỹ thuật & Dẫn chứng mã nguồn |
| :---: | :--- | :---: | :--- |
| **1** | Tài khoản cư dân bị chặn tại đâu? | **FormRequest & Controller** | - Tuyệt đối không bị chặn ở Route/Middleware (`auth.bearer` chấp nhận token cư dân).<br>- Bị chặn tại `TemporaryRegistrationRequest::authorize()` (Dòng 26) khi gọi `store` / `update`.<br>- Bị chặn tại `TemporaryRegistrationController::checkAdminAuthorization()` (Dòng 38) khi gọi `index`, `show`, `destroy`, `uploadCccd`. |
| **2** | Role nào hiện được phép đăng ký tạm trú? | **Chỉ Admin & Manager** | Chỉ có `SUPER_ADMIN` và `BUILDING_MANAGER`. Cư dân (`RESIDENT_OWNER`, `RESIDENT_MEMBER`, `TENANT`) đều bị từ chối 403. |
| **3** | Role nào hiện được phép đăng ký tạm vắng? | **Chỉ Admin & Manager** | Tương tự tạm trú, cả hai loại đăng ký dùng chung FormRequest và Controller, nên cư dân không thể tự đăng ký tạm vắng. |
| **4** | Cư dân có thể xem danh sách đăng ký của chính mình không? | **KHÔNG** | API `GET /api/v1/residents/temporary-registrations` gọi ngay `checkAdminAuthorization()` ở dòng 54 và trả về 403 FORBIDDEN `"Bạn không có quyền quản trị để thực hiện thao tác này."`. |
| **5** | Có kiểm tra cư dân thuộc căn hộ đang đăng ký không? | **CÓ (Một phần)** | `TemporaryRegistrationService::create()` (Dòng 145–156) kiểm tra `Resident::where('id', $residentId)->value('apartment_id') === $apartmentId`. Nhưng **KHÔNG kiểm tra** người gửi request có thuộc căn hộ hay là cư dân đó không. |
| **6** | Backend có tin tưởng `user_id`, `resident_id`, `apartment_id` từ frontend? | **CÓ** | Controller lấy trực tiếp `$request->validated()` và đẩy sang Service tạo bản ghi mà không gán hoặc ràng buộc theo `$request->user()->id`. |
| **7** | Nguy cơ cư dân đăng ký thay người khác hoặc truy cập dữ liệu căn hộ khác? | **CỰC KỲ CAO (IDOR)** | Nếu chỉ mở quyền role mà không bổ sung Data Scoping và Ownership Validation, cư dân A có thể gửi `resident_id` của cư dân B ở căn hộ khác để khai báo, hoặc xem/sửa hồ sơ căn hộ khác. |
| **8** | Luồng xử lý có bước chờ duyệt hoặc xác nhận của Admin/Manager không? | **CÓ** | Bản ghi khi tạo mới luôn nhận trạng thái `police_status = 'PENDING_POLICE_SUBMISSION'`. Quản trị viên sau đó thực hiện: `submitToPolice()` -> `approve()` hoặc `reject()`. Cư dân tuyệt đối không được phép tự duyệt. |
| **9** | Mở quyền cho cư dân ảnh hưởng chức năng quản lý hiện tại như thế nào? | **Có thể phá vỡ quản lý nếu không phân tách scope** | Ban Quản Lý cần xem **tất cả** hồ sơ của toàn bộ tòa nhà, trong khi Cư dân chỉ được xem **hồ sơ thuộc căn hộ của mình**. Phải phân biệt rõ ràng Context: Admin = Unscoped; Cư dân = Scoped. |

---

## 3. DANH SÁCH FILE LIÊN QUAN VÀ VỊ TRÍ MÃ NGUỒN

### 3.1. Phía Backend (Core Logic)
1. **[app/Http/Controllers/TemporaryRegistrationController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/TemporaryRegistrationController.php)**:
   - `L23–47`: Phương thức `checkAdminAuthorization()` ép cứng chỉ cho phép `SUPER_ADMIN` và `BUILDING_MANAGER`.
   - `L52–70`: Phương thức `index()` chặn cư dân xem danh sách hồ sơ của mình.
   - `L75–95`: Phương thức `show()` chặn cư dân xem chi tiết hồ sơ của mình.
   - `L100–109`: Phương thức `store()` tạo mới hồ sơ.
   - `L114–137`: Phương thức `update()` cập nhật thông tin hồ sơ.
   - `L142–168`: Phương thức `destroy()` chặn cư dân xóa hồ sơ nháp/chờ xử lý của mình.
   - `L173–205`, `L210–246`, `L251–283`: Các phương thức duyệt `approve()`, từ chối `reject()`, gửi CA `submitToPolice()` (Phải giữ nguyên quyền Quản trị).
   - `L288–313`: Phương thức `uploadCccd()` bị chặn bởi `checkAdminAuthorization()`, khiến cư dân không upload được ảnh CCCD.
   - `L318–356`: Phương thức `exportForm()` và `downloadForm()` xuất biểu mẫu CT01.
2. **[app/Http/Requests/TemporaryRegistrationRequest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Requests/TemporaryRegistrationRequest.php)**:
   - `L14–31`: `authorize()` chặn mọi role ngoài `SUPER_ADMIN` và `BUILDING_MANAGER`. Cần mở quyền cho người dùng có hồ sơ cư dân hợp lệ (`$user->residents()->exists()` hoặc các role `RESIDENT_OWNER`, `RESIDENT_MEMBER`).
   - `L36–65`: `rules()` validate `resident_id` và `apartment_id`. Cần bổ sung rule hoặc logic xác thực quyền sở hữu giữa user hiện tại và cư dân/căn hộ.
3. **[app/Services/TemporaryRegistrationService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/TemporaryRegistrationService.php)**:
   - `L23–110`: `list(array $params = [])` hiện tại không có tham số `$user`. Cần tiếp nhận User hoặc hỗ trợ lọc theo căn hộ của cư dân khi gọi từ ngữ cảnh cư dân.
   - `L137–190`: `create(array $data)` nạp dữ liệu và phát sự kiện realtime.
   - `L203–244`: `update(string $id, array $data)` đã có Optimistic Locking qua `updated_at` và chặn sửa khi trạng thái khác `PENDING`/`PENDING_POLICE_SUBMISSION`.
   - `L272–294`: `delete(string $id)` chặn xóa khi trạng thái khác `PENDING`/`PENDING_POLICE_SUBMISSION`.
4. **[routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L487-L514)**:
   - Tuyến đường `Route::prefix('api/v1')->middleware(['auth.bearer', 'cooldown:temporary_registrations'])`.
   - Có cả hai tiền tố: `/api/v1/residents/temporary-registrations` và alias `/api/v1/temporary-registrations`. Middleware `auth.bearer` đã xác thực người dùng thành công.

### 3.2. Phía Model & Database Relationship
1. **[app/Models/User.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/User.php#L96-L99)**:
   - Quan hệ `public function residents(): HasMany` trỏ đến `Resident`.
   - Các helper: `hasRole($roles)`, `isSuperAdmin()`.
2. **[app/Models/Resident.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/Resident.php)**:
   - Các trường: `user_id`, `apartment_id`, `resident_type`, `is_head_of_household`, `is_active`, `stay_start_date`, `stay_end_date`.
   - Quan hệ: `user(): BelongsTo`, `apartment(): BelongsTo`.
3. **[app/Models/TemporaryRegistration.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Models/TemporaryRegistration.php)**:
   - Khóa ngoại: `resident_id`, `apartment_id`, `reviewed_by`.
   - Trạng thái `police_status`: `PENDING_POLICE_SUBMISSION`, `SUBMITTED_TO_POLICE`, `APPROVED`, `REJECTED`.

---

## 4. MA TRẬN PHÂN QUYỀN HIỆN TẠI VÀ MỤC TIÊU (ROLE-PERMISSION MATRIX)

```
+------------------------------------+---------------------+---------------------+---------------------+
| Chức năng / API Endpoint           | SUPER_ADMIN/MANAGER | RESIDENT (Hiện tại) | RESIDENT (Mục tiêu) |
+------------------------------------+---------------------+---------------------+---------------------+
| GET /residents/temporary-regs      | Cho phép (Tất cả)   | Bị từ chối (403)    | Xem hồ sơ của mình  |
| GET /residents/temporary-regs/{id} | Cho phép (Tất cả)   | Bị từ chối (403)    | Xem hồ sơ của mình  |
| POST /residents/temporary-regs     | Cho phép            | Bị từ chối (403)    | Đăng ký cho mình    |
| PUT /residents/temporary-regs/{id} | Cho phép (Chờ duyệt)| Bị từ chối (403)    | Sửa hồ sơ của mình  |
| DEL /residents/temporary-regs/{id} | Cho phép (Chờ duyệt)| Bị từ chối (403)    | Xóa hồ sơ của mình  |
| POST /upload-cccd                  | Cho phép            | Bị từ chối (403)    | Cho phép upload     |
| POST /{id}/approve                 | Cho phép            | Bị từ chối (403)    | BỊ TỪ CHỐI (403)    |
| POST /{id}/reject                  | Cho phép            | Bị từ chối (403)    | BỊ TỪ CHỐI (403)    |
| POST /{id}/submit-police           | Cho phép            | Bị từ chối (403)    | BỊ TỪ CHỐI (403)    |
| GET /{id}/export, download         | Cho phép            | Bị từ chối (403)    | Xem/Tải CT01 của mk |
+------------------------------------+---------------------+---------------------+---------------------+
```

---

## 5. PHÂN TÍCH RỦI RO BẢO MẬT VÀ DỮ LIỆU (SECURITY & INTEGRITY RISKS)

### 5.1. Nguy cơ IDOR (Insecure Direct Object References)
- Nếu chỉ cho phép cư dân gửi request tạo hồ sơ với bất kỳ `resident_id` hoặc `apartment_id` nào:
  - Cư dân phòng 101 có thể tạo đơn tạm trú/tạm vắng mạo danh cho cư dân phòng 909.
  - Cư dân có thể tải hồ sơ CCCD cá nhân của cư dân khác nếu sửa UUID trên URL `show` hoặc `export`.
- **Yêu cầu giải pháp**:
  - Đối với Cư dân (Non-Admin): Phải kiểm tra quyền sở hữu căn hộ/nhân khẩu. Cụ thể: `apartment_id` gửi lên phải nằm trong danh sách các căn hộ mà người dùng hiện tại có quan hệ cư trú hợp lệ (`residents.user_id = $currentUser->id` và `is_active = true`). Đồng thời `resident_id` phải tương ứng với bản ghi nhân khẩu của chính họ hoặc người thuộc cùng căn hộ đó.

### 5.2. Nguy cơ rò rỉ dữ liệu danh sách (Data Scoping Bypass)
- Trong `index()`: Admin cần xem danh sách toàn bộ tòa nhà để tổng hợp và nộp Công An. Cư dân **tuyệt đối không được thấy** hồ sơ của căn hộ khác (chứa thông tin nhạy cảm: Số CCCD, Họ tên, Ngày sinh, Lý do cư trú, Hình ảnh CCCD 2 mặt).
- **Yêu cầu giải pháp**:
  - Tự động áp dụng Query Scoping: Nếu người dùng không phải Quản trị viên (`isAdmin = false`), cư dân chỉ được truy vấn các hồ sơ có `apartment_id` thuộc quyền cư trú của họ hoặc `resident.user_id = $user->id`.

### 5.3. Rủi ro vượt quyền phê duyệt hồ sơ (Privilege Escalation)
- Nghiệp vụ xét duyệt hồ sơ tạm trú/tạm vắng mang tính chất pháp lý và hành chính với Công An khu vực.
- Cư dân tuyệt đối **không có quyền** gọi các endpoint `approve`, `reject`, `submit-police`. Các endpoint này bắt buộc giữ nguyên kiểm tra `checkAdminAuthorization()`.

---

## 6. ĐỀ XUẤT GIẢI PHÁP TỐI THIỂU (MINIMAL VIABLE FIX)

Để khắc phục triệt để BUG-03 mà không làm ảnh hưởng đến mã nguồn khác, tuân thủ nguyên tắc thay đổi tối thiểu:

### Bước 1: Điều chỉnh Phân quyền trong `TemporaryRegistrationRequest`
- Trong `authorize()`:
  - Cho phép người dùng là Quản trị viên (`isSuperAdmin()` hoặc có role `SUPER_ADMIN`, `BUILDING_MANAGER`).
  - **HOẶC** Người dùng đã đăng nhập và có liên kết cư trú (`$user->residents()->where('is_active', true)->exists()` hoặc có role cư dân `RESIDENT_OWNER`, `RESIDENT_MEMBER`).
- Trong `withValidator()` hoặc `passedValidation()`:
  - Đối với cư dân (non-admin): Xác thực `apartment_id` gửi lên phải thuộc căn hộ mà người dùng cư trú, và `resident_id` phải thuộc căn hộ/người dùng đó.

### Bước 2: Tinh chỉnh Controller `TemporaryRegistrationController`
- Tách bạch hàm kiểm tra quyền:
  - Giữ nguyên `checkAdminAuthorization()` cho các tác vụ độc quyền của Admin: `approve()`, `reject()`, `submitToPolice()`.
  - Đối với `index()`: Cho phép cả Admin và Cư dân. Nếu là Cư dân, tự động truyền `scoped_user` hoặc lọc theo danh sách căn hộ của cư dân.
  - Đối với `show()`, `exportForm()`, `downloadForm()`: Cho phép Admin xem tất cả; Cư dân chỉ xem được hồ sơ thuộc căn hộ/nhân khẩu của mình (trả về 403 nếu cố truy cập hồ sơ căn hộ khác).
  - Đối với `update()`, `destroy()`: Cho phép Admin thao tác tất cả hồ sơ ở trạng thái PENDING; Cư dân chỉ thao tác trên hồ sơ của chính mình/căn hộ mình ở trạng thái PENDING.
  - Đối với `uploadCccd()`: Cho phép người dùng đã xác thực (cả Admin và Cư dân) upload ảnh tài liệu cá nhân.

### Bước 3: Hỗ trợ Scoping trong `TemporaryRegistrationService`
- Trong `list()`: Hỗ trợ nhận diện user hoặc tham số scope danh sách căn hộ hợp lệ để truy vấn an toàn.
- Đảm bảo các logic khóa dòng Concurrency (`lockForUpdate`), Optimistic Locking (`updated_at`), và Phát sự kiện Realtime (`QuocTinRealtimeService::emit`) hoạt động ổn định và chính xác.

---

## 7. ĐỀ XUẤT 10 KỊCH BẢN KIỂM THỬ (TEST CASES REQUIREMENT)

Bổ sung bộ test toàn diện vào `tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php`:

1. **Test 01 – Cư dân đăng ký tạm trú hợp lệ**: Cư dân đăng nhập với token hợp lệ, gửi payload `TEMPORARY_STAY` cho căn hộ của mình -> Trả về `201 Created`, trạng thái `PENDING_POLICE_SUBMISSION`.
2. **Test 02 – Cư dân đăng ký tạm vắng hợp lệ**: Cư dân gửi payload `TEMPORARY_ABSENCE` cho căn hộ của mình -> Trả về `201 Created`.
3. **Test 03 – Cư dân không thể đăng ký cho căn hộ khác (Anti-IDOR)**: Cư dân A gửi request với `apartment_id` của căn hộ B -> Trả về `403 Forbidden` hoặc `422 Unprocessable Entity`.
4. **Test 04 – Cư dân không thể giả mạo resident_id của người khác**: Cư dân A gửi `resident_id` của cư dân phòng khác -> Bị từ chối và chặn ghi dữ liệu.
5. **Test 05 – Cư dân xem danh sách hồ sơ chỉ thấy hồ sơ của mình (Query Scoping)**: Cư dân gọi `GET /api/v1/residents/temporary-registrations` -> Nhận `200 OK`, danh sách chỉ chứa hồ sơ của căn hộ của mình, không thấy hồ sơ căn hộ khác.
6. **Test 06 – Cư dân không thể xem chi tiết hồ sơ căn hộ khác**: Cư dân A gọi `GET .../{id}` của căn hộ B -> Trả về `403 Forbidden`.
7. **Test 07 – Cư dân không thể sửa hoặc xóa hồ sơ của người khác**: Cư dân A gọi `PUT` hoặc `DELETE` trên hồ sơ căn hộ B -> Trả về `403 Forbidden`.
8. **Test 08 – Khách chưa đăng nhập không thể đăng ký**: Gọi API mà không có Bearer token -> Trả về `401 Unauthorized`.
9. **Test 09 – Cư dân không thể tự duyệt hoặc nộp Công An**: Cư dân gọi `POST .../{id}/approve` hoặc `POST .../{id}/submit-police` -> Bị từ chối `403 Forbidden`.
10. **Test 10 – Quản trị viên (Admin/Manager) vẫn hoạt động bình thường**: Admin có thể xem toàn bộ danh sách, phê duyệt, từ chối, nộp Công an và xuất mẫu CT01 mà không bị ảnh hưởng.

---

## 8. CÁC CHỨC NĂNG CẦN KIỂM THỬ HỒI QUY (REGRESSION SCOPE)

Sau khi hoàn thành Stage 2, bắt buộc chạy lại toàn bộ các bộ test sau để đảm bảo không gây lỗi hồi quy:
1. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationTest.php` (28 test methods quản trị và validation ban đầu).
2. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationUpdateDeleteTest.php` (20 test methods xử lý sửa, xóa, concurrency và RBAC).
3. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php` (10 test methods concurrency và lock contention).
4. `tests/Feature/RealtimeSyncTest.php` (Các kênh SSE Realtime đồng bộ tạm trú / tạm vắng).
5. `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` & `ReceptionParcelCheckoutTest.php` (Kiểm thử bảo toàn BUG-01 và BUG-05).
6. `tests/Feature/AuthSecurityRegressionTest.php` (Kiểm thử bảo toàn BUG-02).

---

## 9. ĐỀ XUẤT KẾ HOẠCH TRIỂN KHAI GIAI ĐOẠN 2 (STAGE 2 IMPLEMENTATION PLAN)

| Bước | Nội dung công việc | File tác động | Ghi chú & Ràng buộc |
| :---: | :--- | :--- | :--- |
| **Bước 1** | Cập nhật quyền trong FormRequest | `app/Http/Requests/TemporaryRegistrationRequest.php` | Mở quyền cho Cư dân hợp lệ, thêm xác thực cư dân thuộc căn hộ |
| **Bước 2** | Cập nhật phân quyền & scoping trong Controller | `app/Http/Controllers/TemporaryRegistrationController.php` | Tách biệt kiểm tra Admin với Cư dân, cho phép cư dân `index`, `show`, `store`, `update`, `destroy`, `uploadCccd` với scope căn hộ của mình; Giữ nguyên 403 đối với `approve`, `reject`, `submitToPolice` |
| **Bước 3** | Bổ sung Scoping trong Service | `app/Services/TemporaryRegistrationService.php` | Lọc danh sách theo apartment/resident khi gọi từ cư dân |
| **Bước 4** | Xây dựng Feature Test bao phủ | `tests/Feature/TemporaryRegistrations/ResidentTemporaryRegistrationTest.php` | Tạo mới test suite bao phủ 10 kịch bản kiểm thử cư dân & chống IDOR |
| **Bước 5** | Chạy kiểm thử hồi quy & Pint | Tất cả file liên quan | Đảm bảo 100% tests PASS, Pint style clean, không có lỗi runtime |

---
**KẾT LUẬN & ĐỀ NGHỊ:**  
Giai đoạn phân tích STAGE 1 đã hoàn tất đầy đủ, chính xác và không có bất kỳ dòng mã nguồn nào bị thay đổi.  
**Dừng lại tại đây và chờ phê duyệt của Quản lý trước khi bắt đầu Stage 2 – Triển khai.**

