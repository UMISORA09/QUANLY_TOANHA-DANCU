# BÁO CÁO PHÂN TÍCH TOÀN DIỆN VÀ LẬP KẾ HOẠCH KHẮC PHỤC BUG-05: BẢO MẬT KÊNH REALTIME SSE

> **Dự án**: `QUANLY_TOANHA-DANCU`  
> **Thời gian phân tích**: 01/11/2026  
> **Người thực hiện**: Antigravity Assistant  
> **Trạng thái**: GIAI ĐOẠN PHÂN TÍCH & LẬP KẾ HOẠCH (ANALYSIS ONLY — CHƯA SỬA CODE)  
> **Phạm vi đối tượng**: Realtime SSE Streaming, Event Broadcasting, Phân quyền Kênh (RFID, Visitors, Parcels, Schedules)

---

## 1. TỔNG QUAN ĐIỀU HÀNH (EXECUTIVE SUMMARY)

Qua rà soát chuyên sâu toàn bộ mã nguồn backend và frontend của hệ thống Realtime SSE (Server-Sent Events), chúng tôi **XÁC NHẬN (CONFIRMED)** tồn tại các lỗ hổng bảo mật nghiêm trọng trong việc phân quyền và cô lập dữ liệu# BUG-05 – GIAI ĐOẠN SỬA 26 TEST FAIL (TEST-ONLY FIX)

Tôi phê duyệt một vòng sửa lỗi kiểm thử dựa trên kết quả PHPUnit runtime vừa thực hiện.

## 1. Kết quả hiện tại

- RealtimeSyncTest.php: 55 PASS / 26 FAIL.
- ReceptionParcelTest.php: 22 PASS / 0 FAIL.
- ReceptionParcelCheckoutTest.php: 11 PASS / 0 FAIL.
- Tổng cộng: 88 PASS / 26 FAIL.
- PHP syntax: PASS.
- npm run typecheck: PASS.

Không được làm mất kết quả PASS hiện tại.

## 2. Phạm vi được phép sửa

Chỉ được chỉnh sửa:

`tests/Feature/RealtimeSyncTest.php`

Tuyệt đối không chỉnh sửa:
- app/Services/QuocTinRealtimeService.php
- app/Http/Controllers/RealtimeStreamController.php
- routes/channels.php
- Frontend.
- Database schema, migration.
- ReceptionParcelTest.php.
- ReceptionParcelCheckoutTest.php.
- BUG-01 production code.

## 3. Xử lý từng nhóm lỗi

### Nhóm A – 22 test thiếu stay_start_date

Kiểm tra helper `createResidentUserWithSession()` và tất cả các lệnh insert trực tiếp vào bảng residents.

Yêu cầu:
- Bổ sung giá trị `stay_start_date` hợp lệ khi tạo fixture mặc định.
- Đảm bảo các test chủ động truyền ngày bắt đầu trong tương lai vẫn giữ nguyên giá trị đó.
- Không ghi đè dữ liệu ngày mà test đang cần kiểm tra.
- Đảm bảo dữ liệu phù hợp schema MySQL strict mode.

Không thay đổi schema chỉ để test chạy được.

### Nhóm B – 2 test duplicate role_permissions

Kiểm tra `createStaffUserWithPermission()`.

Yêu cầu:
- Không insert lại cặp role_id/permission_id đã tồn tại.
- Kiểm tra quan hệ trước khi tạo.
- Dùng cách tạo dữ liệu test idempotent, không gây unique constraint violation.
- Không thay đổi quyền trong production Seeder.

### Nhóm C – Test staff không có quyền phù hợp

Test `bug05_ws_09_staff_without_appropriate_permission_callback_returns_false` đang sử dụng role RECEPTIONIST nhưng Seeder đã cấp sẵn permission vận hành.

Yêu cầu:
- Không sử dụng role đã có PARCEL:VIEW hoặc RECEPTION:OPERATE để mô phỏng staff thiếu quyền.
- Chọn hoặc tạo fixture staff thuộc STAFF_ROLES nhưng chỉ có quyền VEHICLE:VIEW.
- Đảm bảo dữ liệu test thật sự không có PARCEL:VIEW, VISITOR:VIEW hoặc RECEPTION:OPERATE.
- Kiểm tra cả hai channel parcels và visitors nếu phù hợp.

Không sửa logic phân quyền chỉ để làm test PASS.

### Nhóm D – Cooldown assertion

Kiểm tra response thực tế của:

`GET /api/v1/realtime/cooldown`

Điều chỉnh assertion để khớp cấu trúc JSON thật.

Không thay đổi response API production.

Kiểm tra lại test có tên `test_bug05_exp_14_no_security_regression_on_bug01`.

Nếu test chỉ kiểm tra cooldown thì không được coi đó là kiểm thử toàn bộ BUG-01.

## 4. Quy trình kiểm thử sau sửa

Chạy lại:

1. RealtimeSyncTest.php.
2. ReceptionParcelTest.php.
3. ReceptionParcelCheckoutTest.php.
4. PHP syntax check 4 file BUG-05.
5. npm run typecheck.

Mục tiêu:
- RealtimeSyncTest: 81/81 PASS.
- ReceptionParcelTest: 22/22 PASS.
- ReceptionParcelCheckoutTest: 11/11 PASS.

Nếu có test FAIL mới:
- Không tự ý sửa production code.
- Phân tích nguyên nhân.
- Ghi tên test, assertion, exception và stack trace.
- Dừng lại chờ phê duyệt.

## 5. Kiểm tra Git

Kiểm tra:
- git status --short
- git diff --stat
- git diff --check

Chỉ test file được phép thay đổi trong giai đoạn này. Không ghi đè thay đổi có sẵn của thành viên khác.

## 6. Báo cáo và điều kiện dừng

Cập nhật `BUG_05_IMPLEMENTATION_REPORT.md`:
- 26 lỗi ban đầu.
- Nguyên nhân từng nhóm.
- Những test đã sửa.
- Kết quả trước và sau.
- Assertion thực tế.
- Bộ test BUG-01.
- Git diff.

Không commit, push hoặc tạo PR.

Hoàn thành thì dừng lại chờ nghiệm thu. Không chuyển sang BUG-02.# BUG-05 – FINAL POST-PINT VERIFICATION

Kết quả trước đó:
- PHPUnit: 114/114 PASS, 337 assertions.
- PHP syntax: 4/4 PASS.
- TypeScript: PASS.
- Git diff check trên phạm vi BUG-05: PASS.

Tuy nhiên, sau các lần kiểm thử đã chạy Pint trên:
- app/Services/QuocTinRealtimeService.php
- routes/channels.php
- tests/Feature/RealtimeSyncTest.php

Vì vậy cần xác minh lại phiên bản cuối cùng.

## 1. Không chỉnh sửa code

- Không sửa source code.
- Không sửa test.
- Không chạy Pint lại.
- Không thay đổi database.
- Không reset hoặc xóa Docker volume.
- Không commit, push hoặc tạo PR.

## 2. Chạy lại toàn bộ PHPUnit

Chạy lần lượt:

1. RealtimeSyncTest.php
2. ReceptionParcelTest.php
3. ReceptionParcelCheckoutTest.php

Mục tiêu:
- 81/81 PASS.
- 22/22 PASS.
- 11/11 PASS.
- Không có test FAIL hoặc SKIPPED.

Ghi lại assertions và kết quả thực tế.

## 3. Kiểm tra PHP syntax

Chạy php -l đối với:

- app/Services/QuocTinRealtimeService.php
- app/Http/Controllers/RealtimeStreamController.php
- routes/channels.php
- tests/Feature/RealtimeSyncTest.php

## 4. Kiểm tra TypeScript

Xác nhận frontend không có thay đổi sau lần typecheck PASS trước đó. Nếu môi trường khả dụng, chạy lại npm run typecheck.

## 5. Git audit

Chạy:

- git status --short
- git diff --check trên 4 file BUG-05.
- git diff --stat trên các file BUG-05.

Lưu ý:
Working tree có nhiều thay đổi và file chưa được theo dõi thuộc các chức năng khác. Tuyệt đối không reset, clean, checkout hoặc ghi đè chúng.

## 6. Báo cáo kết quả

Cập nhật BUG_05_IMPLEMENTATION_REPORT.md với kết quả kiểm thử sau Pint.

Nếu tất cả PASS, đề xuất chính thức đóng BUG-05.

Nếu có lỗi:
- Không tự sửa.
- Ghi rõ lỗi và stack trace.
- Dừng chờ phê duyệt.

Không chuyển sang BUG-02 trong lần chạy này. trên các kênh SSE:

1. **Bypass Authorization bằng điều kiện `|| true` và `=> true` (Mức độ: CRITICAL)**:
   - Tại [app/Services/QuocTinRealtimeService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L197-L206), phương thức `isUserAuthorizedForChannel()` đang tồn tại các biểu thức bypass:
     - Kênh `quoc-tin.rfid-cards`: Có biểu thức `... || true` (dòng 199).
     - Kênh `quoc-tin.visitors`: Luôn trả về `true` (dòng 201).
     - Kênh `quoc-tin.parcels`: Luôn trả về `true` (dòng 203).
     - Kênh `quoc-tin.schedules`: Luôn trả về `true` (dòng 205).
   - Tại [routes/channels.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/channels.php#L101), kênh `quoc-tin.rfid-cards` cũng chứa biểu thức bypass `|| true`.
   - **Hậu quả**: Bất kỳ người dùng nào sau khi đăng nhập (kể cả Cư dân thông thường hoặc tài khoản bị giới hạn quyền) đều có thể subscribe thành công vào cả 4 kênh dữ liệu nhạy cảm này.

2. **Thiếu cơ chế lọc dữ liệu Server-Side theo căn hộ (IDOR qua SSE) (Mức độ: CRITICAL)**:
   - Phương thức `RealtimeStreamController::stream()` và `events()` khi truy vấn dữ liệu từ bảng `realtime_sync_events` chỉ lọc theo `WHERE channel IN (...)`.
   - Không có bộ lọc theo `apartment_id`, `recipient_user_id` hay `host_resident_user_id`.
   - Dẫn đến việc cư dân căn hộ A có thể nghe lén thông tin khách đến thăm, kiện hàng bưu phẩm (tên người nhận, SĐT, mã vận đơn, ảnh gói hàng), thẻ RFID tòa nhà và lịch làm việc của nhân viên bảo vệ/lễ tân.

3. **Endpoint kiểm tra Cooldown không yêu cầu xác thực (Mức độ: MEDIUM)**:
   - Endpoint `GET /api/realtime/cooldown` và `/api/v1/realtime/cooldown` không kiểm tra `RbacService::resolveUser()`, cho phép client nặc danh kiểm tra trạng thái cooldown và ID của nhân viên đang thao tác.

---

## 2. TỔNG QUAN KIẾN TRÚC SSE HIỆN TẠI (SSE ARCHITECTURE OVERVIEW)

```
+-----------------------------------------------------------------------------------+
|                                 FRONTEND                                          |
|                                                                                   |
|  useRealtimeSync.ts                                                               |
|  - fetch('/api/realtime/stream?channels=...', { headers: { Authorization } })     |
|  - ReadableStream (getReader()) đọc chunks text/event-stream                       |
|  - Reconnect loop (cycle event mỗi ~2.5s)                                         |
+-----------------------------------------------------------------------------------+
                                        | (HTTP GET SSE / Bearer Token)
                                        v
+-----------------------------------------------------------------------------------+
|                                 BACKEND                                           |
|                                                                                   |
|  routes/web.php (Lines 936-941):                                                  |
|  - GET /api/realtime/stream        -> RealtimeStreamController@stream             |
|  - GET /api/v1/realtime/stream     -> RealtimeStreamController@stream             |
|  - GET /api/realtime/events        -> RealtimeStreamController@events             |
|  - GET /api/realtime/cooldown      -> RealtimeStreamController@cooldown           |
|                                                                                   |
|  RealtimeStreamController:                                                        |
|  - resolveUser($request)           -> Xác thực qua Bearer Token / user_sessions   |
|  - getAuthorizedChannels($user)    -> Gọi QuocTinRealtimeService                  |
|                                                                                   |
|  QuocTinRealtimeService:                                                          |
|  - isUserAuthorizedForChannel()    -> [LỖI]: rfid (|| true), visitors (=> true),  |
|                                                parcels (=> true), schedules (=> true)|
|  - getEvents($authorizedChannels)  -> [LỖI]: SELECT WHERE channel IN (...)        |
|                                       (Không lọc theo căn hộ / quyền hạn)         |
+-----------------------------------------------------------------------------------+
```

---

## 3. PHÂN TÍCH TẦNG XÁC THỰC (AUTHENTICATION ANALYSIS)

### 3.1. Cơ chế xác thực kết nối SSE hiện tại
- **Xác thực tại Controller**:
  - Tại `RealtimeStreamController::stream()` (dòng 51) và `events()` (dòng 178):
    ```php
    $user = RbacService::resolveUser($request);
    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
        ], 401);
    }
    ```
  - `RbacService::resolveUser($request)` đọc header `Authorization: Bearer <token>`, băm SHA-256 và tra cứu vào bảng `user_sessions` với điều kiện `is_revoked = 0` và `expires_at > now()`.
  - **Kết luận**: Người dùng chưa đăng nhập hoặc sử dụng token giả mạo, token hết hạn, token bị thu hồi đều bị từ chối với mã **HTTP 401 Unauthorized**.

### 3.2. Vấn đề tương thích EventSource của Trình duyệt
- Trình duyệt chuẩn với `new EventSource(url)` không cho phép đính kèm custom headers (`Authorization: Bearer`).
- Tuy nhiên, dự án hiện tại **không sử dụng `new EventSource()`**, mà triển khai custom SSE client thông qua `fetch()` kết hợp `ReadableStream` ([resources/js/Hooks/useRealtimeSync.ts#L178-L210](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Hooks/useRealtimeSync.ts#L178-L210)):
  ```typescript
  const headers: Record<string, string> = {
    Accept: 'text/event-stream',
    'Cache-Control': 'no-cache',
  };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  ```
- **Đánh giá**: Phương thức này hoàn toàn tương thích và không cần phải chuyển token lên query param không an toàn (`?token=...`).

### 3.3. Các sơ hở về Authentication
1. **Thiếu Middleware ở cấp độ Route**:
   - Các route `/api/realtime/stream`, `/api/v1/realtime/stream`, `/api/realtime/events` nằm ngoài middleware `auth.bearer`. Mặc dù controller có gọi `RbacService::resolveUser()`, việc thiếu middleware khiến route không được hưởng các tính năng rate-limiting hoặc security guard chung của pipeline.
2. **Endpoint `cooldown` không xác thực**:
   - `RealtimeStreamController::cooldown()` (dòng 211) không gọi `resolveUser()`, trả thông tin `actor_id` của nhân viên vừa thao tác cho client nặc danh.
3. **Demo Tokens**:
   - `RbacService::resolveUser()` chấp nhận 4 demo tokens (`smart_token_admin_demo`, etc.) nhưng đã được bọc an toàn trong điều kiện `if (app()->environment('testing', 'local'))`. Môi trường production không bị ảnh hưởng.

---

## 4. PHÂN TÍCH CHI TIẾT THEO TỪNG KÊNH (CHANNEL SECURITY ANALYSIS)

### 4.1. Kênh Thẻ RFID (`quoc-tin.rfid-cards` / `quoc-tin.rfid_cards`)
- **Vị trí lỗi**:
  - [app/Services/QuocTinRealtimeService.php:197-199](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L197-L199):
    ```php
    'quoc-tin.rfid-cards', 'quoc-tin.rfid_cards' => (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']))
        || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
        || true, // <--- LỖI BYPASS
    ```
  - [routes/channels.php:101](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/channels.php#L101):
    ```php
    return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) || true; // <--- LỖI BYPASS
    ```
- **Dữ liệu phát tán qua SSE**: Khi thẻ được tạo hoặc sửa ([AccessCardService.php:165-172](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/AccessCardService.php#L165-L172)):
  - `card_uid` (Mã vật lý RFID UID)
  - `card_number` (Số thẻ in trên mặt thẻ)
  - `card_type` (Thẻ căn hộ / gửi xe / thang máy)
  - `status` (ACTIVE / LOCKED / REVOKED)
  - `assigned_apartment_id`, `assigned_user_id`
- **Nguy cơ**: Kẻ tấn công hoặc cư dân tò mò có thể clone mã thẻ RFID hoặc theo dõi trạng thái thẻ của cư dân khác trong tòa nhà.
- **Vai trò được phép**: Chỉ nhân viên vận hành (`SUPER_ADMIN`, `ADMIN`, `BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD`).

---

### 4.2. Kênh Khách đến thăm (`quoc-tin.visitors`)
- **Vị trí lỗi**:
  - [app/Services/QuocTinRealtimeService.php:201](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L201):
    ```php
    'quoc-tin.visitors' => true, // <--- LỖI BYPASS TOÀN PHẦN
    ```
- **Dữ liệu phát tán qua SSE**:
  - Khi khách check-in ([ReceptionVisitorService.php:552-569](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionVisitorService.php#L552-L569)):
    - `visitor_name` (Họ tên khách)
    - `visitor_phone` (Số điện thoại)
    - `apartment_id` (Căn hộ đến thăm)
    - `checkin_time`, `status`
  - Khi cư dân đăng ký ([ResidentVisitorService.php:403-415](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ResidentVisitorService.php#L403-L415)):
    - `host_resident_user_id`
    - `apartment_id`
    - `registration_code`
- **Nguy cơ**: Vi phạm nghiêm trọng quyền riêng tư (Privacy Violation). Cư dân căn hộ bất kỳ có thể biết được ai đang đến thăm căn hộ khác, số điện thoại của khách và thời gian ra vào.
- **Phân quyền chuẩn**:
  - Nhân viên lễ tân/bảo vệ/quản lý: Được xem toàn bộ sự kiện khách đến tòa nhà.
  - Cư dân: **CHỈ** được nhận sự kiện khách liên quan đến căn hộ mình (`host_resident_user_id == user.id` hoặc `apartment_id` thuộc căn hộ của cư dân).

---

### 4.3. Kênh Bưu phẩm (`quoc-tin.parcels`)
- **Vị trí lỗi**:
  - [app/Services/QuocTinRealtimeService.php:203](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L203):
    ```php
    'quoc-tin.parcels' => true, // <--- LỖI BYPASS TOÀN PHẦN
    ```
- **Dữ liệu phát tán qua SSE**:
  - Khi bưu phẩm được tiếp nhận ([ReceptionParcelService.php:380-389](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L380-L389)):
    - `tracking_number` (Mã vận đơn)
    - `recipient_name` (Tên người nhận)
    - `recipient_phone` (Số điện thoại người nhận)
    - `apartment_number` (Số căn hộ)
    - `courier_company` (Đơn vị vận chuyển: Shopee, SPX, GHN...)
    - `parcel_photo_url` (Ảnh chụp gói hàng)
  - Khi bưu phẩm được check-out/bàn giao ([ReceptionParcelService.php:960-970](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/ReceptionParcelService.php#L960-L970)):
    - `tracking_number`, `recipient_name`, `collected_by_name`, `apartment_number`, `checkout_staff_name`
- **Mã PIN/QR có bị lộ không?**:
  - Payload sự kiện SSE **không** chứa `pickup_pin_code` hay `pickup_qr_code`.
  - Tuy nhiên, thông tin người nhận, SĐT, số phòng, ảnh gói hàng và lịch sử nhận hàng của mọi căn hộ đều bị phát tán công khai trên stream SSE.
- **Phân quyền chuẩn**:
  - Nhân viên lễ tân/bảo vệ/quản lý: Xem toàn bộ bưu phẩm để phục vụ vận hành quầy.
  - Cư dân: **CHỈ** nhận sự kiện bưu phẩm gửi tới căn hộ của mình (`apartment_id` thuộc danh sách căn hộ của cư dân).

---

### 4.4. Kênh Lịch trực nhân viên (`quoc-tin.schedules` / `quoc-tin.staff_schedules`)
- **Vị trí lỗi**:
  - [app/Services/QuocTinRealtimeService.php:205](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L205):
    ```php
    'quoc-tin.schedules', 'quoc-tin.staff_schedules' => true, // <--- LỖI BYPASS TOÀN PHẦN
    ```
- **Dữ liệu phát tán qua SSE**:
  - Khi ca trực được tạo/sửa ([StaffScheduleService.php:433-437](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/StaffScheduleService.php#L433-L437)):
    - `'schedule' => $schedule->toArray()` chứa toàn bộ quan hệ:
      - `staffProfile.user`: Họ tên, username, email, số điện thoại của nhân viên.
      - `staffProfile.department`: Phòng ban.
      - `shiftType`: Giờ vào ca, giờ ra ca, thời gian nghỉ, ca đêm.
- **Nguy cơ**: Cư dân hoặc bất kỳ ai có tài khoản đều xem được thông tin cá nhân và thời gian trực ca của toàn bộ nhân viên bảo vệ và lễ tân.
- **Phân quyền chuẩn**:
  - Chỉ nhân viên và quản lý tòa nhà (`SUPER_ADMIN`, `ADMIN`, `BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD` hoặc có permission `SCHEDULE:VIEW`).
  - Cư dân **tuyệt đối không có quyền** truy cập kênh lịch trực nhân viên.

---

## 5. CƠ CHẾ CÔ LẬP DỮ LIỆU SỰ KIỆN (EVENT DATA ISOLATION)

### 5.1. Khiếm khuyết tại `QuocTinRealtimeService::getEvents()`
Hiện tại:
```php
public static function getEvents(array $channels, int $sinceId = 0, int $sinceMs = 0, int $limit = 50): array
{
    $query = DB::table('realtime_sync_events')->whereIn('channel', $channels);
    ...
}
```
Hàm này hoàn toàn không nhận thông tin `$user`. Dù người gọi là Cư dân hay Nhân viên, câu query vẫn trả về toàn bộ sự kiện của kênh.

### 5.2. Giải pháp Cô lập Dữ liệu Server-Side
Để đảm bảo an toàn tuyệt đối mà không phá vỡ cấu trúc frontend hiện có:
1. **Đối với Nhân viên (`isStaff($user) === true`)**:
   - Được phép nhận toàn bộ sự kiện của kênh được cấp quyền.
2. **Đối với Cư dân (`isStaff($user) === false`)**:
   - Bị cấm hoàn toàn (`Forbidden 403`) đối với kênh: `quoc-tin.rfid-cards`, `quoc-tin.schedules`, `quoc-tin.rbac`, `quoc-tin.account-provisioning`.
   - Đối với kênh `quoc-tin.parcels`: Khi duyệt qua các sự kiện lấy từ database, server sẽ kiểm tra trường `apartment_id` (hoặc `entity_id` tra cứu bưu phẩm) trong payload. Chỉ gửi sự kiện nếu bưu phẩm đó thuộc căn hộ của cư dân (`in_array($ev['apartment_id'], $residentAptIds)`).
   - Đối với kênh `quoc-tin.visitors`: Chỉ gửi sự kiện nếu `apartment_id` thuộc căn hộ của cư dân hoặc `host_resident_user_id === $user->id`.

---

## 6. PHÂN TÍCH PHÍA FRONTEND (FRONTEND SSE USAGE)

1. **Khởi tạo kết nối**:
   - `useRealtimeSync.ts` sử dụng `fetch()` với header `Authorization: Bearer <token>`.
   - Kết nối mở stream theo chu kỳ ngắn (~2.5s) rồi đóng sau sự kiện `cycle` và hẹn giờ reconnect sau 3000ms. Điều này giúp giải phóng PHP-FPM workers và chống cạn kiệt tài nguyên máy chủ.
2. **Xử lý khi bị từ chối 403/401**:
   - Nếu backend trả về HTTP 403 (khi cư dân cố tình request kênh không có quyền), hook `fetch()` sẽ nhận mã lỗi và tự động áp dụng exponential backoff (tối đa 30s) mà không làm sập ứng dụng.
3. **Các Component đang sử dụng SSE**:
   - `ResidentParcelWidget.tsx`: Đăng ký `quoc-tin.parcels`. Khi có event chỉ gọi lại `fetchResidentParcels()` để load dữ liệu của riêng mình. (Không dựa vào payload SSE để render trực tiếp).
   - `ResidentVisitorRegistrationPanel.tsx`: Đăng ký `quoc-tin.visitors`. Khi có event chỉ gọi lại `fetchVisitors()`.
   - `ReceptionParcelPanel.tsx`: Đăng ký `quoc-tin.parcels`.
   - `ReceptionVisitorCheckinPanel.tsx`: Đăng ký `quoc-tin.visitors`.
   - `ManagementStaffSchedulePanel.tsx`: Đăng ký `quoc-tin.schedules`.
   - `RfidManagement.tsx`: Đăng ký `quoc-tin.rfid-cards`.
   - `ReceptionDashboardPanel.tsx`: Đăng ký gộp `['quoc-tin.visitors', 'quoc-tin.parcels', 'quoc-tin.schedules']`.

**Đánh giá an toàn**: Do các component Resident chủ yếu dùng SSE làm tín hiệu kích hoạt (signal) để refresh REST API (đã được bảo mật bởi BUG-01), việc lọc dữ liệu ở backend sẽ ngăn chặn hoàn toàn rò rỉ dữ liệu qua Network tab mà không làm ảnh hưởng đến UX của cư dân.

---

## 7. BẢNG TỔNG HỢP LỖI XÁC NHẬN (CONFIRMED BUGS & EVIDENCE)

| Mã lỗi | File & Dòng | Phương thức / Vị trí | Đoạn code chứng minh | Trạng thái | Mức độ | Hậu quả |
|---|---|---|---|---|---|---|
| **BUG-05-A** | [QuocTinRealtimeService.php:197-199](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L197-L199) | `isUserAuthorizedForChannel()` | `... \|\| true,` | **CONFIRMED** | **CRITICAL** | Mọi user đều subscribe được kênh RFID tòa nhà |
| **BUG-05-B** | [routes/channels.php:101](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/channels.php#L101) | `Broadcast::channel('quoc-tin.rfid-cards')` | `... \|\| true;` | **CONFIRMED** | **CRITICAL** | Bypass quyền kênh RFID trên Laravel Broadcast |
| **BUG-05-C** | [QuocTinRealtimeService.php:201](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L201) | `isUserAuthorizedForChannel()` | `'quoc-tin.visitors' => true,` | **CONFIRMED** | **CRITICAL** | Mọi user xem được thông tin khách của căn hộ khác |
| **BUG-05-D** | [QuocTinRealtimeService.php:203](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L203) | `isUserAuthorizedForChannel()` | `'quoc-tin.parcels' => true,` | **CONFIRMED** | **CRITICAL** | Mọi user xem được bưu phẩm, SĐT, tên người nhận toàn tòa nhà |
| **BUG-05-E** | [QuocTinRealtimeService.php:205](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php#L205) | `isUserAuthorizedForChannel()` | `'quoc-tin.schedules' => true,` | **CONFIRMED** | **HIGH** | Mọi user xem được SĐT, email, ca trực nhân viên |
| **BUG-05-F** | [RealtimeStreamController.php:115,139](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/RealtimeStreamController.php#L115) | `stream()` & `events()` | Không truyền `$user` vào `getEvents()`, không lọc theo căn hộ | **CONFIRMED** | **CRITICAL** | Dữ liệu sự kiện phát tán toàn cục, không cô lập theo căn hộ |
| **BUG-05-G** | [RealtimeStreamController.php:211](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/RealtimeStreamController.php#L211) | `cooldown()` | Thiếu kiểm tra `$user = RbacService::resolveUser($request)` | **CONFIRMED** | **MEDIUM** | Người chưa đăng nhập xem được `actor_id` nhân viên |

---

## 8. ĐÁNH GIÁ TƯƠNG THÍCH VỚI BUG-01

- **Tính bảo vệ**:
  - BUG-01 đã hoàn tất việc bảo vệ các REST API bưu phẩm (`GET /api/v1/reception/parcels`, `show`, `store`, `update`, `destroy`, `lookup`).
  - Khắc phục BUG-05 sẽ hoàn thiện vòng bảo mật thứ hai: bảo vệ dữ liệu bưu phẩm truyền tải qua giao thức Realtime SSE.
  - Sẽ tái sử dụng trực tiếp các phương thức đã kiểm chứng trong `ReceptionParcelService`:
    - `ReceptionParcelService::isStaff(?object $user): bool`
    - `ReceptionParcelService::resolveResidentApartmentIds(object $user): array`
- **Cam kết**:
  - Không thay đổi bất kỳ logic nào trong các API bưu phẩm đã sửa ở BUG-01.
  - Không khôi phục cơ chế fallback nhân viên.
  - Không sửa lại các file của BUG-01 ngoại trừ việc gọi helper xác thực nếu cần.

---

## 9. KẾ HOẠCH KHẮC PHỤC CHI TIẾT (PROPOSED FIX PLAN)

Kế hoạch được chia thành 4 bước thực hiện tuần tự và tối thiểu:

### Bước 1: Khắc phục triệt để các biểu thức Bypass trong Phân quyền Kênh
- **File 1**: [app/Services/QuocTinRealtimeService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php)
  - Sửa `isUserAuthorizedForChannel(?object $user, string $channel)`:
    - Loại bỏ `|| true` ở kênh `quoc-tin.rfid-cards`. Chỉ cho phép các vai trò: `SUPER_ADMIN`, `ADMIN`, `BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD` hoặc có permission `RFID:VIEW`.
    - Kênh `quoc-tin.schedules`: Chỉ cho phép nhân viên vận hành (`SUPER_ADMIN`, `ADMIN`, `BUILDING_MANAGER`, `RECEPTIONIST`, `SECURITY_GUARD` hoặc permission `SCHEDULE:VIEW`). Cư dân bị từ chối (`false`).
    - Kênh `quoc-tin.visitors`:
      - Nhân viên vận hành (`isStaff`): Cho phép (`true`).
      - Cư dân: Cho phép đăng ký kênh (`true`) NHƯNG dữ liệu sự kiện phải qua bộ lọc căn hộ ở Bước 2.
    - Kênh `quoc-tin.parcels`:
      - Nhân viên vận hành (`isStaff`): Cho phép (`true`).
      - Cư dân: Cho phép đăng ký kênh (`true`) NHƯNG dữ liệu sự kiện phải qua bộ lọc căn hộ ở Bước 2.
- **File 2**: [routes/channels.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/channels.php)
  - Loại bỏ `|| true` ở kênh `quoc-tin.rfid-cards` (dòng 101).
  - Khai báo thêm channel callbacks chuẩn cho `quoc-tin.visitors`, `quoc-tin.parcels`, `quoc-tin.schedules`.

### Bước 2: Triển khai Bộ lọc Dữ liệu Sự kiện (Server-Side Event Scoping)
- **File 1**: [app/Services/QuocTinRealtimeService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php)
  - Cập nhật `getEvents(array $channels, int $sinceId = 0, int $sinceMs = 0, int $limit = 50, ?object $user = null)`:
    - Nếu `$user` là nhân viên (`isStaff`): Trả về toàn bộ sự kiện của kênh.
    - Nếu `$user` là cư dân:
      - Tự động lấy danh sách căn hộ của cư dân `$residentAptIds`.
      - Với sự kiện kênh `quoc-tin.parcels`: Chỉ trả về event nếu `payload['apartment_id']` nằm trong `$residentAptIds` hoặc `payload['recipient_user_id'] === $user->id`.
      - Với sự kiện kênh `quoc-tin.visitors`: Chỉ trả về event nếu `payload['apartment_id']` nằm trong `$residentAptIds` hoặc `payload['host_resident_user_id'] === $user->id`.
      - Với các sự kiện hệ thống khác: Lọc bỏ thông tin nhạy cảm.
- **File 2**: [app/Http/Controllers/RealtimeStreamController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/RealtimeStreamController.php)
  - Truyền `$user` vào lời gọi `QuocTinRealtimeService::getEvents(..., $user)` ở cả `stream()` và `events()`.
  - Bổ sung xác thực `$user = RbacService::resolveUser($request)` vào hàm `cooldown()` để ngăn lộ `actor_id` cho người dùng nặc danh.

### Bước 3: Gắn Middleware bảo vệ Route SSE
- **File**: [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php)
  - Bọc cụm route `/api/realtime/*` và `/api/v1/realtime/*` vào `middleware('auth.bearer')` để đồng bộ cơ chế bảo vệ với toàn bộ hệ thống API.

### Bước 4: Viết Test tự động bảo mật cho Realtime SSE
- **File**: [tests/Feature/RealtimeSyncTest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/RealtimeSyncTest.php)
  - Bổ sung các test case:
    1. Cư dân bị từ chối 403 khi kết nối kênh `quoc-tin.rfid-cards`.
    2. Cư dân bị từ chối 403 khi kết nối kênh `quoc-tin.schedules`.
    3. Cư dân căn hộ A kết nối `quoc-tin.parcels` KHÔNG nhận được sự kiện bưu phẩm của căn hộ B.
    4. Cư dân căn hộ A kết nối `quoc-tin.visitors` KHÔNG nhận được sự kiện khách của căn hộ B.
    5. Nhân viên lễ tân kết nối `quoc-tin.parcels` nhận được sự kiện bưu phẩm toàn tòa nhà.
    6. Request chưa đăng nhập vào `/api/realtime/stream` và `/api/realtime/cooldown` bị từ chối 401.

---

## 10. DANH SÁCH FILE DỰ KIẾN THAY ĐỔI

Chỉ thay đổi tối thiểu **3 file code chính** và **1 file test**:

1. [app/Services/QuocTinRealtimeService.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Services/QuocTinRealtimeService.php) — Sửa quyền kênh & thêm bộ lọc sự kiện theo căn hộ.
2. [routes/channels.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/channels.php) — Xóa `|| true` & chuẩn hóa các channel callback.
3. [app/Http/Controllers/RealtimeStreamController.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/RealtimeStreamController.php) — Truyền `$user` vào `getEvents()`, xác thực `cooldown()`.
4. [routes/web.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/routes/web.php) — Gắn `auth.bearer` cho cụm route SSE.
5. [tests/Feature/RealtimeSyncTest.php](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/RealtimeSyncTest.php) — Thêm các bài test bảo mật SSE.

**Tuyệt đối không sửa**:
- Không sửa file frontend (`useRealtimeSync.ts`, các panel UI).
- Không sửa controller đăng nhập, session, token.
- Không sửa schema database.

---

## 11. KẾ HOẠCH KIỂM THỬ HỒI QUY (REGRESSION TEST PLAN)

Sau khi triển khai, bắt buộc chạy lại toàn bộ các test suite liên quan:
1. `tests/Feature/RealtimeSyncTest.php` (Đảm bảo các test cũ 1–12 vẫn PASS và các test mới PASS).
2. `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` (Đảm bảo 22/22 test của BUG-01 vẫn giữ 100% PASS).
3. `tests/Feature/ReceptionParcel/ReceptionParcelCheckoutTest.php` (Đảm bảo 11/11 test check-out vẫn PASS).
4. `tests/Feature/RfidManagement/RfidManagementTest.php` (Đảm bảo luồng RFID vẫn hoạt động).
5. `npm run typecheck` (Kiểm tra tương thích frontend).

---

## 12. KẾT LUẬN & ĐỀ NGHỊ PHÊ DUYỆT

Giai đoạn phân tích BUG-05 đã hoàn thành đầy đủ, xác định chính xác từng vị trí dòng code gây ra lỗ hổng bảo mật và lập phương án khắc phục an toàn, tối thiểu, không gây hồi quy.

Theo đúng nguyên tắc, tôi **dừng lại ở đây và không tự ý chỉnh sửa bất kỳ dòng code nào**. Xin vui lòng xem xét và cho biết ý kiến phê duyệt kế hoạch để bắt đầu triển khai khắc phục BUG-05!

