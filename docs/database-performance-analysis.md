# BÁO CÁO PHÂN TÍCH HIỆU NĂNG DATABASE (DATABASE PERFORMANCE AUDIT)
## HỆ THỐNG QUẢN LÝ TÒA NHÀ & KHU DÂN CƯ THÔNG MINH (QUANLY_TOANHA-DANCU)

---

## 1. Current Architecture

### 1.1 Tổng quan kiến trúc hệ thống
* **Backend Framework:** Laravel 12 (PHP 8.4+ / 8.5) theo mô hình MVC kết hợp Service Layer và Action/Controller.
* **Môi trường Database thực tế:**
  * **Primary Engine (Production / Staging / Local Docker):** MySQL 8.0.x (Container `smart_cassavas_db`, port 3306, default collation `utf8mb4_unicode_ci`, InnoDB Engine).
  * **Testing / E2E Engine (CI/CD):** SQLite in-memory / file (`database.sqlite`) thông qua lớp chuyển đổi SQL tương thích `adaptSqlForDriver`.
  * **Thiết kế CSDL gốc (Schema Definition):** Script T-SQL / SQL Server (`database/schema/CSDL_CHUNGCU_DANCU.sql` gồm 109 bảng chuẩn hóa 3NF) được điều phối và nạp tự động qua Migration `2026_09_11_000010_create_chungcu_dancu_database_schema.php`.
* **Caching & In-Memory Layer:** Redis 7.4 Alpine (Container `smart_cassavas_redis`, port 6379, client `phpredis`).
* **Search Engine:** Smart Hybrid Search Manager hỗ trợ MySQL Full-Text Search (B-Tree + Fulltext), Vietnamese Accent-Insensitive Normalizer, và Vector/Knowledge Chunk Search.

```
[ Frontend: React 19 + TypeScript + Vite ]
                   │
                   ▼ (HTTP / REST API + Bearer Token)
[ Backend: Laravel 12 API Gateway / Controllers ]
         │                    │                   │
         ▼                    ▼                   ▼
 [ Service Layer ]    [ RbacService ]   [ SearchManager ]
         │                    │                   │
         ├────────────────────┼───────────────────┤
         ▼                                        ▼
 [ MySQL 8.0 InnoDB ]                    [ Redis 7.4 Cache ]
  - 109 Tables                            - ETag & Query Cache
  - Primary Key: UUID                     - Master Data Cache
  - Foreign Keys Enforced
```

---

## 2. Database Schema Analysis

### 2.1 Thiết kế Khóa chính (Primary Key)
* **Khóa chính toàn hệ thống:** Hầu hết 109 bảng sử dụng `id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID()` (T-SQL) chuyển thành `CHAR(36) PRIMARY KEY DEFAULT (UUID())` trên MySQL 8 và `TEXT PRIMARY KEY` trên SQLite.
* **Đặc tính hiệu năng:**
  * UUID v4 phân bố ngẫu nhiên (random distribution) gây hiện tượng phân mảnh trang chỉ mục (B-Tree Index Page Fragmentation) khi khối lượng bản ghi chèn mới tăng cao.
  * Với các bảng lịch sử giao dịch và logs (`parking_access_logs`, `meter_readings`, `audit_logs`), các trường thời gian (`created_at`, `log_timestamp`) là yếu tố lọc và sắp xếp chủ đạo.

### 2.2 Ràng buộc khóa ngoại & Chuẩn hóa (Normalization)
* Hệ thống được chuẩn hóa ở cấp độ cao (3NF - 4NF): Phân tách rõ ràng giữa `users`, `user_roles`, `roles`, `role_permissions`, `permissions`, `apartments`, `residents`, `invoices`, `tickets`, `amenities`, `parcels`.
* Điểm cần lưu ý:
  * Một số bảng liên kết nghiệp vụ thường xuyên truy vấn đồng thời (`residents` ↔ `users` ↔ `apartments`, `invoices` ↔ `apartments`, `tickets` ↔ `apartments` ↔ `ticket_categories`). Nếu thiếu Composite Covering Indexes, các phép `JOIN` sẽ tốn kém bộ nhớ đệm Buffer Pool và gây ra nhiều vòng đọc I/O ngẫu nhiên (Random Reads).

---

## 3. Current Index Analysis

### 3.1 Các chỉ mục hiện có trong Schema gốc
1. `users`:
   * `UNIQUE` trên `username`, `phone_number`, `email`, `national_id_number`.
   * Indexes đơn: `idx_users_phone`, `idx_users_email`, `idx_users_national_id`.
2. `apartments`:
   * `idx_apartments_block_status (block_id, status)`
   * `idx_apartments_current_resident (current_resident_user_id)`
3. `amenities`:
   * `idx_amenities_perf (deleted_at, is_active, category_id, block_id, created_at)`
   * `idx_amenities_name_code_ft (amenity_name, amenity_code)` (FULLTEXT)
   * `idx_amenities_code_btree (amenity_code)`
4. `amenity_time_slots`:
   * `idx_amenity_slots_perf (amenity_id, is_active)`
5. `amenity_bookings`:
   * `idx_bookings_conflict_lookup (amenity_id, booking_date, status)`
   * `idx_bookings_resident (resident_user_id, booking_date DESC)`
   * `idx_amenity_bookings_perf (amenity_id, status, deleted_at)`
6. `invoices`:
   * `idx_invoices_period_status (billing_period, status)`
   * `idx_invoices_resident (resident_user_id, status)`
   * `idx_invoices_apt (apartment_id, status)`
7. `tickets`:
   * `idx_tickets_status (status, priority)`
   * `idx_tickets_apt (apartment_id, created_at DESC)`
   * `idx_tickets_tech (current_technician_user_id, status)`

### 3.2 Khoảng trống Index (Missing Indexes) được phát hiện qua Code Audit
1. **Bảng `user_sessions` (CRITICAL - P0):**
   * Truy vấn xác thực trong `AuthenticateBearer.php`:
     `where('refresh_token_hash', $tokenHash)->where('is_revoked', 0)->where('expires_at', '>', now())`
   * Bảng hiện tại chỉ có index `idx_sessions_user_id (user_id, is_revoked)`. Mặc dù `refresh_token_hash` có ràng buộc UNIQUE đơn, nhưng thiếu Composite Covering Index kết hợp `(refresh_token_hash, is_revoked, expires_at)`, buộc MySQL phải thực hiện thêm bước Key Lookup đối với mọi request API có Bearer token.
2. **Bảng `residents` (HIGH - P1):**
   * Truy vấn `ResidentService::list()` sắp xếp mặc định theo `stay_start_date desc` và lọc `is_active = 1`.
   * Thiếu composite index `(is_active, stay_start_date DESC)` dẫn tới việc MySQL phải thực hiện `Using filesort` trên toàn bộ tập dữ liệu.
   * Thiếu composite index `(user_id, is_active)` phục vụ việc tra cứu nhanh căn hộ của cư dân khi đăng nhập và truy cập Portal.
   * Thiếu composite index `(apartment_id, is_active, is_head_of_household)` phục vụ truy vấn danh sách thành viên cùng căn hộ (`ResidentService::getById()`).
3. **Bảng `invoices` (HIGH - P1):**
   * Truy vấn lịch sử 10 hóa đơn gần nhất theo căn hộ (`ResidentPortalController`):
     `where('apartment_id', $apartmentId)->orderBy('due_date', 'desc')->limit(10)`
   * Index hiện tại là `(apartment_id, status)`, thiếu cột `due_date`, buộc MySQL phải đọc dữ liệu và sắp xếp tạm (Filesort). Cần bổ sung `idx_invoices_apt_due (apartment_id, due_date DESC)`.
4. **Bảng `tickets` (MEDIUM - P2):**
   * Dashboard đếm ticket quá hạn:
     `whereIn('status', ['NEW', 'PROCESSING', 'RECEIVED'])->where('sla_deadline', '<', ...)->count()`
   * Index `(status, priority)` không bao gồm `sla_deadline`. Cần `idx_tickets_status_sla (status, sla_deadline)`.
   * Truy vấn ticket theo người tạo: `where('creator_user_id', $userId)->orderByDesc('created_at')`. Thiếu `(creator_user_id, created_at DESC)`.
5. **Bảng `amenity_bookings` (MEDIUM - P2):**
   * Dashboard đếm booking trong ngày: `where('booking_date', $today)->count()`.
   * Truy vấn portal theo căn hộ: `where('apartment_id', $apartmentId)->orderByDesc('booking_date')->orderBy('start_time')`. Thiếu composite index `(apartment_id, booking_date DESC, start_time ASC)`.
6. **Bảng `visitor_registrations` & `visitor_checkin_logs` (MEDIUM - P2):**
   * `visitor_checkin_logs`: Đếm khách đang trong tòa qua `whereNull('checkout_time')->count()`. Thiếu index `(checkout_time)`.
   * `visitor_registrations`: `where('apartment_id', $aptId)->orderByDesc('expected_arrival_time')`. Thiếu `(apartment_id, expected_arrival_time DESC)`.

---

## 4. Slow Query & Non-Sargable Conditions Analysis

Qua rà soát mã nguồn các Controller và Service, phát hiện các mẫu truy vấn không tối ưu:

### 4.1 Non-Sargable: `whereDate()` trên cột DATE
* **Vị trí:** [ManagementDashboardController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/ManagementDashboardController.php#L45)
* **Code hiện tại:**
  ```php
  $amenityBookingsCount = DB::table('amenity_bookings')
      ->whereDate('booking_date', $today)
      ->count();
  ```
* **Vấn đề:** Cột `booking_date` đã có kiểu dữ liệu chuẩn là `DATE`. Việc dùng `whereDate()` khiến Laravel biên dịch thành `date(booking_date) = ?`. Hàm `date()` bọc quanh cột khiến MySQL Optimizer không thể sử dụng B-Tree Index Range Scan, dẫn đến Full Index Scan hoặc Full Table Scan.
* **Giải pháp khắc phục:** Chuyển thành phép so sánh trực tiếp sargable:
  ```php
  ->where('booking_date', $today)
  ```

### 4.2 Non-Sargable: Wildcard Prefix `LIKE '%KEYWORD%'` trên Status
* **Vị trí:** [ReceptionPortalController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/ReceptionPortalController.php#L29-L40)
* **Code hiện tại:**
  ```php
  $pendingParcelsCount = DB::table('parcels')
      ->where('status', 'like', '%RECEIVED%')
      ->count();
  ```
* **Vấn đề:** Sử dụng ký tự đại diện `%` ở đầu chuỗi tìm kiếm vô hiệu hóa hoàn toàn chỉ mục B-Tree, buộc cơ sở dữ liệu phải duyệt qua từng dòng của bảng `parcels`.
* **Giải pháp khắc phục:** Cột `status` lưu các hằng số rõ ràng (`RECEIVED_AT_RECEPTION`, `RECEIVED`). Thay bằng phép so sánh chính xác hoặc `whereIn`:
  ```php
  ->where('status', 'RECEIVED_AT_RECEPTION')
  // hoặc
  ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
  ```

### 4.3 Subqueries lồng nhau (`whereHas`) với nhiều tầng `OR`
* **Vị trí:** [ResidentService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/ResidentService.php#L39-L47)
* **Code hiện tại:**
  ```php
  $query->where(function ($q) use ($search) {
      $q->whereHas('user', function ($uq) use ($search) {
          $uq->where('full_name', 'like', "%{$search}%")
              ->orWhere('phone_number', 'like', "%{$search}%")
              ->orWhere('national_id_number', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
      })->orWhereHas('apartment', function ($aq) use ($search) {
          $aq->where('apartment_number', 'like', "%{$search}%");
      });
  });
  ```
* **Vấn đề:** `whereHas` sinh ra hai truy vấn con `WHERE EXISTS (SELECT 1 FROM users WHERE ...) OR EXISTS (SELECT 1 FROM apartments WHERE ...)`. Với tập dữ liệu lớn, việc đánh giá `EXISTS` lặp đi lặp lại cho mỗi dòng của bảng `residents` gây ra overhead CPU và I/O rất lớn.
* **Giải pháp khắc phục:** Bổ sung index cho các trường tìm kiếm và chuyển đổi sang JOIN nếu cần lọc tập lớn, hoặc sử dụng subquery `whereIn('user_id', ...)` được đánh chỉ mục.

---

## 5. N+1 Query Analysis

### 5.1 Các phân hệ đã được tối ưu trước đó:
* `ManagementDashboardController`: Đã gom cụm 12 truy vấn doanh thu theo tháng thành 1 câu `GROUP BY billing_period` duy nhất.
* `AmenityService`: Đã sử dụng kỹ thuật gom ID (`pluck('id')`) và batch aggregation cho `amenity_time_slots` và `amenity_bookings` thay vì truy vấn trong vòng lặp.

### 5.2 Nguy cơ N+1 và Query Burst trong `ResidentPortalController::overview`:
* **Vấn đề:** Phương thức `overview` thực hiện 14 truy vấn riêng lẻ nối tiếp nhau trong một chu kỳ Request:
  1. `user_sessions` (token auth)
  2. `users` (thông tin cá nhân)
  3. `residents` (hồ sơ cư dân)
  4. `apartments` JOIN `blocks` (căn hộ & tòa nhà)
  5. `invoices` (KPI nợ đọng)
  6. `invoices` (danh sách 10 hóa đơn)
  7. `tickets` (đếm ticket active)
  8. `tickets` (danh sách 10 tickets)
  9. `amenity_bookings` (đếm booking)
  10. `amenity_bookings` (danh sách 10 bookings)
  11. `visitor_registrations` (đếm khách)
  12. `visitor_registrations` (danh sách 10 khách)
  13. `amenities` (lấy danh sách tiện ích active)
  14. `ticket_categories` (danh mục phản ánh)
* **Giải pháp:**
  * Hai truy vấn cuối (13 & 14) là **Master Data tĩnh**, nội dung hoàn toàn giống nhau cho mọi cư dân và ít thay đổi. Cần đưa vào bộ nhớ đệm Cache-Aside (TTL 10 phút) để loại bỏ 2 round-trips tới cơ sở dữ liệu trên mỗi request.
  * Các truy vấn còn lại cần có Composite Indexes để đảm bảo Index Seek O(log N) với thời gian phản hồi < 1ms mỗi query.

---

## 6. Pagination Analysis

### 6.1 Hiện trạng phân trang
| API Endpoint | Cơ chế phân trang | Tham số kiểm soát | Đánh giá |
| :--- | :---: | :---: | :--- |
| `GET /api/v1/residents` | `LengthAwarePaginator` | `limit` (5 - 100, default 15) | ✅ Tốt, có chặn max limit |
| `GET /api/v1/users` | `LengthAwarePaginator` | `limit` (5 - 100, default 15) | ✅ Tốt, có chặn max limit |
| `GET /api/v1/admin/amenities` | Limit / Offset | `limit` (1 - 100, default 10) | ✅ Tốt, có ETag Cache |
| `GET /api/v1/amenities/search` | Limit / Offset | `limit` (1 - 100, default 10) | ✅ Tốt, có Search Cache |
| `GET /api/v1/meta/blocks` | Toàn bộ danh sách | Không có | Chấp nhận được (~3 blocks) |
| `GET /api/v1/admin/amenity-categories` | Toàn bộ danh sách | Không có | Chấp nhận được (~10 danh mục) |
| `GET /api/v1/resident/overview` | Limit 10 cố định | `limit(10)` cho từng mục con | ✅ Tốt cho màn hình tổng quan |

### 6.2 Điểm cần tối ưu
* Trong `AmenityService::getPaginatedAmenities`:
  * Lệnh đếm tổng số dòng: `$total = $query->count()` đang được gọi trên Query Builder đã chứa đầy đủ `leftJoin('amenity_categories')`, `leftJoin('blocks')`, và các mệnh đề `orderBy()`.
  * Việc JOIN thêm các bảng phụ chỉ để chạy `COUNT(*)` gây lãng phí bộ nhớ đệm Buffer Pool. Có thể tách truy vấn count tinh gọn hoặc bỏ qua các JOIN không tham gia vào mệnh đề `WHERE`.

---

## 7. Connection Pool & Database Configuration Analysis

### 7.1 Cấu hình PDO trong `config/database.php`
* Hiện tại cấu hình kết nối MySQL:
  ```php
  'mysql' => [
      'driver' => 'mysql',
      'host' => env('DB_HOST', 'db'),
      'port' => env('DB_PORT', '3306'),
      'database' => env('DB_DATABASE', 'quanly_toanha'),
      'username' => env('DB_USERNAME', 'root'),
      'password' => env('DB_PASSWORD', '123567'),
      ...
  ]
  ```
* **Khoảng trống:**
  * Chưa cấu hình `PDO::ATTR_EMULATE_PREPARES => false`: Mặc định PDO có thể mô phỏng prepare statement trên client thay vì sử dụng native prepared statements của MySQL server.
  * Chưa cấu hình `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true`: Đảm bảo driver MySQL client lưu đệm kết quả truy vấn, giải phóng thread kết nối nhanh chóng.
  * Chưa hỗ trợ tùy chọn `PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false)` cho môi trường high-throughput.

### 7.2 Cấu hình MySQL Server trong `docker-compose.yml`
* Command khởi chạy hiện tại:
  `--default-authentication-plugin=mysql_native_password --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --skip-name-resolve`
* **Vấn đề:** MySQL 8.0 mặc định cấp phát `innodb_buffer_pool_size = 128MB`, quá nhỏ đối với môi trường có 109 bảng và các bảng dữ liệu lớn.
* **Khuyến nghị tham số:**
  * `--innodb_buffer_pool_size=256M` (hoặc 512M tùy RAM host) để lưu trọn bộ dữ liệu và chỉ mục trong RAM.
  * `--innodb_log_file_size=64M` giúp ghi redo log mượt mà, giảm checkpoint freeze.
  * `--innodb_flush_log_at_trx_commit=2` tăng tốc độ ghi dữ liệu lên gấp 3-5 lần mà vẫn an toàn khi MySQL chạy trong Docker.
  * `--max_connections=200` đáp ứng tải đồng thời tốt hơn.

---

## 8. Statistics & Query Optimizer Analysis

* MySQL 8 sử dụng cơ chế **Automatic Statistics Update** (`innodb_stats_auto_recalc = ON`).
* Tuy nhiên, khi các bảng có khối lượng chèn hàng loạt (chẳng hạn khi chạy seed dữ liệu mẫu hoặc đồng bộ hóa công tơ điện nước hàng loạt), chỉ số thống kê (cardinality) có thể bị lệch tạm thời khiến Optimizer chọn nhầm execution plan (ví dụ chọn Scan thay vì Seek).
* **Khuyến nghị:**
  * Thiết lập chỉ thị `ANALYZE TABLE` định kỳ hoặc sau các đợt seed/sync dữ liệu lớn cho các bảng giao dịch trọng yếu: `invoices`, `amenity_bookings`, `tickets`, `parcels`, `residents`.

---

## 9. Cache Opportunities (Cache-Aside Pattern)

Áp dụng mô hình **Cache-Aside** với nguyên tắc: Cơ sở dữ liệu luôn là nguồn chân lý (Source of Truth), Redis đóng vai trò tầng đệm tăng tốc:

| Dữ liệu mục tiêu | Tần suất Đọc / Ghi | Thời gian TTL | Chiến lược Invalidation |
| :--- | :---: | :---: | :--- |
| **Danh mục tiện ích** (`amenity_categories`) | Rất cao / Rất hiếm | 10 phút (600s) | Invalidate ngay khi Create/Update/Delete category |
| **Tiện ích khả dụng cho Resident** (`available_amenities`) | Cực cao / Thấp | 10 phút (600s) | Invalidate khi thay đổi trạng thái hoặc cập nhật tiện ích |
| **Danh mục Ticket** (`ticket_categories`) | Rất cao / Gần như tĩnh | 1 giờ (3600s) | Invalidate khi admin sửa danh mục ticket |
| **Danh sách Tòa nhà** (`blocks`) | Cao / Rất hiếm | 1 giờ (3600s) | Invalidate khi tạo/sửa block |
| **Tổng quan Dashboard Quản lý** | Cao / Trung bình | 2 phút (120s) | Đã triển khai qua `Cache::remember` |

---

## 10. Search Opportunities & Architecture

* **Hiện trạng module tìm kiếm:** Hệ thống đã có `SearchManager` tinh gọn hỗ trợ driver `mysql` (B-Tree + Fulltext), `smart` và các driver mở rộng (`meilisearch`, `elasticsearch`).
* **Đánh giá:**
  * Đối với các thực thể có quan hệ ràng buộc chặt chẽ và dữ liệu có cấu trúc (`apartments`, `residents`, `invoices`, `users`), **MySQL B-Tree Index và Composite Index hoàn toàn đáp ứng xuất sắc** độ trễ < 5ms cho các truy vấn exact code, phone, email, national ID.
  * Đối với tìm kiếm văn bản tự do trên tiện ích (`amenity_name`, `amenity_code`), MySQL Fulltext Index `idx_amenities_name_code_ft` kết hợp với bộ chuẩn hóa tiếng Việt không dấu `VietnameseNormalizer` đã hoạt động rất hiệu quả, không cần thiết phải thêm chi phí vận hành Meilisearch/Elasticsearch tại thời điểm hiện tại.

---

## 11. Performance Bottlenecks Tổng Hợp

1. **[P0] Thiếu Composite Index trên `user_sessions` cho quá trình xác thực:**
   * Mỗi request API đều thực hiện tìm kiếm phiên đăng nhập theo `refresh_token_hash`, `is_revoked`, `expires_at`.
2. **[P0] Thiếu Composite Index phục vụ phân trang danh sách cư dân `residents`:**
   * Phân trang mặc định lọc theo `is_active` và sắp xếp theo `stay_start_date DESC`. Không có index hỗ trợ dẫn đến Full Scan + Filesort.
3. **[P1] Truy vấn Non-sargable `whereDate()` trong Dashboard và `LIKE '%...%'` trong Portal Lễ tân:**
   * Vô hiệu hóa chỉ mục B-Tree trên các trường trạng thái và ngày tháng.
4. **[P1] Thiếu Composite Indexes phục vụ các truy vấn Portal cá nhân hóa:**
   * Bảng `invoices`, `tickets`, `amenity_bookings`, `visitor_registrations` đều truy vấn theo `apartment_id` kết hợp với sắp xếp ngày giảm dần nhưng thiếu chỉ mục kết hợp.
5. **[P2] Lặp lại truy vấn Master Data tĩnh trong Resident Portal:**
   * Mỗi lượt truy cập portal đều truy vấn lại `amenities` và `ticket_categories` từ database.
6. **[P2] Cấu hình Buffer Pool của MySQL chưa tận dụng tối đa RAM:**
   * Mặc định 128MB có nguy cơ tràn cache khi dữ liệu tăng.

---

## 12. Recommended Optimizations Plan

| # | Hạng mục | Vấn đề | Đề xuất giải pháp | Mức độ tác động | Rủi ro | Chi phí | Ưu tiên |
| :---: | :--- | :--- | :--- | :--- | :---: | :---: | :---: |
| **1** | **Index: Xác thực Phiên** | `user_sessions` thiếu composite index tra cứu token | Thêm migration tạo index `(refresh_token_hash, is_revoked, expires_at)` | Giảm 60-80% thời gian xác thực mọi request API | Rất thấp | Thấp | **P0** |
| **2** | **Index: Cư dân & Căn hộ** | `residents` bị filesort khi phân trang và tra cứu theo căn hộ | Thêm index `(is_active, stay_start_date DESC)`, `(user_id, is_active)`, `(apartment_id, is_active, is_head_of_household)` | Tăng tốc API `GET /api/v1/residents` gấp 5-10 lần | Rất thấp | Thấp | **P0** |
| **3** | **Index: Hóa đơn & Nợ đọng** | `invoices` thiếu index sắp xếp hạn nộp và căn hộ | Thêm index `idx_invoices_apt_due (apartment_id, due_date DESC)` và `idx_invoices_status_remaining (status, remaining_balance)` | Giảm 70% I/O khi nạp lịch sử hóa đơn portal | Rất thấp | Thấp | **P1** |
| **4** | **Index: Sự cố & Đặt chỗ** | `tickets` và `amenity_bookings` thiếu index theo căn hộ và ngày | Thêm index `idx_tickets_creator_created (creator_user_id, created_at DESC)`, `idx_tickets_status_sla (status, sla_deadline)`, `idx_bookings_apt_date_time (apartment_id, booking_date DESC, start_time ASC)` | Giảm thời gian query ticket & booking xuống < 2ms | Rất thấp | Thấp | **P1** |
| **5** | **Index: Lễ tân & Khách** | `visitor_checkin_logs` và `visitor_registrations` thiếu index | Thêm index `(checkout_time)` cho log check-in và `(apartment_id, expected_arrival_time DESC)` | Tăng tốc Portal Lễ tân | Rất thấp | Thấp | **P1** |
| **6** | **Query Rewrite: Sargable** | `whereDate()` và `LIKE '%RECEIVED%'` làm mất index | Sửa thành `where('booking_date', $today)` và `where('status', 'RECEIVED_AT_RECEPTION')` | Chuyển Table Scan thành Index Seek | Rất thấp | Rất thấp | **P1** |
| **7** | **Cache: Master Data Portal** | `ResidentPortalController` query lặp lại danh mục tĩnh | Bổ sung Cache-Aside cho `available_amenities` và `ticket_categories` (TTL 10m) | Giảm 2 query round-trips trên mỗi request portal | Thấp | Thấp | **P2** |
| **8** | **Database & Docker Tuning** | Tham số InnoDB Buffer Pool mặc định quá nhỏ | Cập nhật cấu hình MySQL 8 trong docker-compose: `--innodb-buffer-pool-size=256M`, `--innodb-flush-log-at-trx-commit=2` | Tối ưu hóa I/O và throughput tổng thể | Rất thấp | Thấp | **P2** |
