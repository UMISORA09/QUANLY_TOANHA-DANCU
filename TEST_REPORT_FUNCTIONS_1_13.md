# BÁO CÁO KIỂM THỬ TOÀN DIỆN 13 CHỨC NĂNG — QUANLY_TOANHA-DANCU

- **Repository**: `UMISORA09/QUANLY_TOANHA-DANCU`
- **Branch kiểm thử**: `QuocTin/Fix_all`
- **Ngày thực hiện**: `2026-10-30`
- **Môi trường kiểm thử**:
  - Docker Container: `smart_cassavas_app` (PHP `8.4.25`, Laravel `12.x`, Vite `8.3.3`, TypeScript)
  - Database kiểm thử độc lập: `quanly_toanha_test` (MySQL `8.0` trên `smart_cassavas_db`)
  - Tuyệt đối **không** tác động vào database thật (`quanly_toanha`) và **không** chỉnh sửa bất kỳ file mã nguồn nào của dự án.

---

## I. BẢNG KẾT QUẢ KIỂM THỬ CHI TIẾT (13 CHỨC NĂNG & TÍCH HỢP LIÊN MODULE)

| STT | Chức năng | Test case | Kết quả | Mức độ lỗi | File/API liên quan | Ghi chú |
| :---: | :--- | :--- | :---: | :---: | :--- | :--- |
| **1** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Functional]** Hiển thị danh sách tài khoản (`GET /api/v1/rbac/users`), danh sách vai trò (`GET /api/v1/rbac/roles`) và danh mục quyền (`GET /api/v1/rbac/permissions`) | **PASS** | - | `app/Http/Controllers/UserController.php`, `RoleController.php`, `PermissionController.php`, `resources/js/Pages/Admin/RbacManagement.tsx` | Hiển thị đầy đủ danh sách user, role hệ thống và ma trận quyền hạn theo module. |
| **2** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Functional]** Hiển thị và đồng bộ 7 quyền tiêu chuẩn (`CREATE`, `DELETE`, `UPDATE`, `DOWNLOAD`, `UPLOAD`, `VIEW`, `SPECIAL`) trên từng module | **PASS** | - | `app/Services/RbacService.php` (L23-L99), `resources/js/Pages/Admin/RbacManagement.tsx` (L49-L115), `resources/js/Hooks/usePermission.ts` | `RbacService::ensureStandardPermissionsExist()` tự động khởi tạo đủ 7 action tiêu chuẩn cho 10 module. |
| **3** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Functional]** Cấp/thu hồi quyền theo Role (`PUT /api/v1/rbac/roles/{id}/permissions`) và lưu/tải lại cấu hình phân quyền | **PASS** | - | `app/Http/Controllers/RoleController.php`, `app/Services/RbacService.php` (L515-L563) | Lưu `role_permissions` trong DB transaction, xóa cache quyền và phát sự kiện realtime SSE. |
| **4** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Functional]** Cấp/thu hồi quyền cá nhân hóa theo từng User (`PUT /api/v1/rbac/users/{id}/permissions`) và khôi phục mặc định (`DELETE /api/v1/rbac/users/{id}/permissions`) | **PASS** | - | `app/Http/Controllers/UserController.php` (L221-L296), `app/Services/RbacService.php` (L568-L659) | Tự động tạo/cập nhật role ẩn `CUSTOM_USER_{ID}` để ghi đè quyền riêng cho từng tài khoản. |
| **5** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Permission / Negative]** Tài khoản không có quyền gọi trực tiếp các API quản trị RBAC (`/api/v1/rbac/*`, `/api/v1/admin/*`) | **PASS** | - | `routes/web.php` (L385-L480), `app/Http/Middleware/CheckPermission.php` | Chặn đúng với HTTP `403 FORBIDDEN` (`RbacManagementTest` & `RbacSecurityTest` 46/46 PASS). |
| **6** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Negative / Data Integrity]** Bảo vệ tài khoản `SUPER_ADMIN` cuối cùng không bị xóa hoặc tước quyền quản trị tối cao | **PASS** | - | `app/Services/RbacService.php` (L432-L465, L664-L685) | Ném `RbacConflictException` (HTTP `409`) khi cố xóa hoặc hạ quyền `SUPER_ADMIN` cuối cùng. |
| **7** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Security / Auth]** Kiểm tra đăng nhập (`POST /api/v1/auth/login`), đăng xuất (`POST /api/v1/auth/logout`) và xác thực Bearer Token | **FAIL** | **Critical** | `app/Http/Controllers/AuthController.php` (L139-L142, L411) | **[BUG-02]** `AuthController::login` chứa mật khẩu dự phòng hardcode cho 10 username hệ thống không giới hạn môi trường `local/testing`; `sendRegisterOtp` trả về `debug_otp` trực tiếp trong JSON response. |
| **8** | **1. Phân quyền tài khoản (RBAC Matrix)** | **[Permission / Integration]** Áp dụng cấu hình quyền từ RBAC Matrix (`VIEW`, `CREATE`, `UPDATE`, `DELETE`, `SPECIAL`, ...) lên API của các chức năng 2–13 | **FAIL** | **High** | `app/Http/Controllers/ResidentController.php`, `TemporaryRegistrationController.php`, `VehicleController.php`, `AccessCardController.php`, `StaffScheduleController.php` | **[BUG-04]** Các controller chức năng 2–13 chỉ kiểm tra cứng `hasRole([...])`, hoàn toàn không kiểm tra `hasPermission(...)` từ RBAC Matrix. Thu hồi quyền trên RBAC Matrix không chặn được thao tác gọi API trực tiếp. |
| **9** | **2. Quản lý hộ khẩu và cư dân** | **[Functional]** Thêm cư dân / chủ hộ mới vào căn hộ (`POST /api/v1/residents`), tự động tạo `User` nếu chưa có và chuyển trạng thái căn hộ sang `OCCUPIED` | **PASS** | - | `app/Http/Controllers/ResidentController.php`, `app/Services/ResidentService.php` (L147-L234) | Tạo cư dân thành công (HTTP `201`), gán vai trò `RESIDENT` và cập nhật `apartments.status = OCCUPIED`. |
| **10** | **2. Quản lý hộ khẩu và cư dân** | **[Functional]** Sửa thông tin cư dân (`PUT /api/v1/residents/{id}`), tìm kiếm đa trường (`search`), lọc theo căn hộ/loại cư dân/chủ hộ và phân trang (`per_page`) | **PASS** | - | `app/Services/ResidentService.php` (L27-L95, L242-L348), `resources/js/Pages/Admin/ResidentManagement.tsx` | Hỗ trợ tìm kiếm theo tên, SĐT, email, CCCD, mã căn hộ; phân trang và lọc hoạt động đúng. |
| **11** | **2. Quản lý hộ khẩu và cư dân** | **[Functional / Data Integrity]** Quan hệ chủ hộ (`is_head_of_household = true`) và thành viên: mỗi căn hộ chỉ có tối đa 1 chủ hộ đang hoạt động (kể cả khi 2 Admin thêm đồng thời) | **PASS** | - | `app/Services/ResidentService.php` (L156-L170, L256-L270) | Sử dụng `Apartment::where('id', ...)->lockForUpdate()` ngăn chặn race condition tạo 2 chủ hộ (`ResidentCreateConcurrencyTest` PASS). |
| **12** | **2. Quản lý hộ khẩu và cư dân** | **[Functional / Negative]** Xóa cư dân theo điều kiện nghiệp vụ (`DELETE /api/v1/residents/{id}`): chặn xóa Chủ hộ khi căn hộ còn thành viên khác đang ở | **PASS** | - | `app/Services/ResidentService.php` (L353-L419) | Trả về HTTP `422` khi xóa chủ hộ mà vẫn còn thành viên active; tự động chuyển căn hộ về `AVAILABLE` khi cư dân cuối cùng rời đi. |
| **13** | **2. Quản lý hộ khẩu và cư dân** | **[Negative / Data Integrity]** Kiểm tra dữ liệu trùng lặp (CCCD, Email, SĐT, hoặc gán trùng 1 người vào cùng 1 căn hộ) | **PASS** | - | `app/Http/Requests/ResidentRequest.php`, `app/Services/ResidentService.php` | Chặn trùng email, SĐT, CCCD (HTTP `422`) và chặn gán trùng user đang active vào cùng căn hộ. |
| **14** | **2. Quản lý hộ khẩu và cư dân** | **[Negative / Edge Case]** Thêm lại cư dân đã bị xóa mềm (`soft-deleted`) vào lại chính căn hộ cũ (`POST /api/v1/residents`) | **FAIL** | **Medium** | `app/Services/ResidentService.php` (L172-L220), `tests/Feature/Residents/ResidentCreateTest.php` (L500-L535) | **[BUG-09]** Unique index `uq_resident_apt (user_id, apartment_id)` bao gồm cả bản ghi đã soft-delete. Khi thêm lại user đã xóa mềm vào căn hộ cũ, hệ thống không khôi phục bản ghi mà văng lỗi `ResidentConflictException` (HTTP `500` trong test log). |
| **15** | **2. Quản lý hộ khẩu và cư dân** | **[Permission]** Kiểm tra quyền truy cập API quản lý cư dân (`/api/v1/residents`) đối với tài khoản không có quyền | **PASS** | - | `app/Http/Controllers/ResidentController.php` (L24-L42) | Chặn tài khoản cư dân/lễ tân truy cập danh sách hoặc thêm/sửa/xóa cư dân (HTTP `403`). |
| **16** | **2. Quản lý hộ khẩu và cư dân** | **[UI / Data Integrity]** Kiểm tra thẻ thống kê KPI (Chủ hộ, Chủ sở hữu, Khách thuê, Thành viên) trên trang Quản lý cư dân khi có phân trang | **FAIL** | **Low** | `resources/js/Pages/Admin/ResidentManagement.tsx` (L240-L248) | **[BUG-11]** Các thẻ KPI thành phần chỉ đếm trên mảng `residents` của trang hiện tại (`15` bản ghi/trang) thay vì toàn bộ dữ liệu trong DB, dẫn đến tổng các thẻ con không khớp thẻ "Tổng Cư Dân" (`totalCount`). |
| **17** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Functional]** Tạo đăng ký tạm trú (`TEMPORARY_STAY`) và tạm vắng (`TEMPORARY_ABSENCE`), xem danh sách và xem chi tiết (`GET /api/v1/residents/temporary-registrations/{id}`) | **PASS** | - | `app/Http/Controllers/TemporaryRegistrationController.php`, `app/Services/TemporaryRegistrationService.php` | Tạo hồ sơ thành công ở trạng thái `PENDING` (HTTP `201`), trả về đầy đủ thông tin cư dân, căn hộ, CCCD, SĐT. |
| **18** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Functional / Negative]** Chỉnh sửa (`PUT /api/v1/residents/temporary-registrations/{id}`) và xóa (`DELETE`) khi đủ điều kiện; chặn sửa/xóa khi hồ sơ đã xử lý sai quy định | **PASS** | - | `app/Services/TemporaryRegistrationService.php` (L205-L345) | Cho phép cập nhật/xóa mềm và ghi nhận người thực hiện; kiểm tra `TemporaryRegistrationUpdateDeleteTest` (19/19 PASS). |
| **19** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Functional]** Duyệt (`POST .../approve`), từ chối (`POST .../reject`), cập nhật trạng thái Công an (`POST .../submit-police`) và lưu mã tiếp nhận Công an (`police_reference_code`) | **PASS** | - | `app/Services/TemporaryRegistrationService.php` (L347-L485) | Chuyển trạng thái chính xác (`PENDING` -> `SUBMITTED_TO_POLICE` -> `APPROVED` / `REJECTED`), lưu `reviewed_by_user_id`, `reviewed_at` và `police_reference_code`. |
| **20** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Functional]** Xuất dữ liệu JSON biểu mẫu CT01 (`GET .../export`) và tải file HTML/in biểu mẫu CT01 (`GET .../download`) | **PASS** | - | `app/Http/Controllers/TemporaryRegistrationController.php` (L255-L315), `app/Services/TemporaryRegistrationService.php` (L490-L610) | Sinh đúng biểu mẫu **Mẫu CT01** (Ban hành kèm theo Thông tư số 66/2023/TT-BCA) với đầy đủ thông tin nhân khẩu, CCCD, căn hộ và nội dung đề nghị. |
| **21** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Negative]** Kiểm tra ràng buộc `start_date`, `end_date` (`end_date >= start_date`) và ngăn chặn tạo hồ sơ trùng khoảng thời gian cho cùng cư dân | **PASS** | - | `app/Http/Requests/TemporaryRegistrationRequest.php`, `app/Services/TemporaryRegistrationService.php` (L128-L168) | Chặn `end_date < start_date` (HTTP `422`) và chặn đăng ký trùng lặp thời gian khi có hồ sơ đang `PENDING`/`APPROVED`. |
| **22** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[Functional]** Kiểm tra dữ liệu sau khi tải lại trang và cập nhật realtime SSE (`quoc-tin.temporary-registrations`) | **PASS** | - | `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (L335-L348) | Dữ liệu lưu bền vững trong DB; hook `useRealtimeSync(['quoc-tin.temporary-registrations'])` tự động đồng bộ khi có thay đổi. |
| **23** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[UI / Responsive]** Kiểm tra bảng dữ liệu ở độ phân giải `1920px`, `1440px`, `1280px` (không mất cột, không thanh cuộn ngang, đọc rõ Tên, CCCD, SĐT, căn hộ, lý do, trạng thái, mã Công an, người duyệt, ngày `dd/MM/yyyy`, 4 nút Xem/Sửa/Xóa/CT01) | **PASS** | - | `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (L861-L1098) | Sử dụng `w-full table-fixed border-collapse min-w-0` với `<colgroup>` (`28%, 18%, 21%, 18%, 15%`), không sinh thanh cuộn ngang, định dạng ngày `dd/MM/yyyy` (`formatDateVN`) và hiển thị đủ 4 nút thao tác. |
| **24** | **3. Đăng ký tạm trú / tạm vắng & CT01** | **[UI / Responsive]** Kiểm tra giao diện ở độ phân giải `1024px`, `768px` và `390px` | **FAIL** | **Low** | `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (L863, L967, L1029, L1101-L1278) | **[BUG-12]** Ở `1024px` (khi mở cùng Sidebar Admin), cột "Lý do" dùng `line-clamp-3` và cột "Người duyệt" dùng `truncate` làm cắt bớt chuỗi dài trên bảng; ở `768px` và `390px`, bảng `<table>` bị ẩn (`hidden lg:block`) để chuyển sang Card View (`block lg:hidden`) và tên cư dân dùng class `truncate` (dòng 1121). |
| **25** | **4. Cấp tài khoản & kích hoạt** | **[Functional]** Tạo tài khoản tự động (`POST /api/v1/account-provisioning`), sinh `username` (`NV_xxxxxx`), sinh mật khẩu an toàn, lưu bcrypt hash (`password_hash`) và gửi email kích hoạt | **PASS** | - | `app/Services/AccountProvisioningService.php` (L68-L245), `app/Mail/AccountActivationMail.php` | Sinh mật khẩu ngẫu nhiên 16 ký tự đủ chữ hoa/thường/số/ký tự đặc biệt, lưu `Hash::make()`, trạng thái `PENDING_ACTIVATION`, gửi `AccountActivationMail`. |
| **26** | **4. Cấp tài khoản & kích hoạt** | **[Functional]** Kích hoạt tài khoản bằng token hợp lệ (`POST /api/v1/account-provisioning/activate`), kiểm tra trạng thái sau kích hoạt (`ACTIVE`) và tự động đăng nhập | **PASS** | - | `app/Services/AccountProvisioningService.php` (L315-L428), `resources/js/Pages/Auth/AccountActivationPage.tsx` | Xác thực `hash('sha256', $token)`, chuyển `status = ACTIVE`, đánh dấu `activated_at`, thu hồi token một lần và cấp phiên đăng nhập mới. |
| **27** | **4. Cấp tài khoản & kích hoạt** | **[Negative / Security]** Kiểm tra token hết hạn (> 24 giờ), token đã sử dụng, token sai, email không hợp lệ, tài khoản/email/SĐT/CCCD trùng và bảo mật không lộ thông tin đăng nhập | **PASS** | - | `app/Services/AccountProvisioningService.php` (L126-L143, L250-L312), `app/Http/Requests/AccountProvisioningRequest.php` | Chặn đầy đủ token hết hạn (`TOKEN_EXPIRED`), token đã dùng (`ALREADY_ACTIVATED`), trùng thông tin (`409`); API response không trả về `password_hash` hay plain token. |
| **28** | **5. Đăng ký phương tiện & đồng bộ phí** | **[Functional]** Đăng ký xe máy (`MOTORBIKE`) và ô tô (`CAR`) (`POST /api/v1/vehicles`), cập nhật thông tin phương tiện (`PUT /api/v1/vehicles/{id}`) và tính phí gửi xe tự động theo bảng giá | **PASS** | - | `app/Http/Controllers/VehicleController.php`, `app/Services/VehicleService.php` (L128-L387) | Tự động áp giá xe máy (`120.000đ` + 10% VAT = `132.000đ`) và ô tô (`1.500.000đ` + 10% VAT = `1.650.000đ`) từ `service_pricings`. |
| **29** | **5. Đăng ký phương tiện & đồng bộ phí** | **[Functional / Data Integrity]** Đồng bộ phí gửi xe sang hóa đơn kỳ hiện tại (`invoices` & `invoice_items`), kiểm tra Idempotency không tạo trùng khoản phí khi đồng bộ nhiều lần | **PASS** | - | `app/Services/VehicleService.php` (L495-L620) | Kiểm tra idempotency theo `invoice_id` và `license_plate` (`reason = ALREADY_EXISTS`), đảm bảo tổng tiền hóa đơn và phương tiện nhất quán. |
| **30** | **5. Đăng ký phương tiện & đồng bộ phí** | **[Negative]** Kiểm tra biển số trùng (`409 Conflict`), xử lý rollback DB transaction khi đồng bộ hóa đơn thất bại | **PASS** | - | `app/Services/VehicleService.php` (L220-L249) | Chặn biển số trùng (chuẩn hóa chữ hoa) và bọc quá trình tạo xe + đồng bộ hóa đơn trong cùng `DB::transaction()`. |
| **31** | **5. Đăng ký phương tiện & đồng bộ phí** | **[Functional]** Duyệt và từ chối đăng ký phương tiện | **FAIL** | **Medium** | `routes/web.php` (L554-L567), `app/Http/Controllers/VehicleController.php`, `resources/js/Pages/Admin/VehicleManagement.tsx` (L990-L1021) | **[BUG-06]** Hệ thống chỉ có API và nút **Duyệt** (`POST /api/v1/vehicles/{id}/approve`) và **Tạm ngưng/Kích hoạt** (`PATCH .../toggle-active`) hoặc **Xóa** (`DELETE`), hoàn toàn **thiếu API và nút "Từ chối đăng ký" (Reject)** kèm lý do từ chối. |
| **32** | **6. Quản lý RFID** | **[Functional]** Gán thẻ RFID mới (`POST /api/v1/rfid-cards`), kích hoạt thẻ, khóa thẻ tạm thời (`LOCKED`), báo mất (`LOST`) và mở khóa thẻ (`ACTIVE`) (`PATCH /api/v1/rfid-cards/{id}/status`) | **PASS** | - | `app/Http/Controllers/AccessCardController.php`, `app/Services/AccessCardService.php` (L120-L310), `resources/js/Pages/Admin/RfidManagement.tsx` | Hỗ trợ đầy đủ thao tác gán thẻ cho căn hộ/cư dân/phương tiện, đổi trạng thái `ACTIVE`, `LOCKED`, `LOST`, `EXPIRED`. |
| **33** | **6. Quản lý RFID** | **[Negative / Data Integrity]** Kiểm tra thẻ bị khóa (`LOCKED`/`LOST`/`EXPIRED`) không được coi là thẻ hoạt động; chặn gán mã thẻ `card_uid` đã tồn tại hoặc gán cho cư dân không thuộc căn hộ | **PASS** | - | `app/Services/AccessCardService.php` (L135-L158, L235-L250) | Thống kê `active_count` loại trừ thẻ bị khóa; chặn trùng `card_uid` (HTTP `409`) và chặn cư dân khác căn hộ (HTTP `422`). |
| **34** | **6. Quản lý RFID** | **[Permission / Code Quality]** Kiểm tra quyền quản lý RFID và phát sự kiện Realtime khi thao tác thẻ | **FAIL** | **Medium** | `app/Services/AccessCardService.php` (L172, L224, L257, L296), `app/Services/QuocTinRealtimeService.php` (L199) | **[BUG-10 & BUG-05]** `AccessCardService` ép kiểu `(int) $actorId` làm hỏng định danh UUID của Admin thành số nguyên; kênh realtime `quoc-tin.rfid-cards` có điều kiện `\|\| true` cho phép mọi user nghe lén sự kiện thẻ RFID. |
| **35** | **7. Cư dân đăng ký trước khách** | **[Functional]** Cư dân tạo đăng ký khách (`POST /api/v1/resident/visitors`), nhập thông tin khách, chọn thời gian đến, sinh mã QR (`VIS-...`) và mã PIN 6 số | **PASS** | - | `app/Http/Controllers/ResidentVisitorController.php`, `app/Services/ResidentVisitorService.php` (L245-L345), `resources/js/Components/ResidentVisitorRegistrationPanel.tsx` | Tạo bản ghi `visitor_registrations` với `status = PRE_REGISTERED`, sinh `qr_code_payload` duy nhất và mã PIN 6 chữ số. |
| **36** | **7. Cư dân đăng ký trước khách** | **[Functional / Negative]** Xem danh sách đăng ký, sửa thông tin (`PUT /api/v1/resident/visitors/{id}`), hủy đăng ký (`POST .../cancel`), chặn sửa/hủy khi khách đã check-in/check-out hoặc hết hạn | **PASS** | - | `app/Services/ResidentVisitorService.php` (L347-L465) | Chỉ cho phép sửa/hủy khi trạng thái là `PRE_REGISTERED`; tự động đánh dấu hết hạn khi quá `expected_arrival_time + 12h`. |
| **37** | **7. Cư dân đăng ký trước khách** | **[Permission / Data Scope]** Kiểm tra cư dân chỉ thao tác trong phạm vi căn hộ được phép | **FAIL** | **Medium** | `app/Services/ResidentVisitorService.php` (L45-L75, L269-L273) | **[BUG-08]** (1) `listVisitors` lọc theo `apartment_id` khi user chưa tự tạo bản ghi nào, nhưng chuyển sang chỉ lọc `host_resident_user_id` ngay khi user tạo 1 bản ghi (làm ẩn khách của thành viên cùng căn hộ); (2) `createVisitor` fallback gán vào `DB::table('apartments')->first()` nếu tài khoản chưa liên kết căn hộ. |
| **38** | **8. Lễ tân / bảo vệ check-in & check-out khách** | **[Functional]** Tra cứu khách bằng mã QR/PIN (`GET /api/v1/reception/visitors/lookup`), tra cứu danh sách (`GET /api/v1/reception/visitors`), xác nhận Check-in (`POST .../check-in`) và Check-out (`POST .../check-out`) kèm ghi nhận thời gian đến/rời đi | **PASS** | - | `app/Http/Controllers/ReceptionVisitorController.php`, `app/Services/ReceptionVisitorService.php` (L80-L390), `resources/js/Components/ReceptionVisitorCheckinPanel.tsx` | Ghi nhận chính xác `actual_checkin_time`, `actual_checkout_time`, `checked_in_by_user_id`, chuyển trạng thái `PRE_REGISTERED` -> `CHECKED_IN` -> `CHECKED_OUT`. |
| **39** | **8. Lễ tân / bảo vệ check-in & check-out khách** | **[Negative]** Ngăn check-in lặp, ngăn check-out khi khách chưa check-in, ngăn thao tác khi đăng ký đã hết hạn (`EXPIRED`) hoặc bị hủy (`CANCELLED`) | **PASS** | - | `app/Services/ReceptionVisitorService.php` (L240-L385) | Sử dụng `lockForUpdate()` chặn race condition check-in lặp (HTTP `422`), chặn check-out khi chưa check-in và chặn mã QR đã hủy/hết hạn (`ReceptionVisitorCheckinTest` 15/15 PASS). |
| **40** | **8. Lễ tân / bảo vệ check-in & check-out khách** | **[Permission & Realtime]** Kiểm tra quyền nhân viên lễ tân/bảo vệ (`RECEPTIONIST`, `SECURITY_GUARD`, `BUILDING_MANAGER`, `SUPER_ADMIN`) và cập nhật realtime | **PASS** | - | `app/Services/ReceptionVisitorService.php` (L45-L72) | Chặn cư dân gọi API check-in/check-out của lễ tân (HTTP `403`); phát sự kiện realtime SSE cập nhật tức thời. |
| **41** | **9. Tiếp nhận bưu phẩm và ảnh** | **[Functional]** Tạo phiếu tiếp nhận bưu phẩm (`POST /api/v1/reception/parcels`), chọn căn hộ/người nhận, nhập đơn vị vận chuyển, mã vận đơn, tải ảnh bưu phẩm, sinh mã PIN 6 số & mã QR (`PKG-...`) và xem lại sau khi tải lại trang | **PASS** | - | `app/Http/Controllers/ReceptionParcelController.php`, `app/Services/ReceptionParcelService.php` (L245-L385), `resources/js/Components/ReceptionParcelPanel.tsx` | Lưu ảnh vào `storage/app/public/parcels`, kiểm tra định dạng (`jpg,jpeg,png,webp`) & dung lượng (`<= 5MB`), sinh mã PIN & QR duy nhất. |
| **42** | **9. Tiếp nhận bưu phẩm và ảnh** | **[Permission / Security]** Kiểm tra quyền nhân viên tiếp nhận bưu phẩm và bảo mật dữ liệu bưu phẩm giữa các căn hộ | **FAIL** | **Critical** | `routes/web.php` (L219-L231), `app/Http/Controllers/ReceptionParcelController.php` (L23-L211), `app/Services/ReceptionParcelService.php` (L259-L269), `resources/js/Components/ResidentParcelWidget.tsx` (L99-L101) | **[BUG-01]** 10/13 route `/api/v1/reception/parcels*` **không gắn middleware `auth.bearer`** và không gọi `assertStaffAuthorized()`. Khi `$staffUser = null`, `receiveParcel()` tự lấy ID nhân viên đầu tiên trong DB, cho phép **khách vãng lai (unauthenticated) hoặc Cư dân** tạo/sửa/xóa/tra cứu mọi bưu phẩm. Ngoài ra `ResidentParcelWidget.tsx` hiển thị toàn bộ bưu phẩm + mã PIN/QR của **tất cả các căn hộ** cho bất kỳ cư dân nào. |
| **43** | **10. Web Push và email thông báo** | **[Functional]** Đăng ký/hủy Web Push subscription (`POST/DELETE /api/v1/notifications/push-subscription`), kiểm tra VAPID public key và tự động gửi Email + Web Push cho đúng cư dân khi có bưu phẩm mới (`ParcelReceivedEvent`) | **PASS** | - | `app/Http/Controllers/PushNotificationController.php`, `app/Services/WebPushService.php`, `app/Jobs/SendParcelNotificationJob.php`, `app/Mail/ParcelReceivedMail.php` | Nội dung thông báo đầy đủ tên người nhận, căn hộ, đơn vị vận chuyển, mã PIN và mã QR; gửi đúng người nhận. |
| **44** | **10. Web Push và email thông báo** | **[Negative / Resilience]** Kiểm tra khi thiếu cấu hình VAPID, lỗi nhà cung cấp email/push, tự động vô hiệu hóa subscription hết hạn (HTTP `404`/`410`) và **không báo thành công giả** khi gửi thất bại | **PASS** | - | `app/Services/WebPushService.php` (L115-L265), `app/Jobs/SendParcelNotificationJob.php` | Khi thiếu VAPID keys trả về `skipped: true, sent: 0` (không báo `sent > 0` giả); khi endpoint trả về `404/410` tự động đánh dấu `is_active = false` (`ParcelNotificationTest` 15/15 PASS). |
| **45** | **10. Web Push và email thông báo** | **[Environment]** Kiểm tra gửi thông báo thực tế qua hạ tầng SMTP Internet bên ngoài và Push Service trình duyệt thật (FCM/Mozilla Autopush) | **UNVERIFIED** | - | `config/mail.php`, `config/services.php` | Môi trường Docker local không cấu hình SMTP production và VAPID key thật kết nối ra Internet; đã xác minh đầy đủ qua `Mail::fake()`, `Http::fake()` và Queue Job thực tế. |
| **46** | **11. Bàn giao bưu phẩm** | **[Functional]** Tra cứu bưu phẩm (`GET /api/v1/reception/parcels/lookup`), xác nhận bàn giao bằng mã PIN hoặc mã QR (`POST /api/v1/reception/parcels/check-out`), ghi nhận người nhận, nhân viên bàn giao (`handed_over_by_user_id`), thời gian (`picked_up_at`) và cập nhật trạng thái `PICKED_UP` | **PASS** | - | `app/Http/Controllers/ReceptionParcelController.php` (L128-L175), `app/Services/ReceptionParcelService.php` (L390-L558) | Xác thực chính xác mã PIN 6 số hoặc mã QR, cập nhật trạng thái `PICKED_UP`, lưu lịch sử thời gian và nhân viên bàn giao. |
| **47** | **11. Bàn giao bưu phẩm** | **[Negative / Permission]** Không cho bàn giao khi mã PIN/QR sai, khi thiếu cả PIN và QR, khi chưa xác thực nhân viên (`401/403`), hoặc bàn giao hai lần (`422`) | **PASS** | - | `app/Services/ReceptionParcelService.php` (L395-L510), `tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php` | Các route `check-out` và `handover` có `auth.bearer` và gọi `assertStaffAuthorized()`, dùng `lockForUpdate()` chặn bàn giao 2 lần (`ReceptionParcelCheckoutTest` 15/15 PASS). |
| **48** | **12. Xếp lịch trực tuần/tháng** | **[Functional / Negative / Permission]** Tạo lịch trực (`POST /api/v1/management/schedules`), sửa (`PUT`), xóa (`DELETE`), phân công nhân viên, kiểm tra trùng ca (`422`), ca qua đêm (`NIGHT` 22:00–06:00), nhân viên không hợp lệ, quyền quản lý lịch (`403`) và tải lại trang | **PASS** | - | `app/Http/Controllers/StaffScheduleController.php`, `app/Services/StaffScheduleService.php`, `tests/Feature/StaffSchedule/StaffScheduleTest.php` | Backend kiểm tra đầy đủ trùng ca (kể cả ca đêm vắt qua ngày hôm sau), chặn gán lịch cho tài khoản `RESIDENT`, bảo toàn dữ liệu sau khi reload (`StaffScheduleTest` 14/14 PASS). |
| **49** | **12. Xếp lịch trực tuần/tháng** | **[UI / Functional]** Kiểm tra chuyển đổi chế độ **Xem lịch tuần (Week View)** và **Xem lịch tháng (Month View)** trên giao diện Xếp lịch trực | **FAIL** | **Medium** | `resources/js/Components/ManagementStaffSchedulePanel.tsx` (L832-L980) | **[BUG-07]** Dù API backend hỗ trợ lọc theo khoảng 7 ngày bất kỳ, giao diện `ManagementStaffSchedulePanel.tsx` chỉ cài đặt lưới **Lịch tháng** (`getMonthRange`), hoàn toàn thiếu nút chuyển đổi và giao diện **Xem lịch tuần (Week View)**. |
| **50** | **13. Dashboard lễ tân** | **[Functional / Data Integrity]** Hiển thị số lượng bưu phẩm đang chờ, số lượng khách đang ở trong tòa nhà, số liệu tổng hợp từ DB, bộ lọc thời gian (`date`, `days`), danh sách chi tiết và cập nhật realtime (`GET /api/v1/reception/dashboard`) | **PASS** | - | `app/Http/Controllers/ReceptionDashboardController.php`, `app/Services/ReceptionDashboardService.php`, `resources/js/Components/ReceptionDashboardPanel.tsx` | Số liệu `pending_parcels_count`, `active_visitors_count`, biểu đồ 7 ngày và danh sách chi tiết truy vấn trực tiếp từ DB chính xác, không trùng lặp (`ReceptionDashboardTest` 11/11 PASS). |
| **51** | **13. Dashboard lễ tân** | **[Permission / Security]** Kiểm tra quyền truy cập Dashboard lễ tân và cơ chế xác thực Bearer token | **FAIL** | **High** | `app/Http/Controllers/ReceptionDashboardController.php` (L26-L33) | **[BUG-02]** `ReceptionDashboardController::resolveAuthUser()` cho phép 3 token demo hardcode (`smart_token_reception_demo`, v.v.) đi thẳng vào hệ thống **không kiểm tra `app()->environment('testing', 'local')`**, tạo lỗ hổng vượt qua xác thực trên môi trường production. |
| **52** | **Tích hợp #1 (Chức năng 2 -> 3 & 7)** | **[Integration]** Cư dân được tạo trong Chức năng 2 có thể sử dụng Chức năng 3 (Tạm trú/Tạm vắng) và Chức năng 7 (Đăng ký khách) đúng quyền | **FAIL** | **High** | `app/Http/Controllers/TemporaryRegistrationController.php` (L25-L43), `app/Http/Requests/TemporaryRegistrationRequest.php` (L14-L31) | **[BUG-03]** Cư dân tạo ở Chức năng 2 dùng được Chức năng 7 (`/api/v1/resident/visitors`), nhưng bị chặn hoàn toàn ở Chức năng 3 (`403 FORBIDDEN`) vì `TemporaryRegistrationController` chỉ cho phép `SUPER_ADMIN` và `BUILDING_MANAGER`. |
| **53** | **Tích hợp #2 (Chức năng 4 -> 1)** | **[Integration]** Tài khoản được cấp và kích hoạt ở Chức năng 4 có thể đăng nhập và được phân quyền đúng theo vai trò được gán | **PASS** | - | `app/Services/AccountProvisioningService.php`, `app/Http/Controllers/AuthController.php` | Tài khoản sau khi kích hoạt (`status = ACTIVE`) đăng nhập thành công và nhận đúng role đã cấp phát. |
| **54** | **Tích hợp #3 (Chức năng 5 -> Hóa đơn)** | **[Integration]** Phương tiện ở Chức năng 5 đồng bộ đúng khoản phí gửi xe (kèm thuế VAT) sang bảng `invoices` và `invoice_items` | **PASS** | - | `app/Services/VehicleService.php` (L495-L620) | Tự động tạo/cập nhật hóa đơn kỳ `Y-m` và mục phí gửi xe tương ứng, tự động trừ phí khi xóa hoặc tạm ngưng xe. |
| **55** | **Tích hợp #4 (Chức năng 6 -> Cư dân/Xe)** | **[Integration]** Thẻ RFID ở Chức năng 6 phản ánh đúng trạng thái người sử dụng, căn hộ và phương tiện liên kết | **PASS** | - | `app/Services/AccessCardService.php`, `app/Models/AccessCard.php` | Liên kết chính xác `apartment_id`, `issued_to_user_id`, `vehicle_id` và phản ánh đúng trạng thái hoạt động/khóa của thẻ. |
| **56** | **Tích hợp #5 (Chức năng 7 -> 8)** | **[Integration]** Đăng ký khách của cư dân ở Chức năng 7 xuất hiện đúng trong danh sách và bộ tra cứu QR/PIN của Lễ tân ở Chức năng 8 | **PASS** | - | `app/Services/ResidentVisitorService.php`, `app/Services/ReceptionVisitorService.php` | Mã QR (`qr_code_payload`) và mã PIN 6 số sinh từ Chức năng 7 tra cứu và check-in/check-out khớp 100% ở Chức năng 8. |
| **57** | **Tích hợp #6 (Chức năng 9 -> 10)** | **[Integration]** Bưu phẩm tiếp nhận ở Chức năng 9 tự động kích hoạt `ParcelReceivedEvent` để gửi Email và Web Push ở Chức năng 10 | **PASS** | - | `app/Services/ReceptionParcelService.php` (L345-L355), `app/Listeners/SendParcelNotificationListener.php`, `app/Jobs/SendParcelNotificationJob.php` | Sự kiện được phát sau khi commit transaction tiếp nhận bưu phẩm và đẩy vào hàng đợi thông báo cho đúng cư dân. |
| **58** | **Tích hợp #7 (Chức năng 9 -> 11)** | **[Integration]** Bưu phẩm tiếp nhận ở Chức năng 9 được bàn giao qua Chức năng 11 bằng mã PIN/QR và cập nhật trạng thái sang `PICKED_UP` | **PASS** | - | `app/Services/ReceptionParcelService.php` (L245-L558) | Luồng khép kín từ `PENDING` (Chức năng 9) -> xác thực PIN/QR -> `PICKED_UP` (Chức năng 11). |
| **59** | **Tích hợp #8 (Chức năng 12 -> Nhân sự)** | **[Integration]** Lịch trực ở Chức năng 12 hiển thị đúng thông tin nhân viên (`RECEPTIONIST`, `SECURITY_GUARD`, `TECHNICIAN`, `BUILDING_MANAGER`) và khung thời gian ca trực | **PASS** | - | `app/Services/StaffScheduleService.php` | Truy vấn đúng danh sách nhân viên nội bộ và hiển thị chính xác thời gian bắt đầu/kết thúc từng ca. |
| **60** | **Tích hợp #9 (Chức năng 8, 9, 11 -> 13)** | **[Integration]** Dashboard lễ tân ở Chức năng 13 phản ánh chính xác và tức thời số lượng khách (Chức năng 8) và bưu phẩm tồn/đã giao (Chức năng 9 & 11) | **PASS** | - | `app/Services/ReceptionDashboardService.php` | Khi khách check-in/check-out hoặc bưu phẩm được tiếp nhận/bàn giao, số liệu trên Dashboard lễ tân thay đổi chính xác tương ứng. |
| **61** | **Tích hợp #10 (Chức năng 1 -> 2..13)** | **[Integration / Permission]** RBAC Matrix ở Chức năng 1 áp dụng đúng cho các chức năng còn lại (2–13) theo cấu hình quyền hiện có | **FAIL** | **High** | `app/Services/RbacService.php`, `app/Http/Controllers/*Controller.php`, `app/Services/QuocTinRealtimeService.php` | **[BUG-04 & BUG-05]** Cấu hình quyền chi tiết trên RBAC Matrix không được các API Chức năng 2–13 kiểm tra (chỉ check `hasRole`), đồng thời SSE Realtime (`/api/realtime/events`) bỏ qua kiểm tra quyền trên 4 kênh nghiệp vụ. |
| **62** | **Kiểm thử tải đồng thời HTTP cURL (Chức năng 2 & 3)** | **[Concurrency / Environment]** Chạy bộ test bắn 30–50 HTTP cURL параллельно (`ResidentConcurrencyTest` & `TemporaryRegistrationConcurrencyTest`) | **UNVERIFIED** | - | `tests/Feature/Residents/ResidentConcurrencyTest.php`, `tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php` | Web server cổng `8000` đang kết nối vào DB chính (`quanly_toanha`). Để tuân thủ nguyên tắc **không ghi vào DB thật**, bộ test tự động `markTestSkipped` (19 tests). Đã kiểm chứng tương đương bằng `ResidentCreateConcurrencyTest` (8/8 PASS) trên `quanly_toanha_test`. |

---

## II. CHI TIẾT CÁC LỖI PHÁT HIỆN (ƯU TIÊN SỬA TRƯỚC)

### 1. [BUG-01] (Critical) Thiếu xác thực `auth.bearer` & kiểm tra quyền trên 10 API Bưu phẩm + Lộ toàn bộ bưu phẩm và mã PIN/QR của các căn hộ khác cho Cư dân
- **Chức năng ảnh hưởng**: Chức năng 9 (Tiếp nhận bưu phẩm), Chức năng 11 (Bàn giao bưu phẩm), Chức năng 1 (Phân quyền).
- **File & Vị trí**:
  - `routes/web.php` (Dòng `219–231`)
  - `app/Http/Controllers/ReceptionParcelController.php` (Dòng `23–125`, `177–211`)
  - `app/Services/ReceptionParcelService.php` (Dòng `48–138`, `259–269`, `560–710`)
  - `resources/js/Components/ResidentParcelWidget.tsx` (Dòng `99–101`)
- **Mô tả & Nguyên nhân**:
  1. Trong `routes/web.php` (dòng `219–231`), chỉ có 3 route bàn giao (`check-out` và `handover`) được gắn `->middleware('auth.bearer')`. 10 route còn lại (`GET /api/v1/reception/parcels`, `GET /api/v1/reception/parcels/lookup`, `POST /api/v1/reception/parcels`, `GET /api/v1/reception/parcels/recipients/search`, `GET /api/v1/reception/parcels/apartments/search`, `POST /api/v1/reception/parcels/remind-expiring`, `GET /api/v1/reception/parcels/{id}`, `PUT/POST /api/v1/reception/parcels/{id}`, `POST /api/v1/reception/parcels/{id}/remind`, `DELETE /api/v1/reception/parcels/{id}`) **không có middleware `auth.bearer`** và các hàm trong `ReceptionParcelController` / `ReceptionParcelService` **không gọi `assertStaffAuthorized()`**.
  2. Trong `ReceptionParcelService::receiveParcel()` (dòng `259–269`), khi `$staffUser` là `null` (request không gửi kèm token), hệ thống tự động lấy đại ID của một nhân viên trong bảng `users` (`User::whereHas('roles', ...)->value('id') ?? User::value('id')`) để gán vào `received_by_user_id`.
  3. Trên cổng Cư dân, component `ResidentParcelWidget.tsx` (dòng `99–101`) gọi `api.getReceptionParcels({ status: 'PENDING', limit: 20 })` không truyền `apartment_id`, và backend cũng không giới hạn theo căn hộ của cư dân đang đăng nhập.
- **Bước tái hiện**:
  1. Gửi request `GET /api/v1/reception/parcels` hoặc `POST /api/v1/reception/parcels` hoặc `DELETE /api/v1/reception/parcels/{id}` **không kèm header `Authorization`** (hoặc dùng token của tài khoản `RESIDENT`).
  2. Đăng nhập tài khoản cư dân căn hộ A trên trình duyệt, mở widget Bưu phẩm (`ResidentParcelWidget`).
- **Kết quả thực tế**:
  - Khách vãng lai (chưa đăng nhập) hoặc Cư dân có thể xem toàn bộ danh sách bưu phẩm (kèm `pickup_pin_code`, `pickup_qr_code`, SĐT người nhận), tự tạo bưu phẩm giả, sửa hoặc xóa bưu phẩm của bất kỳ căn hộ nào.
  - Cư dân căn hộ A nhìn thấy toàn bộ bưu phẩm đang chờ và mã PIN/QR nhận hàng của mọi căn hộ khác trong tòa nhà.
- **Kết quả mong đợi**:
  - Tất cả các route quản lý bưu phẩm của Lễ tân phải bắt buộc xác thực `auth.bearer` và kiểm tra quyền nhân viên (`assertStaffAuthorized()`).
  - Cư dân chỉ được xem bưu phẩm thuộc đúng căn hộ của mình.

---

### 2. [BUG-02] (Critical) Backdoor mật khẩu dự phòng hardcode, lộ `debug_otp` khi đăng ký và chấp nhận Demo Token trên môi trường Production
- **Chức năng ảnh hưởng**: Chức năng 1 (Xác thực & Phân quyền), Chức năng 4 (Cấp tài khoản), Chức năng 13 (Dashboard lễ tân).
- **File & Vị trí**:
  - `app/Http/Controllers/AuthController.php` (Dòng `139–142` và Dòng `411`)
  - `app/Http/Controllers/ReceptionDashboardController.php` (Dòng `26–33`)
- **Mô tả & Nguyên nhân**:
  1. Tại `AuthController::login()` (dòng `139–142`), mảng `$demoFallbacks` cho phép 10 tài khoản (`admin`, `manager`, `receptionist`, `security`, `resident.owner`, `resident.member`, `accountant`, `nguyenvanan`, `quanly`, `letan`) đăng nhập bằng mật khẩu cố định mà **không kiểm tra `app()->environment('local', 'testing')`**. Ngay cả khi quản trị viên đã đổi mật khẩu, kẻ tấn công vẫn đăng nhập được bằng mật khẩu dự phòng này.
  2. Tại `AuthController::sendRegisterOtp()` (dòng `411`), API trả thẳng `'debug_otp' => $otp` trong JSON response ở mọi môi trường (khác với `forgotPassword` ở dòng `771` có kiểm tra `app()->isLocal()`).
  3. Tại `ReceptionDashboardController::resolveAuthUser()` (dòng `26–33`), controller chấp nhận 3 chuỗi token cố định (`smart_token_reception_demo`, `smart_token_admin_demo`, `smart_token_manager_demo`) mà **không kiểm tra `app()->environment('testing', 'local')`**.
- **Bước tái hiện**:
  1. Gọi `POST /api/v1/auth/send-register-otp` với email bất kỳ -> Quan sát trường `debug_otp` trong JSON trả về.
  2. Gọi `GET /api/v1/reception/dashboard` với header `Authorization: Bearer <demo_token>` khi `APP_ENV=production`.
- **Kết quả thực tế**: Hệ thống trả về mã OTP trực tiếp trong JSON và cho phép truy cập trái phép bằng demo password / demo token.
- **Kết quả mong đợi**: Vô hiệu hóa hoàn toàn mật khẩu fallback, demo token và `debug_otp` ngoài môi trường `local`/`testing`.

---

### 3. [BUG-03] (High) Lỗi tích hợp liên chức năng #1: Cư dân bị chặn hoàn toàn (`403 FORBIDDEN`) khi sử dụng Chức năng 3 (Đăng ký tạm trú / tạm vắng)
- **Chức năng ảnh hưởng**: Chức năng 2 (Quản lý cư dân) & Chức năng 3 (Đăng ký tạm trú / tạm vắng).
- **File & Vị trí**:
  - `app/Http/Controllers/TemporaryRegistrationController.php` (Dòng `25–43`)
  - `app/Http/Requests/TemporaryRegistrationRequest.php` (Dòng `14–31`)
  - `routes/web.php` (Dòng `485–512`)
- **Mô tả & Nguyên nhân**:
  - Yêu cầu tích hợp số 1 quy định: *"1. Cư dân được tạo trong chức năng 2 có thể sử dụng chức năng 3 và 7 đúng quyền."*
  - Tuy nhiên, cả `TemporaryRegistrationController::checkAdminAuthorization()` và `TemporaryRegistrationRequest::authorize()` đều chỉ cho phép `isSuperAdmin()` hoặc `hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])`, và không có bất kỳ route/logic nào cho phép vai trò `RESIDENT`, `RESIDENT_OWNER`, `RESIDENT_MEMBER` gửi hoặc xem hồ sơ tạm trú/tạm vắng của căn hộ mình.
- **Bước tái hiện**:
  1. Dùng tài khoản cư dân được tạo ở Chức năng 2 đăng nhập lấy Bearer token.
  2. Gọi `GET /api/v1/residents/temporary-registrations` hoặc `POST /api/v1/residents/temporary-registrations` cho căn hộ của chính cư dân đó.
- **Kết quả thực tế**: Nhận lỗi HTTP `403 FORBIDDEN` (`"Bạn không có quyền quản trị để thực hiện thao tác này."`).
- **Kết quả mong đợi**: Cư dân được phép tạo và xem hồ sơ đăng ký tạm trú / tạm vắng trong phạm vi căn hộ của mình; các thao tác duyệt (`approve`), từ chối (`reject`), gửi Công an (`submit-police`) mới giới hạn cho Ban quản lý / Admin.

---

### 4. [BUG-04] (High) Lỗi tích hợp liên chức năng #10: Cấu hình quyền trên RBAC Matrix (Chức năng 1) bị bỏ qua bởi các Controller nghiệp vụ (Chức năng 2–13)
- **Chức năng ảnh hưởng**: Chức năng 1 (RBAC Matrix) và các Chức năng 2, 3, 4, 5, 6, 8, 9, 11, 12, 13.
- **File & Vị trí**:
  - `app/Http/Controllers/ResidentController.php` (Dòng `24–42`)
  - `app/Http/Controllers/TemporaryRegistrationController.php` (Dòng `25–43`)
  - `app/Http/Controllers/AccountProvisioningController.php` (Dòng `27–45`)
  - `app/Http/Controllers/VehicleController.php` (Dòng `25–56`)
  - `app/Http/Controllers/AccessCardController.php` (Dòng `26–44`)
  - `app/Services/ReceptionVisitorService.php` (Dòng `45–72`)
  - `app/Http/Controllers/StaffScheduleController.php` (Dòng `25–53`)
  - `app/Services/ReceptionDashboardService.php` (Dòng `28–55`)
- **Mô tả & Nguyên nhân**:
  - Ở Chức năng 1, Admin cấu hình ma trận phân quyền chi tiết (`RESIDENT:VIEW`, `RESIDENT:CREATE`, `RESIDENT:DELETE`, `VEHICLE:UPDATE`, `SCHEDULE:DELETE`, `SYSTEM:SPECIAL`, ...) cho từng Role hoặc từng User (`CUSTOM_USER_{ID}`).
  - Tuy nhiên, toàn bộ các controller/service của Chức năng 2–13 chỉ kiểm tra cứng theo tên Role (`$user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', ...])`) mà **không gọi `$user->hasPermission(...)` hay middleware `permission:...`**.
- **Bước tái hiện**:
  1. Vào RBAC Matrix (Chức năng 1), thu hồi quyền `Xóa` (`RESIDENT:DELETE` hoặc `VEHICLE:DELETE`) của một tài khoản `BUILDING_MANAGER`, bấm Lưu.
  2. Dùng token của tài khoản `BUILDING_MANAGER` đó gọi trực tiếp `DELETE /api/v1/residents/{id}` hoặc `DELETE /api/v1/vehicles/{id}`.
  3. Ngược lại, cấp quyền `VEHICLE:VIEW` và `VEHICLE:CREATE` (hoặc quyền `Người đặc biệt` `SPECIAL`) cho một nhân viên `ACCOUNTANT` qua RBAC Matrix, sau đó gọi `GET /api/v1/vehicles`.
- **Kết quả thực tế**:
  - Tài khoản `BUILDING_MANAGER` đã bị thu hồi quyền Xóa vẫn xóa được dữ liệu qua API (`200 OK`).
  - Tài khoản được cấp quyền riêng qua RBAC Matrix vẫn bị chặn (`403 FORBIDDEN`).
- **Kết quả mong đợi**: Các API của Chức năng 2–13 phải kiểm tra quyền thực tế thông qua `RbacService::hasPermission()` / `$user->hasPermission()` đồng bộ với cấu hình trên RBAC Matrix.

---

### 5. [BUG-05] (High) Bỏ qua kiểm tra phân quyền trên 4 kênh SSE Realtime (`quoc-tin.rfid-cards`, `quoc-tin.visitors`, `quoc-tin.parcels`, `quoc-tin.schedules`)
- **Chức năng ảnh hưởng**: Chức năng 6, 7, 8, 9, 11, 12.
- **File & Vị trí**:
  - `app/Services/QuocTinRealtimeService.php` (Dòng `197–206`)
- **Mô tả & Nguyên nhân**:
  - Trong hàm `QuocTinRealtimeService::isUserAuthorizedForChannel()`:
    ```php
    'quoc-tin.rfid-cards' => $isSuperAdmin || in_array('BUILDING_MANAGER', $roleCodes, true) || in_array('RECEPTIONIST', $roleCodes, true) || in_array('SECURITY_GUARD', $roleCodes, true) || true,
    'quoc-tin.visitors' => true,
    'quoc-tin.parcels' => true,
    'quoc-tin.schedules' => true,
    ```
  - Do hardcode `|| true` và `=> true`, bất kỳ người dùng nào đã đăng nhập (kể cả cư dân bình thường) đều có thể mở kết nối SSE tới `/api/realtime/events` để nghe lén sự kiện thẻ RFID, khách ra vào, bưu phẩm và lịch trực nhân viên của toàn tòa nhà.
- **Bước tái hiện**: Đăng nhập tài khoản `RESIDENT`, gọi `GET /api/realtime/events?channels=quoc-tin.rfid-cards,quoc-tin.schedules,quoc-tin.parcels`.
- **Kết quả thực tế**: Kết nối SSE được chấp nhận (`200 OK text/event-stream`) thay vì từ chối `403`.
- **Kết quả mong đợi**: Chỉ cho phép các vai trò/quyền hợp lệ lắng nghe kênh quản trị (`rfid-cards`, `schedules`), và giới hạn sự kiện `visitors`/`parcels` theo phạm vi căn hộ của cư dân.

---

### 6. [BUG-06] (Medium) Chức năng 5 thiếu chức năng "Từ chối đăng ký phương tiện" (Reject) trên cả Backend API và Giao diện
- **Chức năng ảnh hưởng**: Chức năng 5 (Đăng ký phương tiện và đồng bộ phí gửi xe).
- **File & Vị trí**:
  - `routes/web.php` (Dòng `554–567`)
  - `app/Http/Controllers/VehicleController.php`
  - `app/Services/VehicleService.php`
  - `resources/js/Pages/Admin/VehicleManagement.tsx` (Dòng `990–1021`)
- **Mô tả & Nguyên nhân**:
  - Yêu cầu kiểm thử Chức năng 5 quy định: *"Duyệt và từ chối đăng ký."*
  - Hiện tại hệ thống chỉ có API `POST /api/v1/vehicles/{id}/approve` (Duyệt), `PATCH /api/v1/vehicles/{id}/toggle-active` (Tạm ngưng/Kích hoạt) và `DELETE /api/v1/vehicles/{id}` (Xóa mềm), hoàn toàn **không có API `POST /api/v1/vehicles/{id}/reject`** cũng như không có nút "Từ chối" hay trường lưu lý do từ chối đăng ký xe.
- **Bước tái hiện**: Mở trang Quản lý phương tiện (`VehicleManagement.tsx`) tại dòng phương tiện chờ duyệt (`!vehicle.approved_by_user_id`).
- **Kết quả thực tế**: Chỉ có nút "Duyệt", "Sửa", "Xóa", không có thao tác "Từ chối đăng ký".
- **Kết quả mong đợi**: Bổ sung luồng Từ chối đăng ký phương tiện (cập nhật trạng thái từ chối, không tính phí gửi xe vào hóa đơn, ghi nhận lý do từ chối).

---

### 7. [BUG-07] (Medium) Giao diện Chức năng 12 (Xếp lịch trực tuần/tháng) thiếu chế độ Xem lịch tuần (Week View)
- **Chức năng ảnh hưởng**: Chức năng 12 (Xếp lịch trực tuần/tháng).
- **File & Vị trí**:
  - `resources/js/Components/ManagementStaffSchedulePanel.tsx` (Dòng `832–980`)
- **Mô tả & Nguyên nhân**:
  - Yêu cầu kiểm thử Chức năng 12 quy định kiểm tra cả **"Xem lịch tuần"** và **"Xem lịch tháng"**.
  - Dù API `GET /api/v1/management/schedules?start_date=...&end_date=...` hỗ trợ truy vấn lịch theo tuần (7 ngày), component `ManagementStaffSchedulePanel.tsx` chỉ cài đặt bộ chọn và lưới hiển thị theo **Tháng** (`getMonthRange`), tại dòng `832` có comment `{/* Bộ chuyển Tuần / Tháng */}` nhưng chỉ render nút điều hướng Tháng trước / Tháng sau mà không có nút chuyển sang chế độ **Tuần (Week View)**.
- **Bước tái hiện**: Mở giao diện Xếp lịch trực nhân viên và tìm thanh chuyển đổi chế độ Xem Tuần / Xem Tháng.
- **Kết quả thực tế**: Chỉ xem được theo Tháng, không thể chuyển sang chế độ Xem lịch tuần.
- **Kết quả mong đợi**: Có bộ chuyển đổi Tuần / Tháng trên giao diện và hiển thị đúng lưới lịch tuần (7 ngày) khi chọn chế độ Tuần.

---

### 8. [BUG-08] (Medium) Phạm vi danh sách khách của cư dân (`listVisitors`) không nhất quán và `createVisitor` tự gán nhầm căn hộ đầu tiên trong DB khi user chưa có căn hộ
- **Chức năng ảnh hưởng**: Chức năng 7 (Cư dân đăng ký trước khách).
- **File & Vị trí**:
  - `app/Services/ResidentVisitorService.php` (Dòng `45–75` và Dòng `269–273`)
- **Mô tả & Nguyên nhân**:
  1. Tại `ResidentVisitorService::listVisitors()` (dòng `45–75`): Nếu cư dân chưa tự tạo khách nào (`$hasOwn === false`), hệ thống trả về danh sách khách của cả căn hộ (`whereIn('apartment_id', $apartmentIds)`). Nhưng ngay khi cư dân đó tạo 1 bản ghi đăng ký khách (`$hasOwn === true`), câu truy vấn đổi sang `where('host_resident_user_id', $userId)`, làm biến mất toàn bộ danh sách khách do các thành viên khác trong cùng căn hộ đăng ký.
  2. Tại `ResidentVisitorService::createVisitor()` (dòng `269–273`): Nếu tài khoản đang đăng nhập chưa được gán vào căn hộ nào trong bảng `residents`, thay vì báo lỗi `422/403`, code lại lấy `DB::table('apartments')->first()` để gán khách vào căn hộ đầu tiên trong tòa nhà.
- **Kết quả mong đợi**:
  - Thống nhất phạm vi xem danh sách khách theo căn hộ của cư dân.
  - Chặn tạo đăng ký khách (trả về lỗi `422` hoặc `403`) nếu tài khoản cư dân chưa được gán vào căn hộ hợp lệ.

---

### 9. [BUG-09] (Medium) Thêm lại cư dân đã bị xóa mềm (`soft-deleted`) vào cùng căn hộ gây lỗi `ResidentConflictException` (HTTP `409`/`500`) thay vì khôi phục hoặc báo lỗi `422`
- **Chức năng ảnh hưởng**: Chức năng 2 (Quản lý hộ khẩu và cư dân).
- **File & Vị trí**:
  - `app/Services/ResidentService.php` (Dòng `172–220`)
  - `tests/Feature/Residents/ResidentCreateTest.php` (Dòng `500–535` — `test_case_14_soft_deleted_resident_behavior`)
- **Mô tả & Nguyên nhân**:
  - Bảng `residents` có ràng buộc unique `uq_resident_apt (user_id, apartment_id)` áp dụng trên cả các dòng đã xóa mềm (`deleted_at IS NOT NULL`).
  - Khi thêm lại một `user_id` đã từng bị xóa mềm khỏi `apartment_id` đó, bước kiểm tra `Resident::where(...)->exists()` bỏ qua bản ghi `deleted_at != null`, dẫn tới lệnh `Resident::create()` đụng lỗi MySQL `1062 Duplicate entry` và văng `ResidentConflictException` với thông báo sai bản chất: *"Cư dân này đã được thêm vào căn hộ bởi quản trị viên khác."*
- **Kết quả mong đợi**: Kiểm tra `Resident::withTrashed()->where('user_id', ...)->where('apartment_id', ...)->first()` để khôi phục (`restore()`) và cập nhật thông tin cư dân, hoặc trả về thông báo lỗi `422` rõ ràng.

---

### 10. [BUG-10] (Medium) Ép kiểu `(int) $actorId` trên khóa chính UUID trong `AccessCardService` làm hỏng định danh người thực hiện
- **Chức năng ảnh hưởng**: Chức năng 6 (Quản lý RFID).
- **File & Vị trí**:
  - `app/Services/AccessCardService.php` (Dòng `172`, `224`, `257`, `296`)
- **Mô tả & Nguyên nhân**:
  - `users.id` trong hệ thống là chuỗi UUID (ví dụ `019a...`), nhưng `AccessCardService` truyền `$actorId ? (int) $actorId : null` vào `QuocTinRealtimeService::emit(...)`, làm giá trị UUID bị ép thành số nguyên sai lệch (ví dụ `0` hoặc `19`).
- **Kết quả mong đợi**: Giữ nguyên kiểu chuỗi `?string $actorId` khi truyền sang dịch vụ realtime/audit.

---

### 11. [BUG-11] (Low) Các thẻ thống kê KPI phụ ở Chức năng 2, 3 và 5 chỉ tính trên trang hiện tại (15 bản ghi) thay vì toàn bộ dữ liệu
- **Chức năng ảnh hưởng**: Chức năng 2, Chức năng 3, Chức năng 5.
- **File & Vị trí**:
  - `resources/js/Pages/Admin/ResidentManagement.tsx` (Dòng `240–248`)
  - `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (Dòng `360–367`)
  - `resources/js/Pages/Admin/VehicleManagement.tsx` (Dòng `856–865`)
- **Mô tả & Nguyên nhân**:
  - Thẻ KPI đầu tiên hiển thị `totalCount` (`meta.total` của toàn bộ DB), nhưng các thẻ KPI phân loại bên cạnh (ví dụ: Tạm trú / Tạm vắng / Chờ duyệt / Đã duyệt / Từ chối ở Chức năng 3; Chủ hộ / Chủ sở hữu / Khách thuê / Thành viên ở Chức năng 2; Xe máy / Ô tô / Doanh thu dự kiến ở Chức năng 5) lại tính bằng `.filter()` trên mảng dữ liệu của trang hiện tại (`15` dòng/trang). Khi tổng số bản ghi `> 15`, tổng các thẻ thành phần không khớp với thẻ Tổng.
- **Kết quả mong đợi**: Backend trả về object `summary_counts` tính trên toàn tập dữ liệu (hoặc theo bộ lọc hiện tại) để hiển thị nhất quán trên các thẻ KPI.

---

### 12. [BUG-12] (Low) Giao diện bảng Đăng ký tạm trú / tạm vắng (Chức năng 3) ở độ phân giải `1024px`, `768px`, `390px` cắt bớt văn bản dài (`truncate` / `line-clamp-3`) và ẩn `<table>` dưới `1024px`
- **Chức năng ảnh hưởng**: Chức năng 3 (Đăng ký tạm trú / tạm vắng và biểu mẫu CT01).
- **File & Vị trí**:
  - `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx` (Dòng `863`, `967`, `1029`, `1101–1278`)
- **Mô tả & Nguyên nhân**:
  - Ở `1920px`, `1440px`, `1280px`: Bảng hiển thị tốt, `table-fixed` chia 5 cột (`28%, 18%, 21%, 18%, 15%`), không có thanh cuộn ngang, hiển thị đủ thông tin và 4 nút thao tác.
  - Ở `1024px`: Khi hiển thị bên cạnh thanh Sidebar quản trị, chiều rộng vùng bảng còn khoảng `~740px`; cột "Lý do" sử dụng `line-clamp-3` (dòng `967`) và cột "Người duyệt" sử dụng `truncate` (dòng `1029`), làm ẩn phần chữ vượt quá giới hạn nếu không bấm vào nút "Xem chi tiết".
  - Ở `768px` và `390px`: Khối `<table>` dùng class `hidden lg:block` (dòng `863`) nên bị ẩn hoàn toàn và chuyển sang dạng danh sách thẻ (Card View `block lg:hidden` tại dòng `1101`), trong đó tên cư dân vẫn gắn class `truncate` (dòng `1121`).

---

## III. TỔNG HỢP SỐ LIỆU KIỂM THỬ

### 1. Thống kê Test Case & Mức độ lỗi
- **Tổng số nhóm kịch bản kiểm thử (Test Cases) trong bảng**: **62** kịch bản lớn (bao phủ **315** automated assertions/test methods trong PHPUnit + kiểm thử tĩnh/động toàn diện Frontend & Backend).
- **Số lượng theo kết quả**:
  - **PASS**: **48** / 62 kịch bản (PHPUnit: **296 / 296** test methods chạy thực tế đạt PASS sau khi kiểm tra cô lập 3 test bị nhiễu dữ liệu tạm; TypeScript typecheck PASS; Vite production build PASS; Pint code style check PASS).
  - **FAIL**: **12** / 62 kịch bản (phát hiện **12 lỗi** mã nguồn/nghiệp vụ/bảo mật/giao diện).
  - **UNVERIFIED**: **2** / 62 kịch bản (do giới hạn môi trường an toàn).
- **Số lượng lỗi theo mức độ nghiêm trọng ( Tổng cộng: 12 lỗi)**:
  - **Critical**: **2** lỗi (`[BUG-01]`, `[BUG-02]`)
  - **High**: **3** lỗi (`[BUG-03]`, `[BUG-04]`, `[BUG-05]`)
  - **Medium**: **5** lỗi (`[BUG-06]`, `[BUG-07]`, `[BUG-08]`, `[BUG-09]`, `[BUG-10]`)
  - **Low**: **2** lỗi (`[BUG-11]`, `[BUG-12]`)

### 2. Thứ tự ưu tiên sửa lỗi khuyến nghị
1. **[BUG-01] (Critical)**: Bổ sung middleware `auth.bearer` và `assertStaffAuthorized()` cho toàn bộ route `/api/v1/reception/parcels*`; lọc danh sách bưu phẩm của cư dân theo đúng `apartment_id` của cư dân đó.
2. **[BUG-02] (Critical)**: Gỡ bỏ/giới hạn `$demoFallbacks` và `'debug_otp'` trong `AuthController.php` và demo tokens trong `ReceptionDashboardController.php` chỉ hoạt động khi `app()->environment('local', 'testing')`.
3. **[BUG-04] (High)**: Tích hợp kiểm tra quyền `RbacService::hasPermission()` / middleware `permission:` vào các controller Chức năng 2–13 thay vì chỉ kiểm tra `hasRole()`.
4. **[BUG-05] (High)**: Bỏ `|| true` và `=> true` trong `QuocTinRealtimeService::isUserAuthorizedForChannel()` để phân quyền chặt chẽ các kênh SSE Realtime.
5. **[BUG-03] (High)**: Mở quyền cho phép Cư dân (`RESIDENT`, `RESIDENT_OWNER`, `RESIDENT_MEMBER`) tạo và xem hồ sơ đăng ký tạm trú / tạm vắng của chính căn hộ mình ở Chức năng 3.
6. **[BUG-06] (Medium)**: Bổ sung API và nút "Từ chối đăng ký" (Reject) phương tiện ở Chức năng 5.
7. **[BUG-07] (Medium)**: Bổ sung chế độ "Xem lịch tuần" (Week View) trên giao diện `ManagementStaffSchedulePanel.tsx` ở Chức năng 12.
8. **[BUG-08] (Medium)**: Sửa điều kiện lọc `listVisitors` theo căn hộ và bỏ fallback `DB::table('apartments')->first()` trong `ResidentVisitorService.php`.
9. **[BUG-09] (Medium)**: Xử lý khôi phục hoặc trả mã lỗi `422` khi thêm lại cư dân đã xóa mềm vào cùng căn hộ trong `ResidentService.php`.
10. **[BUG-10] (Medium)**: Bỏ ép kiểu `(int) $actorId` trong `AccessCardService.php`.
11. **[BUG-11] & [BUG-12] (Low)**: Đồng bộ số liệu tổng cho các thẻ KPI phụ ở Chức năng 2, 3, 5 và bỏ `truncate`/`line-clamp-3` gây khuất thông tin ở độ phân giải `1024px–390px` trong `TemporaryRegistrationManagement.tsx`.

---

## IV. DANH SÁCH CHỨC NĂNG / KỊCH BẢN CHƯA ĐỦ ĐIỀU KIỆN TEST (`UNVERIFIED`)

1. **Gửi thông báo qua hạ tầng SMTP thực tế & Push Service trình duyệt ngoại mạng (Một phần Chức năng 10)**:
   - **Lý do**: Container Docker local không kết nối tới máy chủ SMTP thật ngoài Internet và không gắn khóa VAPID production kết nối tới FCM/Mozilla Push Endpoint thật của trình duyệt người dùng cuối.
   - **Phạm vi đã xác minh**: Đã kiểm thử đầy đủ logic nghiệp vụ, hàng đợi (`SendParcelNotificationJob`), cấu trúc payload, xử lý lỗi `404/410` và chống báo thành công giả thông qua `ParcelNotificationTest.php` (15/15 PASS).
2. **Kiểm thử tải đồng thời qua cURL HTTP trực tiếp (`ResidentConcurrencyTest` & `TemporaryRegistrationConcurrencyTest`)**:
   - **Lý do**: Tiến trình web server trong container (`http://127.0.0.1:8000`) đang trỏ vào database chính `quanly_toanha`. Để tuân thủ tuyệt đối nguyên tắc **không ghi/thay đổi dữ liệu thật**, 2 file test cURL này tự động kích hoạt cơ chế an toàn `markTestSkipped` (19 tests).
   - **Phạm vi đã xác minh**: Đã kiểm thử tranh chấp đồng thời (Race Condition / Database Locking `lockForUpdate()`) trực tiếp trên database test `quanly_toanha_test` qua `ResidentCreateConcurrencyTest.php` (8/8 PASS).

---

## V. DANH SÁCH CÁC LỆNH ĐÃ CHẠY VÀ KẾT QUẢ THỰC TẾ

| STT | Lệnh thực thi | Mục đích | Kết quả thực tế |
| :---: | :--- | :--- | :--- |
| 1 | `git status -sb` | Kiểm tra nhánh Git hiện tại và trạng thái working tree trước khi kiểm thử | Branch `QuocTin/Fix_all...origin/QuocTin/Fix_all [ahead 235]`, ghi nhận nguyên trạng các file đang modified/untracked. |
| 2 | `docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"` | Kiểm tra các container Docker đang chạy | `smart_cassavas_app`, `smart_cassavas_db`, `smart_cassavas_redis`, `smart_cassavas_meilisearch`, `smart_cassavas_phpmyadmin` đều `Up`. |
| 3 | `docker compose exec -T db mysql -uroot -proot -e "SHOW DATABASES;"` | Kiểm tra sự tồn tại của database kiểm thử độc lập | Tồn tại database test `quanly_toanha_test` tách biệt hoàn toàn với `quanly_toanha`. |
| 4 | `docker compose exec -T app npm run typecheck` | Kiểm tra lỗi kiểu dữ liệu TypeScript (`tsc --noEmit`) trên toàn bộ Frontend | **PASS** — Exit code `0`, không có lỗi TypeScript. |
| 5 | `docker compose exec -T app vendor/bin/pint --test` | Kiểm tra chuẩn định dạng code PHP ở chế độ chỉ đọc (`--test`) | **PASS** — `PASS   ... 347 files`, Exit code `0`. |
| 6 | `docker compose exec -T app npx vite build --outDir /tmp/vite-build-check` (kèm xóa `/tmp/vite-build-check` sau khi kiểm tra) | Kiểm tra khả năng đóng gói bundle production của toàn bộ trang/component React | **PASS** — `✓ 3853 modules transformed. ✓ built in 1m 51s`, Exit code `0`. |
| 7 | `docker compose exec -T -e DB_DATABASE=quanly_toanha_test app vendor/bin/phpunit tests/Feature/Rbac/RbacManagementTest.php tests/Feature/RbacSecurityTest.php tests/Feature/AuthSecurityRegressionTest.php tests/Feature/Residents/ResidentManagementTest.php tests/Feature/Residents/ResidentCreateTest.php tests/Feature/TemporaryRegistrations/TemporaryRegistrationTest.php tests/Feature/TemporaryRegistrations/TemporaryRegistrationUpdateDeleteTest.php tests/Feature/AccountProvisioning/AccountProvisioningTest.php tests/Feature/Vehicles/VehicleManagementTest.php tests/Feature/Vehicles/ReceptionSecurityVehicleTest.php tests/Feature/RfidManagement/RfidManagementTest.php tests/Feature/ResidentVisitor/ResidentVisitorTest.php tests/Feature/ReceptionVisitor/ReceptionVisitorCheckinTest.php tests/Feature/ReceptionParcel/ReceptionParcelTest.php tests/Feature/Notifications/ParcelNotificationTest.php tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php tests/Feature/StaffSchedule/StaffScheduleTest.php tests/Feature/ReceptionDashboard/ReceptionDashboardTest.php` | Chạy 18 bộ Feature Test chính của 13 chức năng trên DB test `quanly_toanha_test` | `Tests: 273, Assertions: 1171` — **270 PASS**, 3 test bị nhiễu trạng thái dữ liệu khi chạy nối tiếp hàng loạt; phát hiện log HTTP 500 `ResidentConflictException` ở `test_case_14_soft_deleted_resident_behavior`. |
| 8 | `docker compose exec -T -e DB_DATABASE=quanly_toanha_test app vendor/bin/phpunit tests/Feature/RealtimeSyncTest.php tests/Feature/Residents/ResidentCreateConcurrencyTest.php tests/Feature/Residents/ResidentConcurrencyTest.php tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php` | Chạy 4 bộ Feature Test còn lại (Realtime & Concurrency) trên DB test `quanly_toanha_test` | **PASS** — `Tests: 42, Assertions: 58, Skipped: 19` (23/23 test chạy thực tế đạt PASS, 19 test cURL tự động skip để bảo vệ DB thật). |
| 9 | `docker compose exec -T -e DB_DATABASE=quanly_toanha_test app vendor/bin/phpunit --filter "test_26_27_28_no_changes_to_resident_apartment_rbac\|test_14_resend_activation_gui_lai_email\|test_11_parking_fee_is_automatically_pushed_to_invoice" ...` | Chạy kiểm tra cô lập 3 test bị nhiễu dữ liệu ở Lệnh 7 trên `quanly_toanha_test` | **PASS** — `OK (3 tests, 19 assertions)`, Exit code `0`. |
| 10 | `git status -s` | Xác nhận không có bất kỳ file mã nguồn nào bị chỉnh sửa trong quá trình kiểm thử | Trạng thái git giữ nguyên 100% so với ban đầu. |

---

## VI. DANH SÁCH CÁC FILE ĐÃ ĐỌC ĐỂ ĐỐI CHIẾU

### 1. Tài liệu, Cấu hình, Route & Middleware
1. `AGENTS.md`
2. `README.md`
3. `Danh_Sach_File_Chuc_Nang_QuocTin_1-13.html`
4. `composer.json`
5. `package.json`
6. `phpunit.xml`
7. `docker-compose.yml`
8. `routes/web.php`
9. `tests/TestCase.php`
10. `app/Http/Middleware/AuthenticateBearer.php`
11. `app/Http/Middleware/CheckModuleCooldown.php`

### 2. Backend (Controllers, Services, Requests, Models, Events, Listeners, Jobs, Mail)
12. `app/Http/Controllers/AuthController.php`
13. `app/Http/Controllers/UserController.php`
14. `app/Http/Controllers/RoleController.php`
15. `app/Http/Controllers/PermissionController.php`
16. `app/Services/RbacService.php`
17. `app/Models/User.php`
18. `app/Http/Controllers/ResidentController.php`
19. `app/Services/ResidentService.php`
20. `app/Http/Requests/ResidentRequest.php`
21. `app/Models/Resident.php`
22. `app/Http/Controllers/TemporaryRegistrationController.php`
23. `app/Services/TemporaryRegistrationService.php`
24. `app/Http/Requests/TemporaryRegistrationRequest.php`
25. `app/Models/TemporaryRegistration.php`
26. `app/Http/Controllers/AccountProvisioningController.php`
27. `app/Services/AccountProvisioningService.php`
28. `app/Http/Requests/AccountProvisioningRequest.php`
29. `app/Mail/AccountActivationMail.php`
30. `app/Http/Controllers/VehicleController.php`
31. `app/Services/VehicleService.php`
32. `app/Http/Requests/VehicleRequest.php`
33. `app/Models/Vehicle.php`
34. `app/Http/Controllers/AccessCardController.php`
35. `app/Services/AccessCardService.php`
36. `app/Http/Requests/AccessCardRequest.php`
37. `app/Models/AccessCard.php`
38. `app/Http/Controllers/ResidentVisitorController.php`
39. `app/Services/ResidentVisitorService.php`
40. `app/Models/VisitorRegistration.php`
41. `app/Http/Controllers/ReceptionVisitorController.php`
42. `app/Services/ReceptionVisitorService.php`
43. `app/Http/Controllers/ReceptionParcelController.php`
44. `app/Services/ReceptionParcelService.php`
45. `app/Models/Parcel.php`
46. `app/Http/Controllers/PushNotificationController.php`
47. `app/Services/WebPushService.php`
48. `app/Events/ParcelReceivedEvent.php`
49. `app/Listeners/SendParcelNotificationListener.php`
50. `app/Jobs/SendParcelNotificationJob.php`
51. `app/Mail/ParcelReceivedMail.php`
52. `app/Http/Controllers/StaffScheduleController.php`
53. `app/Services/StaffScheduleService.php`
54. `app/Http/Controllers/ReceptionDashboardController.php`
55. `app/Services/ReceptionDashboardService.php`
56. `app/Services/QuocTinRealtimeService.php`
57. `app/Http/Controllers/RealtimeStreamController.php`

### 3. Frontend (Pages, Components, Hooks, Services, Service Worker)
58. `resources/js/Pages/Admin/RbacManagement.tsx`
59. `resources/js/Hooks/usePermission.ts`
60. `resources/js/Pages/Admin/ResidentManagement.tsx`
61. `resources/js/Pages/Admin/TemporaryRegistrationManagement.tsx`
62. `resources/js/Pages/Admin/AccountProvisioningManagement.tsx`
63. `resources/js/Pages/Auth/AccountActivationPage.tsx`
64. `resources/js/Pages/Admin/VehicleManagement.tsx`
65. `resources/js/Pages/Admin/RfidManagement.tsx`
66. `resources/js/Components/ResidentVisitorRegistrationPanel.tsx`
67. `resources/js/Components/ReceptionVisitorCheckinPanel.tsx`
68. `resources/js/Components/ReceptionParcelPanel.tsx`
69. `resources/js/Components/ResidentParcelWidget.tsx`
70. `resources/js/Components/ResidentPushNotificationToggle.tsx`
71. `public/sw.js`
72. `resources/js/Components/ManagementStaffSchedulePanel.tsx`
73. `resources/js/Services/staffScheduleApi.ts`
74. `resources/js/Components/ReceptionDashboardPanel.tsx`
75. `resources/js/services/api.ts`

### 4. Automated Tests (Feature Tests)
76. `tests/Feature/Rbac/RbacManagementTest.php`
77. `tests/Feature/RbacSecurityTest.php`
78. `tests/Feature/AuthSecurityRegressionTest.php`
79. `tests/Feature/Residents/ResidentManagementTest.php`
80. `tests/Feature/Residents/ResidentCreateTest.php`
81. `tests/Feature/Residents/ResidentCreateConcurrencyTest.php`
82. `tests/Feature/Residents/ResidentConcurrencyTest.php`
83. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationTest.php`
84. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationUpdateDeleteTest.php`
85. `tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php`
86. `tests/Feature/AccountProvisioning/AccountProvisioningTest.php`
87. `tests/Feature/Vehicles/VehicleManagementTest.php`
88. `tests/Feature/Vehicles/ReceptionSecurityVehicleTest.php`
89. `tests/Feature/RfidManagement/RfidManagementTest.php`
90. `tests/Feature/ResidentVisitor/ResidentVisitorTest.php`
91. `tests/Feature/ReceptionVisitor/ReceptionVisitorCheckinTest.php`
92. `tests/Feature/ReceptionParcel/ReceptionParcelTest.php`
93. `tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php`
94. `tests/Feature/Notifications/ParcelNotificationTest.php`
95. `tests/Feature/StaffSchedule/StaffScheduleTest.php`
96. `tests/Feature/ReceptionDashboard/ReceptionDashboardTest.php`
97. `tests/Feature/RealtimeSyncTest.php`

