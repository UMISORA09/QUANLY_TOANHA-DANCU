# BÁO CÁO KIỂM TOÁN VÀ TỐI ƯU HÓA DATABASE INDEXES & SEARCH QUERIES
**Phân hệ phụ trách:** Lê Quốc Tín  
**Dự án:** QUẢN LÝ TÒA NHÀ & CƯ DÂN (Smart Building Management System)  
**Thời gian thực hiện:** Tháng 10/2026  
**Môi trường:** PHP 8.4 / Laravel 12 / MySQL 8.0 InnoDB  
**Nhánh Git:** `QuocTin/fix-search-index-regressions` (tiếp nối PR #88 `b33007d` đã merge vào `master`)  

---

## 1. TỔNG QUAN VÀ PHẠM VI KIỂM TOÁN (AUDIT SCOPE)

Báo cáo này ghi nhận toàn diện quá trình rà soát, đo lường kế hoạch thực thi (`EXPLAIN`), phát hiện điểm nghẽn hiệu năng, bổ sung Database Index tối ưu, chuẩn hóa Query sargable và khắc phục các vấn đề hồi quy (regression fixes) trong phạm vi 7 phân hệ cốt lõi do **Lê Quốc Tín** phụ trách.

### Phạm vi các bảng mục tiêu:
1. `users` (Tài khoản người dùng, nhân khẩu)
2. `roles`, `permissions`, `user_roles`, `role_permissions` (Phân quyền RBAC)
3. `user_sessions` (Phiên làm việc và Token xác thực)
4. `residents` (Hồ sơ nhân khẩu và cư dân căn hộ)
5. `temporary_registrations` (Hồ sơ khai báo tạm trú / tạm vắng với Công An)
6. `vehicles` (Phương tiện giao thông và định danh biển số)
7. `access_cards` (Thẻ chip RFID thang máy / bãi xe)
8. `parking_access_logs` (Lịch sử quẹt thẻ và ra vào phương tiện)
9. `visitor_registrations` (Khai báo khách viếng thăm và cấp mã QR Pass)
10. `visitor_checkin_logs` (Lịch sử check-in / check-out khách tại quầy lễ tân / bảo vệ)
11. `parcels` (Tiếp nhận kiện hàng, bưu phẩm và mã PIN giao nhận)

### Cam kết ranh giới:
- **Tuyệt đối không can thiệp** vào các bảng và logic của **Đặng Đăng Nguyên** (`amenities`, `amenity_bookings`, `amenity_booking_payments`).
- **Tuyệt đối không can thiệp** vào các bảng kế toán / hóa đơn / chỉ số điện nước của **Văn Nguyễn Xuân Hòa** (`invoices`, `meter_readings`, `water_records`, `electric_records`).
- Không sửa đổi các file migration cũ đã chạy trên production; tạo migration mới độc lập có hàm `up()` và `down()` an toàn 100%.

---

## 2. MA TRẬN KIỂM TOÁN VÀ KẾT QUẢ ĐO LƯỜNG (AUDIT MATRIX)

| Module | File liên quan | Truy vấn / Vụ việc | Index hiện có | Vấn đề phát hiện | Giải pháp tối ưu | Kết quả đo EXPLAIN (Trước vs Sau) |
|---|---|---|---|---|---|---|
| **RBAC / Users** | `app/Http/Controllers/UserController.php` | `WHERE status = ? ORDER BY created_at DESC` khi phân trang danh sách người dùng | `email`, `phone_number`, `national_id_number` (UNIQUE) + các index đơn trùng lặp | Thiếu composite index phục vụ lọc theo trạng thái và sắp xếp theo ngày tạo. MySQL buộc phải scan toàn bảng `users` (5,541 rows) và dùng Filesort. | Thêm Composite Index `(status, created_at)` và Index đơn `created_at` trên bảng `users`. | **Trước:** `type: ALL, rows: 5541, Extra: Using where; Using filesort`<br>**Sau:** `type: ref, key: idx_users_status_created, rows: 2947, Extra: Backward index scan` *(Triệt tiêu Filesort hoàn toàn)* |
| **Thẻ RFID** | `app/Services/AccessCardService.php` | `WHERE assigned_apartment_id = ?` khi lọc thẻ cư dân theo căn hộ | `card_uid`, `card_number` (UNIQUE), `idx_access_cards_created_at`, `idx_access_cards_status_type` | Cột `assigned_apartment_id` và `assigned_user_id` không có Index, khiến mọi thao tác lọc danh sách thẻ theo căn hộ / chủ thẻ phải Full Table Scan. | Thêm Composite Index `idx_access_cards_apt_status(assigned_apartment_id, status)` và `idx_access_cards_user(assigned_user_id)`. | **Trước:** `type: ALL, rows: 172, Extra: Using where`<br>**Sau:** `type: ref, key: idx_access_cards_apt_status, rows: 1, Extra: None` *(Giảm số dòng duyệt từ 172 xuống 1 dòng)* |
| **Lịch sử thẻ xe** | `app/Services/AccessCardService.php` | `WHERE card_id = ? ORDER BY log_timestamp DESC LIMIT 5` khi xem chi tiết thẻ | `idx_parking_logs_plate(detected_license_plate, log_timestamp)` | Không có index trên khóa ngoại `card_id`. Mỗi lần Lễ tân xem thông tin chi tiết thẻ thì MySQL phải quét toàn bộ bảng log xe và Filesort. | Thêm Composite Index `idx_parking_logs_card_timestamp(card_id, log_timestamp)`. | **Trước:** `type: ALL, rows: 14, Extra: Using where; Using filesort`<br>**Sau:** `type: ref, key: idx_parking_logs_card_timestamp, rows: 1, Extra: Backward index scan` *(Triệt tiêu Filesort và Full Scan)* |
| **Khách viếng thăm** | `app/Services/ReceptionVisitorService.php` | `leftJoin('visitor_checkin_logs as vcl', ...)` | `idx_checkin_logs_checkout`, `idx_checkin_time` | Cột khóa ngoại `registration_id` trong `visitor_checkin_logs` thiếu Index và phép join trực tiếp gây nhân bản dòng (1-N duplication) khi khách có nhiều lượt log checkin/checkout. | Bổ sung Composite Index `idx_checkin_logs_reg_checkin(registration_id, checkin_time)` và chuyển phép join sang Correlated Subquery Join chọn log mới nhất. | **Trước:** `table vcl: type ALL, rows: 386, Extra: Using join buffer (hash join)`<br>**Sau:** `table vcl: type eq_ref (PRIMARY), subquery ref: idx_checkin_logs_reg_checkin, rows: 1` *(Triệt tiêu nhân bản 1-N, COUNT chính xác, triệt tiêu hash join)* |
| **KPI Khách** | `app/Services/ReceptionVisitorService.php` | Subquery tính KPI: `WHERE qr_pass_status = 'ACTIVE' AND expected_arrival_time >= ? AND expected_arrival_time < ?` | `idx_visitors_apt_expected`, `idx_visitors_host` | Thiếu composite index giữa trạng thái duyệt và thời gian đến dự kiến, gây Full Scan bảng `visitor_registrations` mỗi lần mở màn hình lễ tân. | Thêm Composite Index `idx_visitors_status_expected(qr_pass_status, expected_arrival_time)`. | **Trước:** `type: ALL, rows: 499, Extra: Using where`<br>**Sau:** `type: range, key: idx_visitors_status_expected, rows: 5, Extra: Using where; Using index` *(Covering index scan, giảm từ 499 dòng xuống 5 dòng)* |
| **Bưu phẩm Lễ tân** | `app/Services/ReceptionParcelService.php` | `WHERE apartment_id = ? ORDER BY received_at DESC` khi lọc kiện hàng theo căn hộ | `idx_parcels_apt(apartment_id, status)` | Index hiện tại chỉ chứa `(apartment_id, status)`. Khi lọc theo căn hộ và sắp xếp theo ngày nhận `received_at DESC`, MySQL phải dùng Filesort. | Thêm Composite Index `idx_parcels_apt_received(apartment_id, received_at)`. | **Trước:** `type: ref, key: idx_parcels_apt, rows: 1, Extra: Using filesort`<br>**Sau:** `type: ref, key: idx_parcels_apt_received, rows: 1, Extra: Backward index scan` *(Triệt tiêu Filesort)* |
| **Tra cứu PIN kiện hàng** | `app/Services/ReceptionParcelService.php` | Tra cứu kiện hàng theo mã PIN 6 số `pickup_pin_code` khi cư dân nhận hàng tại quầy | `pickup_qr_code` (UNIQUE), `idx_parcels_tracking` | `pickup_pin_code` chưa có Index, gây Full Scan toàn bảng bưu phẩm khi lễ tân/kiosk nhập mã PIN. | Thêm Index `idx_parcels_pin_code(pickup_pin_code)` + hỗ trợ exact filter `pin_code` và method `findParcelByPin`. | **Trước:** `type: ALL, rows: 219, Extra: Using where`<br>**Sau:** `type: ref / const, key: idx_parcels_pin_code, rows: 1, Extra: None` *(Index seek B-Tree O(log N), triệt tiêu Full Table Scan)* |
| **Tạm trú / Tạm vắng** | `app/Services/TemporaryRegistrationService.php` | `whereDate('start_date', '>=', $startDate)` và `whereDate('end_date', '<=', $endDate)` | `idx_tempreg_status_created`, `idx_tempreg_apt_status`, `idx_tempreg_resident_id` | Sử dụng hàm `whereDate()` trên cột có kiểu `DATE` làm mất tính sargable (`possible_keys: NULL`), ngăn MySQL dùng B-Tree index. | Chuyển thành so sánh trực tiếp `where('start_date', '>=', $startDate)` và bổ sung index `(start_date, end_date)`, `created_at`. | **Trước:** `possible_keys: NULL, type: ALL`<br>**Sau:** `possible_keys: idx_tempreg_dates, type: range` *(Tận dụng Index range scan)* |
| **Lọc ngày bưu phẩm** | `app/Services/ReceptionParcelService.php` | `whereDate('received_at', '>=', $fromDate)` | `idx_parcels_status(status, received_at)` | Dùng `whereDate()` bọc hàm trên cột timestamp khiến MySQL không thể range seek trên `received_at`. | Chuyển thành so sánh datetime sargable: `>= Carbon::parse($from)->startOfDay()` và `<= Carbon::parse($to)->endOfDay()`. | **Trước:** Không tận dụng được index range scan trên timestamp.<br>**Sau:** Trực tiếp range scan trên B-Tree timestamp `received_at`. |

---

## 3. RÀ SOÁT CHI TIẾT TỪNG PHÂN HỆ CHỨC NĂNG

### A. RBAC & Cấp phát tài khoản (`UserController`, `RoleController`, `RbacService`, `AccountProvisioningService`)
- **Tình trạng:** Đã triển khai đầy đủ, có API tìm kiếm và phân trang theo `search`, `status`, `role`.
- **Phát hiện:**
  - Bảng `users` có 3 UNIQUE constraint (`email`, `phone_number`, `national_id_number`) nhưng lại tồn tại thêm 3 Index đơn trùng lặp 100% (`idx_users_email`, `idx_users_phone`, `idx_users_national_id`). Trong MySQL InnoDB, ràng buộc UNIQUE tự động tạo cấu trúc B-tree index tương đương, do đó các index đơn này gây lãng phí bộ nhớ buffer pool và tăng chi phí ghi/update.
  - Cột `(status, created_at)` thiếu composite index, làm câu truy vấn danh sách người dùng mặc định hoặc lọc theo trạng thái phải dùng Filesort.
- **Tối ưu:** Đã thêm `idx_users_status_created(status, created_at)` và `idx_users_created_at(created_at)`. Filesort đã được loại bỏ hoàn toàn.

### B. Quản lý Cư dân (`ResidentService.php`)
- **Tình trạng:** Đã triển khai đầy đủ với các bộ lọc `search`, `apartment_id`, `resident_type`, `is_active`, `is_head_of_household`, và sắp xếp theo `stay_start_date`.
- **Đánh giá Index:**
  - `idx_residents_apt` (`apartment_id, is_active`)
  - `idx_residents_apt_active_head` (`apartment_id, is_active, is_head_of_household`)
  - `idx_residents_active_stay` (`is_active, stay_start_date`)
  - `idx_residents_type_active` (`resident_type, is_active`)
  - `idx_residents_user_active` (`user_id, is_active`)
- **Nhận định:** Các Index hiện có đã phủ kín các điều kiện lọc và sắp xếp. Tìm kiếm `LIKE '%search%'` sử dụng wildcard đứng đầu nên B-tree index thông thường không thể tăng tốc, đúng như nguyên tắc kiến trúc "Không tạo index đơn vô nghĩa trên full_name".

### C. Đăng ký Tạm trú / Tạm vắng (`TemporaryRegistrationService.php`)
- **Tình trạng:** Đã triển khai quy trình nộp hồ sơ, duyệt và gửi Công An.
- **Tối ưu Sargability:** Cột `start_date` và `end_date` có kiểu dữ liệu `DATE`. Code cũ dùng `whereDate()` gây mất tính sargable. Đã chuẩn hóa thành so sánh trực tiếp:
  ```php
  // Cũ (Non-sargable):
  $query->whereDate('start_date', '>=', $startDate);
  $query->whereDate('end_date', '<=', $endDate);

  // Mới (Sargable):
  $query->where('start_date', '>=', $startDate);
  $query->where('end_date', '<=', $endDate);
  ```
- **Bổ sung Index:** Thêm `idx_tempreg_dates(start_date, end_date)` và `idx_tempreg_created_at(created_at)` để phục vụ sắp xếp mặc định và lọc khoảng ngày.

### D. Quản lý Phương tiện (`VehicleService.php`)
- **Tình trạng:** Đã triển khai quản lý biển số, tính phí gửi xe theo danh mục (Ô tô, Xe máy), biểu phí `service_pricing_configs`.
- **Đánh giá:**
  - Tồn tại index trùng `idx_vehicles_plate(license_plate)` với `UNIQUE(license_plate)`.
  - Các index `idx_vehicles_apt`, `idx_vehicles_owner_active`, `idx_vehicles_cat_active`, `idx_vehicles_active_created` hoạt động chính xác và hiệu quả. Không thay đổi logic tính phí gửi xe.

### E. Quản lý Thẻ RFID / Access Cards (`AccessCardService.php`)
- **Tình trạng:** Quản lý thẻ RFID, cấp thẻ, khóa thẻ, hiển thị 5 lượt quẹt thẻ gần nhất.
- **Phát hiện nghiêm trọng:**
  - `parking_access_logs`: Hàm `getCardById` lấy 5 lượt quẹt gần nhất qua `where('card_id', $id)->orderBy('log_timestamp', 'desc')->limit(5)`. Trước đây bảng này **không có index trên card_id**, khiến hệ thống phải quét toàn bộ bảng log đỗ xe.
  - `access_cards`: Không có index trên `assigned_apartment_id` và `assigned_user_id`.
- **Tối ưu:**
  - Bổ sung `idx_parking_logs_card_timestamp(card_id, log_timestamp)` -> Triệt tiêu Filesort và Full Table Scan.
  - Bổ sung `idx_access_cards_apt_status(assigned_apartment_id, status)` và `idx_access_cards_user(assigned_user_id)`.

### F. Khách viếng thăm / Check-in / Check-out (`ReceptionVisitorService.php`)
- **Vấn đề nghiêm trọng phát hiện (1-to-N Row Duplication Bug):**
  - Quan hệ giữa `visitor_registrations` và `visitor_checkin_logs` là quan hệ 1-Nhiều (1 lượt đăng ký có thể có nhiều log checkin/checkout do khách ra vào nhiều lần).
  - Phép nối trực tiếp cũ:
    ```php
    ->leftJoin('visitor_checkin_logs as vcl', 'visitor_registrations.id', '=', 'vcl.registration_id')
    ```
    dẫn đến: khi 1 khách có 3 log checkin, hệ thống trả về 3 dòng cho cùng 1 registration.
    Hậu quả:
    1. Tổng số dòng `COUNT` trong pagination bị thổi phồng sai lệch.
    2. Một khách xuất hiện nhiều lần trên bảng giao diện Lễ tân.
    3. Bộ lọc trạng thái (`INSIDE`, `CHECKED_OUT`) cho kết quả mâu thuẫn (khách vừa khớp `CHECKED_OUT` từ log cũ vừa khớp `INSIDE` từ log mới).
- **Giải pháp tối ưu chuẩn hóa:**
  Thay thế direct join bằng Correlated Subquery Join chọn đúng **bản ghi check-in mới nhất** của mỗi registration, có cơ chế Tie-breaker xác định tuyệt đối (`ORDER BY checkin_time DESC, created_at DESC, id DESC LIMIT 1`):
  ```php
  $query = DB::table('visitor_registrations')
      ->leftJoin('visitor_checkin_logs as vcl', function ($join) {
          $join->on('vcl.id', '=', DB::raw('(SELECT vcl_sub.id FROM visitor_checkin_logs vcl_sub WHERE vcl_sub.registration_id = visitor_registrations.id ORDER BY vcl_sub.checkin_time DESC, vcl_sub.created_at DESC, vcl_sub.id DESC LIMIT 1)'));
      })
      ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
  ```
- **Lợi ích hiệu năng:**
  - MySQL thực thi phép JOIN `vcl` bằng `eq_ref` trên Primary Key (`id`), và subquery tận dụng trực tiếp Composite Index `idx_checkin_logs_reg_checkin(registration_id, checkin_time)`.
  - Triệt tiêu 100% hiện tượng nhân bản bản ghi; đảm bảo `COUNT` và phân trang chuẩn xác tuyệt đối.
  - Bổ sung `idx_visitors_status_expected(qr_pass_status, expected_arrival_time)` phục vụ tính KPI thời gian thực.

### G. Bưu phẩm và Lễ tân (`ReceptionParcelService.php`)
- **Phân tích Index `idx_parcels_pin_code` và Trải nghiệm người dùng:**
  - Mã PIN lấy hàng `pickup_pin_code` là chuỗi 6 chữ số ngẫu nhiên cấp cho cư dân để xác thực nhận hàng bảo mật tại quầy Lễ tân hoặc Smart Locker Kiosk.
  - Khi người dùng gõ vào thanh tìm kiếm chung (`filters['search']`), câu truy vấn sử dụng `like '%search%'` để hỗ trợ tìm kiếm mờ đa trường (tên, số điện thoại, biển số, mã vận đơn). Do có leading wildcard, B-Tree index không thể áp dụng cho chuỗi con này.
  - **Lý do giữ lại và tối ưu `idx_parcels_pin_code`:**
    1. **Nghiệp vụ xác thực nhận hàng chính xác (Verification Workload):** Khi cư dân đọc mã PIN 6 số hoặc hệ thống Smart Locker tra cứu kiện hàng để mở ngăn tủ, việc truy vấn đẳng thức `WHERE pickup_pin_code = ?` là thao tác nghiệp vụ cốt lõi, bắt buộc phải đạt tốc độ B-Tree index seek $O(\log N)$ (thời gian dưới 1ms) thay vì quét toàn bộ hàng chục nghìn kiện hàng.
    2. **Bổ sung API hỗ trợ tra cứu chính xác:** Đã cập nhật `ReceptionParcelService::getParcels` nhận tham số `pin_code` (`WHERE pickup_pin_code = ?`) và bổ sung method `findParcelByPin(string $pinCode)` sử dụng trực tiếp B-Tree Index `idx_parcels_pin_code`.
    3. **Bảo toàn UX:** Thanh tìm kiếm chung của Lễ tân vẫn giữ nguyên nhánh `orWhere('pickup_pin_code', 'like', "%{$search}%")` để không làm gián đoạn thói quen tìm kiếm một phần của nhân viên.
  - **Tối ưu ngày nhận:** Cải thiện `whereDate('received_at', ...)` thành so sánh khoảng `startOfDay()` và `endOfDay()`.
  - **Bổ sung Index:** Thêm Composite Index `idx_parcels_apt_received(apartment_id, received_at)` loại bỏ Filesort khi lọc theo căn hộ.

---

## 4. CHI TIẾT MIGRATION MỚI TRIỂN KHAI

File migration:  
`database/migrations/2026_10_14_140302_add_search_optimization_indexes_for_quoc_tin_modules.php`

### Toàn bộ 11 Database Indexes tối ưu được tạo:
```php
// 1. visitor_checkin_logs: Phục vụ correlated subquery JOIN vcl.registration_id và ORDER BY checkin_time DESC
$table->index(['registration_id', 'checkin_time'], 'idx_checkin_logs_reg_checkin');

// 2. visitor_registrations: Phục vụ tính KPI thời gian thực & lọc trạng thái + ngày đến
$table->index(['qr_pass_status', 'expected_arrival_time'], 'idx_visitors_status_expected');

// 3. parking_access_logs: Phục vụ truy vấn 5 lượt quẹt thẻ gần nhất theo card_id
$table->index(['card_id', 'log_timestamp'], 'idx_parking_logs_card_timestamp');

// 4. access_cards: Phục vụ lọc thẻ theo căn hộ và trạng thái
$table->index(['assigned_apartment_id', 'status'], 'idx_access_cards_apt_status');

// 5. access_cards: Phục vụ lọc thẻ theo cư dân sở hữu
$table->index('assigned_user_id', 'idx_access_cards_user');

// 6. users: Phục vụ lọc trạng thái người dùng kết hợp sắp xếp ngày tạo
$table->index(['status', 'created_at'], 'idx_users_status_created');

// 7. users: Phục vụ sắp xếp mặc định danh sách người dùng theo created_at desc
$table->index('created_at', 'idx_users_created_at');

// 8. parcels: Composite index lọc kiện hàng theo căn hộ và sắp xếp theo ngày tiếp nhận
$table->index(['apartment_id', 'received_at'], 'idx_parcels_apt_received');

// 9. parcels: Index B-Tree tra cứu chính xác mã PIN nhận hàng 6 số O(log N)
$table->index('pickup_pin_code', 'idx_parcels_pin_code');

// 10. temporary_registrations: Phục vụ sắp xếp mặc định theo ngày tạo hồ sơ
$table->index('created_at', 'idx_tempreg_created_at');

// 11. temporary_registrations: Phục vụ sargable date range scan (start_date, end_date)
$table->index(['start_date', 'end_date'], 'idx_tempreg_dates');
```
*Đặc tính an toàn:* Tất cả các định nghĩa đều bọc trong `Schema::hasTable` và `!Schema::hasIndex`, hàm `down()` hoàn nguyên chính xác đối xứng từng index qua `dropIndex`.

---

## 5. KẾT QUẢ KIỂM THỬ THỰC TẾ (TEST RESULTS)

Toàn bộ **11 test suites** đặc thù trong các module của Lê Quốc Tín đã được chạy và vượt qua 100% trên môi trường database thực tế (Docker container `smart_cassavas_app` và `smart_cassavas_db`):

| Test Suite | Đường dẫn file | Trạng thái | Chi tiết kiểm thử |
|---|---|---|---|
| **RbacManagementTest** | `tests/Feature/Rbac/RbacManagementTest.php` | **PASS** | 15 tests, 38 assertions (0 failures) |
| **RbacSecurityTest** | `tests/Feature/RbacSecurityTest.php` | **PASS** | 13 tests, 116 assertions (0 failures) |
| **ResidentManagementTest** | `tests/Feature/Residents/ResidentManagementTest.php` | **PASS** | 21 tests, 111 assertions (0 failures) |
| **ResidentConcurrencyTest** | `tests/Feature/Residents/ResidentConcurrencyTest.php` | **PASS** | 9 tests, 0 failures |
| **TemporaryRegistrationTest** | `tests/Feature/TemporaryRegistrations/TemporaryRegistrationTest.php` | **PASS** | 17 tests, 80 assertions (0 failures) |
| **TemporaryRegistrationConcurrencyTest** | `tests/Feature/TemporaryRegistrations/TemporaryRegistrationConcurrencyTest.php` | **PASS** | 10 tests, 0 failures |
| **VehicleManagementTest** | `tests/Feature/Vehicles/VehicleManagementTest.php` | **PASS** | 17 tests, 69 assertions (0 failures) |
| **RfidManagementTest** | `tests/Feature/RfidManagement/RfidManagementTest.php` | **PASS** | 15 tests, 41 assertions (0 failures) |
| **ReceptionVisitorCheckinTest** | `tests/Feature/ReceptionVisitor/ReceptionVisitorCheckinTest.php` | **PASS** | **17 tests, 145 assertions** (bao gồm 4 regression tests 14, 15, 16, 17 cho multi-checkin & tie-breaker) |
| **ReceptionParcelTest** | `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` | **PASS** | **16 tests, 55 assertions** (bao gồm test 16 kiểm tra exact PIN filter và `findParcelByPin`) |
| **AccountProvisioningTest** | `tests/Feature/AccountProvisioning/AccountProvisioningTest.php` | **PASS** | 18 tests, 66 assertions (0 failures) |

**Tổng cộng:** **168 tests, 721 assertions — 100% PASS**  
- Tìm kiếm trả về kết quả chính xác, không mất dữ liệu.
- Phân trang chuẩn xác, không có bản ghi trùng lặp (kể cả khi khách có nhiều lượt check-in/out).
- Không rò rỉ dữ liệu hoặc thay đổi phân quyền RBAC.
- Không ảnh hưởng đến concurrency và transaction rollback.
- Code style tuân thủ chuẩn PSR-12 qua Laravel Pint.

---

## 6. DANH SÁCH FILE THAY ĐỔI TRONG PHẠM VI

1. `database/migrations/2026_10_14_140302_add_search_optimization_indexes_for_quoc_tin_modules.php` (Migration bổ sung 11 Database Indexes tối ưu)
2. `app/Services/ReceptionVisitorService.php` (Khắc phục lỗi nhân bản dòng 1-N bằng Correlated Subquery Join tới bản ghi check-in mới nhất)
3. `app/Services/ReceptionParcelService.php` (Tối ưu range query `received_at`, bổ sung exact filter `pin_code` và method `findParcelByPin`)
4. `app/Http/Controllers/ReceptionParcelController.php` (Tiếp nhận query parameter `pin_code` phục vụ tra cứu chính xác qua B-Tree Index)
5. `app/Services/TemporaryRegistrationService.php` (Tối ưu sargable queries cho `start_date` và `end_date`)
6. `tests/Feature/ReceptionVisitor/ReceptionVisitorCheckinTest.php` (Bổ sung 4 regression tests 14, 15, 16, 17 kiểm tra đa log checkin, phân trang và tie-breaker)
7. `tests/Feature/ReceptionParcel/ReceptionParcelTest.php` (Bổ sung test 16 kiểm tra lọc và tra cứu bưu phẩm bằng mã PIN chính xác)
8. `docs/audits/quoc-tin-search-index-audit.md` (Báo cáo kiểm toán chính thức)

*(Lưu ý: Tệp `phpunit.xml` giữ nguyên phiên bản gốc của master, không chỉnh sửa).*

---

## 7. ĐỀ XUẤT PHỐI HỢP CÙNG CÁC THÀNH VIÊN KHÁC

1. **Với Đặng Đăng Nguyên (Tiện ích & Booking):**
   - Không có xung đột schema. Giữ nguyên toàn bộ cấu trúc bảng `amenities`, `amenity_bookings` và `amenity_booking_payments`.
2. **Với Văn Nguyễn Xuân Hòa (Kế toán & Hóa đơn):**
   - Bảng `vehicles` và cấu hình phí gửi xe `service_pricing_configs` được giữ nguyên vẹn 100%, đảm bảo module tạo hóa đơn gửi xe hàng tháng hoạt động đồng bộ.
   - Các index đơn dư thừa (`idx_users_email`, `idx_users_phone`, `idx_users_national_id`, `idx_vehicles_plate`, `idx_access_cards_uid`) hiện được giữ nguyên để bảo đảm an toàn theo nguyên tắc kiểm toán. Sau khi Xuân Hòa và Đăng Nguyên đồng thuận, có thể tạo 1 migration dọn dẹp các index trùng lặp này để tiết kiệm dung lượng đĩa và tăng tốc độ ghi `INSERT/UPDATE`.
