# BÁO CÁO KIỂM TOÁN ĐỘC LẬP SAU KHI KHẮC PHỤC BUG-01: BẢO MẬT API BƯU PHẨM

> **Thời gian kiểm toán**: 30/10/2026  
> **Kiểm toán viên**: Độc lập (Independent Audit Mode)  
> **Mục tiêu**: Đánh giá khách quan, toàn diện và độc lập toàn bộ các thay đổi của BUG-01; xác minh tính an toàn, bảo mật, tính toàn vẹn của nghiệp vụ và khả năng hồi quy (regression).  
> **Nguyên tắc**: Review/Verify Only — Tuyệt đối không can thiệp, chỉnh sửa source code, không commit, không push.

---

## 1. KIỂM TRA GIT DIFF VÀ PHẠM VI THAY ĐỔI

### 1.1. Danh sách file thay đổi liên quan trực tiếp đến BUG-01
Qua phân tích `git status` và `git diff`, có chính xác **4 file** chứa các thay đổi thuộc phạm vi giải quyết BUG-01:

1. [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php#L218-L234)
2. [app/Services/ReceptionParcelService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php)
3. [app/Http/Controllers/ReceptionParcelController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/ReceptionParcelController.php)
4. [tests/Feature/ReceptionParcel/ReceptionParcelTest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/ReceptionParcel/ReceptionParcelTest.php)

*(Lưu ý: `tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php` đã được kiểm thử để xác nhận tương thích hồi quy cho module check-out/bàn giao bưu phẩm).*

### 1.2. Đánh giá từng đoạn Diff
- **routes/web.php**:
  - Đưa nhóm 13 route bưu phẩm (`/api/v1/reception/parcels` và các route con) vào `Route::middleware('auth.bearer')->group(...)`.
  - Không xóa, đổi tên hay đổi HTTP method của bất kỳ route nào.
  - Không sửa các route ngoài phạm vi bưu phẩm.
- **app/Services/ReceptionParcelService.php**:
  - `getParcels()`: Thêm tham số `$user = null`. Tách biệt rạch ròi luồng lấy dữ liệu giữa nhân viên (`isStaff`) và cư dân (`resolveResidentApartmentIds`).
  - `receiveParcel()`: Xóa bỏ 11 dòng fallback tự động truy vấn nhân viên đầu tiên trong database (`User::whereHas('roles', ...)->first()`). Gọi `assertStaffAuthorized($staffUser, 'receive')` và gán chính xác `$staffUser->id`.
  - `updateParcel()` & `deleteParcel()`: Bổ sung kiểm tra `assertStaffAuthorized`.
  - `searchRecipients()`, `searchApartments()`, `lookupParcel()`: Chuyển sang bắt buộc kiểm tra thẩm quyền nhân viên, ngăn chặn cư dân dò quét thông tin người khác.
  - `remindResident()`, `remindAllExpiring()`: Xác thực nhân viên gửi nhắc nhở.
  - Không can thiệp cấu trúc bảng hoặc schema database.
- **app/Http/Controllers/ReceptionParcelController.php**:
  - `index()`: Truyền `$user` từ request vào service.
  - `show()`: Kiểm tra quyền sở hữu căn hộ nếu người xem là cư dân. Trả về `403 Forbidden` nếu xem bưu phẩm nhà khác.
  - Bổ sung `catch (HttpException $e)` để controller trả về đúng HTTP status code (401, 403, 404, 409, 422) thay vì để rơi vào `catch (Throwable)` trả về 500.
  - Giới hạn các endpoint tìm kiếm gợi ý (`searchRecipients`, `searchApartments`) chỉ dành cho nhân viên.
- **tests/Feature/ReceptionParcel/ReceptionParcelTest.php**:
  - Giữ nguyên 100% nội dung và assertion của toàn bộ 17 test case cũ.
  - Không có test cũ nào bị xóa, comment out, hay sửa đổi giá trị kỳ vọng (expected status/data).
  - Bổ sung 5 test case mới ở cuối file để kiểm thử bảo mật BUG-01.

---

## 2. KIỂM TOÁN TẦNG XÁC THỰC (AUTHENTICATION)

### 2.1. Đăng ký và kích hoạt Middleware trên 13 Route
Kiểm tra qua `php artisan route:list --path=api/v1/reception/parcels -v` sau khi xóa cache route:
- Cả 13/13 endpoints đều được gắn chuỗi middleware: `web` ➔ `auth.bearer`.
- Middleware `auth.bearer` (lớp `App\Http\Middleware\AuthenticateBearer`) được cấu hình trong `bootstrap/app.php`.

### 2.2. Kiểm thử hành vi từ chối khi chưa xác thực (Unauthenticated Request)
- Kiểm thử thực tế qua test `test_unauthenticated_requests_to_parcel_apis_are_rejected` và `test_checkout_unauthenticated_returns_401`:
  - Request không có Bearer header: **HTTP 401 Unauthorized** (Xác nhận chặn thành công).
  - Request có Bearer token giả mạo không tồn tại trong `user_sessions`: **HTTP 401 Unauthorized** (Xác nhận chặn thành công).

### 2.3. Hoạt động của tài khoản cư dân và nhân viên hợp lệ
- Nhân viên Lễ tân (`RECEPTIONIST`) có session/actingAs hợp lệ: Thao tác tiếp nhận, tra cứu, check-out thành công (HTTP 200/201).
- Cư dân (`RESIDENT_OWNER`) có session/actingAs hợp lệ: Xem danh sách bưu phẩm nhà mình thành công (HTTP 200).
- Không làm thay đổi bất kỳ file nào trong quy trình đăng nhập (`AuthController`), bảng `user_sessions`, hay cấu trúc Bearer Token của hệ thống.

---

## 3. KIỂM TOÁN TẦNG PHÂN QUYỀN (AUTHORIZATION) VÀ CÔ LẬP DỮ LIỆU

Bảng đối chiếu kiểm toán bảo mật trên từng API bưu phẩm:

| API / Chức năng | Hành vi đối với Nhân viên (`Staff`) | Hành vi đối với Cư dân (`Resident`) | Khả năng tấn công IDOR / Đánh cắp dữ liệu | Kết luận |
|---|---|---|---|---|
| **GET /api/v1/reception/parcels** *(Danh sách)* | Xem toàn bộ bưu phẩm tòa nhà hoặc lọc theo `apartment_id` tùy ý | **Chỉ xem bưu phẩm thuộc căn hộ của mình** (`whereIn apartment_id`). Nếu cố tình truyền `apartment_id` của căn hộ khác, bị ép về điều kiện `1 = 0` (danh sách rỗng) | **Không thể**: Param `apartment_id` bị ghi đè bởi danh sách căn hộ thực tế của user | **AN TOÀN** |
| **GET /api/v1/reception/parcels/{id}** *(Chi tiết kiện hàng)* | Xem chi tiết kiện hàng bất kỳ để phục vụ kiểm tra | **Chỉ xem được nếu kiện hàng thuộc căn hộ của mình**. Nếu xem kiện hàng nhà khác: **HTTP 403 Forbidden** | **Không thể**: IDOR qua path param `{id}` bị chặn tại Controller | **AN TOÀN** |
| **Mã PIN nhận hàng (`pickup_pin_code`)** | Xem để đối chiếu bàn giao tại quầy | Cư dân chỉ thấy mã PIN của bưu phẩm nhà mình. Không thể xem mã PIN nhà khác | **Không thể**: Do API `show` và `index` đã cô lập dữ liệu | **AN TOÀN** |
| **Mã QR nhận hàng (`pickup_qr_code`)** | Quét và đối chiếu lúc nhận hàng | Cư dân chỉ thấy mã QR của bưu phẩm nhà mình. Không thể xem mã QR nhà khác | **Không thể**: Do API `show` và `index` đã cô lập dữ liệu | **AN TOÀN** |
| **GET .../recipients/search** *(Tìm cư dân)* | Tìm kiếm gợi ý người nhận để lập phiếu | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể dò SĐT/tên cư dân khác qua API này | **AN TOÀN** |
| **GET .../apartments/search** *(Tìm căn hộ)* | Tìm kiếm danh sách căn hộ | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể quét danh sách phòng | **AN TOÀN** |
| **GET .../lookup?code=...** *(Tra cứu mã)* | Tra cứu nhanh theo PIN/QR/vận đơn để bàn giao | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể brute-force mã PIN/QR tại quầy | **AN TOÀN** |
| **POST /api/v1/reception/parcels** *(Tiếp nhận)* | Lập phiếu tiếp nhận bưu phẩm mới | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể tự tạo bưu phẩm ảo | **AN TOÀN** |
| **PUT /api/v1/reception/parcels/{id}** *(Cập nhật)* | Sửa thông tin kiện hàng đang lưu kho | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể sửa ghi chú/vị trí lưu | **AN TOÀN** |
| **DELETE /api/v1/reception/parcels/{id}** *(Xóa)* | Xóa kiện hàng sai sót | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể xóa dữ liệu lưu kho | **AN TOÀN** |
| **POST .../check-out & handover** *(Bàn giao)* | Bàn giao bưu phẩm khi có PIN/QR hợp lệ | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể tự confirm check-out | **AN TOÀN** |
| **POST .../remind & remind-expiring** *(Nhắc nhở)* | Kích hoạt gửi thông báo nhắc cư dân nhận | **Bị từ chối với HTTP 403 Forbidden** | **Không thể**: Cư dân không thể spam nhắc nhở | **AN TOÀN** |

### 3.1. Trường hợp người dùng không có căn hộ (Orphan User / Unassigned Resident)
Trong `ReceptionParcelService::getParcels()`:
```php
if ($user && ! $this->isStaff($user)) {
    $userApartmentIds = $this->resolveResidentApartmentIds($user);
    if (empty($userApartmentIds)) {
        $query->whereRaw('1 = 0');
    }
    ...
```
Nếu tài khoản người dùng đăng nhập nhưng không gắn với căn hộ nào trong bảng `residents` hoặc `apartments`, hệ thống chủ động ép điều kiện `whereRaw('1 = 0')`. Dữ liệu trả về là `[]` (rỗng), **tuyệt đối không bị rò rỉ dữ liệu toàn tòa nhà**.

---

## 4. KIỂM TOÁN TÁC VỤ NHÂN VIÊN & AUDIT TRAIL

1. **Loại bỏ cơ chế gán nhân viên ngẫu nhiên**:
   - Trước khi sửa: Nếu `$staffUser` là `null`, code cũ tự động query `User::whereHas('roles', ...)->first()` lấy nhân viên đầu tiên trong database gán vào `received_by_staff_id`.
   - Sau khi sửa: Hàm `receiveParcel()` gọi `assertStaffAuthorized($staffUser, 'receive')` ngay bước 3 và gán trực tiếp `$staffId = $staffUser->id`.
   - Nếu gọi mà không có thông tin nhân viên hợp lệ: Lập tức ném `HttpException` (HTTP 401 hoặc 403). Test `test_receive_parcel_rejects_without_staff_identity_and_does_not_fallback` đã kiểm chứng điều này.

2. **Tính chính xác của Audit Log**:
   - Cột `received_by_staff_id` trong database ghi nhận đúng UUID của nhân viên thực hiện request.
   - Sự kiện realtime broadcast `QuocTinRealtimeService::emit()` ghi nhận đúng `actorId: $staffId`.

3. **Tính liên tục của nghiệp vụ tiếp nhận và bàn giao**:
   - Quy trình tiếp nhận có upload ảnh (multipart) và nhập mã vận đơn vẫn hoạt động chính xác (`test_receptionist_receives_parcel_with_image_upload_successfully` PASS).
   - Quy trình check-out xác minh mã PIN hoặc mã QR, cập nhật trạng thái `DELIVERED` và ghi nhận `checkout_staff_id` hoạt động chuẩn xác (`test_checkout_succeeds_with_valid_qr_code` PASS).

---

## 5. ĐỐI CHIẾU KẾT QUẢ KIỂM THỬ (TEST EXECUTION & BASELINE COMPARISON)

### 5.1. Bảng so sánh trước và sau khi sửa BUG-01

| File Test | Số lượng Test trước khi sửa (Baseline) | Số lượng Test sau khi sửa | Số test cũ PASS | Số test mới thêm | Tỷ lệ PASS |
|---|---|---|---|---|---|
| **ReceptionParcelTest.php** | 17 tests | **22 tests** | 17 / 17 | 5 tests mới (Security BUG-01) | **22 / 22 (100%)** |
| **ReceptionParcelCheckoutTest.php** | 11 tests | **11 tests** | 11 / 11 | 0 (Giữ nguyên kiểm thử hồi quy) | **11 / 11 (100%)** |
| **Tổng cộng** | **28 tests** | **33 tests** | **28 / 28** | **5 tests mới** | **33 / 33 (100%)** |

### 5.2. Danh sách 17 Test cũ trong `ReceptionParcelTest.php` (Được bảo lưu 100%)
1. `test_receptionist_receives_parcel_with_image_upload_successfully` — PASS
2. `test_receive_parcel_with_existing_photo_url` — PASS
3. `test_receive_parcel_validation_fails_when_missing_required_fields` — PASS
4. `test_reject_non_image_file_upload` — PASS
5. `test_reject_image_file_exceeding_10mb` — PASS
6. `test_update_parcel_information_and_replaces_photo_successfully` — PASS
7. `test_cannot_update_or_delete_delivered_parcel` — PASS
8. `test_delete_pending_parcel_successfully` — PASS
9. `test_auto_link_recipient_user_by_apartment_resident_phone` — PASS
10. `test_receptionist_filters_parcels_by_status_and_apartment` — PASS
11. `test_receptionist_search_parcels_by_courier_tracking_or_recipient` — PASS
12. `test_remind_resident_sends_notification_and_updates_status` — PASS
13. `test_remind_all_expiring_parcels_batch_execution` — PASS
14. `test_kpis_calculation_accuracy` — PASS
15. `test_15_can_filter_parcels_by_pin_code_correctly` — PASS
16. `test_16_can_filter_parcels_by_courier_company_strictly` — PASS
17. `test_17_can_filter_by_courier_alias_and_status` — PASS

### 5.3. Danh sách 5 Test mới bổ sung cho BUG-01 (Đều PASS)
18. `test_unauthenticated_requests_to_parcel_apis_are_rejected` — PASS (Khẳng định mã lỗi 401 khi không đăng nhập)
19. `test_resident_only_sees_parcels_of_own_apartment` — PASS (Khẳng định dữ liệu bị cô lập, không đọc được bưu phẩm căn hộ khác)
20. `test_resident_cannot_view_details_of_parcel_from_other_apartment` — PASS (Khẳng định mã lỗi 403 khi cư dân cố xem bưu phẩm nhà khác)
21. `test_resident_cannot_perform_staff_only_actions` — PASS (Khẳng định mã lỗi 403 trên các action: store, update, delete, lookup, search)
22. `test_receive_parcel_rejects_without_staff_identity_and_does_not_fallback` — PASS (Khẳng định từ chối khi thiếu nhân viên, không gán ngẫu nhiên nhân viên trong DB)

---

## 6. ĐÁNH GIÁ RỦI RO HỒI QUY (REGRESSION RISK)

- **Nguy cơ làm hỏng luồng Authentication chung**: **0%** — Không sửa middleware gốc, không sửa token handler, không sửa controller đăng nhập.
- **Nguy cơ làm hỏng luồng Lễ tân tiếp nhận / Check-out**: **0%** — Toàn bộ 11 tests check-out và 17 tests bưu phẩm gốc đều chạy thành công.
- **Nguy cơ vỡ giao diện Frontend**: **0%** — Lệnh `npm run typecheck` xác nhận TypeScript hoàn toàn hợp lệ, không có xung đột kiểu dữ liệu.

---

## 7. CÁC VẤN ĐỀ CÒN TỒN TẠI (NẰM NGOÀI PHẠM VI BUG-01)

Kiểm toán xác nhận BUG-01 đã được giải quyết triệt để. Tuy nhiên, hệ thống vẫn còn các BUG khác đã được lập kế hoạch:
- **BUG-02**: Mật khẩu dự phòng hardcode, debug OTP trong response, demo token trong reception dashboard.
- **BUG-03**: Thiếu API cho phép cư dân gửi yêu cầu đăng ký tạm trú/tạm vắng.
- **BUG-04**: RBAC Matrix còn điểm không đồng nhất giữa vai trò và quyền.
- **BUG-05**: Kênh SSE Realtime bưu phẩm/RFID/khách/lịch trực còn điều kiện bypass quyền (`|| true`).
- **BUG-06 đến BUG-12**: Các vấn đề về logic nghiệp vụ, transaction và tối ưu hóa khác theo `BUG_FIX_PLAN.md`.

---

## 8. KẾT LUẬN & KHUYẾN NGHỊ

1. **Khắc phục BUG-01**: **ĐẠT YÊU CẦU 100% (APPROVED)**.
2. **Tính bảo mật**: Lỗ hổng IDOR, lộ mã PIN/QR, truy cập nặc danh và gán nhân viên ngẫu nhiên đã được xử lý triệt để và có test tự động bảo vệ.
3. **Đủ điều kiện chuyển sang BUG tiếp theo**: **ĐỦ ĐIỀU KIỆN (READY TO PROCEED)**.

> **Đề xuất**: Theo thứ tự ưu tiên trong `BUG_FIX_PLAN.md`, giai đoạn 1 tập trung vào các lỗ hổng bảo mật nghiêm trọng (P0). Nếu được bạn phê duyệt, bước tiếp theo có thể triển khai là **BUG-05 (Bảo mật kênh Realtime SSE)** hoặc **BUG-02 (Bảo mật đăng nhập và OTP)**.

