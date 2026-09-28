# PHÂN TÍCH BUG LOAD CHẬM VÀ ĐĂNG NHẬP CHẬM
# Repository `QUANLY_TOANHA-DANCU` — branch `master`

## 1. Kết luận nhanh

Dựa trên source code của branch `master`, tình trạng chậm nhiều khả năng đến từ ba nhóm nguyên nhân:

1. **Mỗi lần đăng nhập tải dư quan hệ role/permission và thực hiện nhiều truy vấn hơn cần thiết.**
2. **Các trang dashboard trả về quá nhiều dữ liệu trong một request, không phân trang và có nhiều truy vấn nối tiếp.**
3. **Cấu hình runtime hiện thiên về development:** `APP_DEBUG=true`, cache dùng database, session dùng file, queue dùng database, production opcache chưa tối ưu.

Ngoài vấn đề hiệu năng, luồng login hiện có lỗi bảo mật và độ tin cậy nghiêm trọng: chấp nhận nhiều mật khẩu hard-code, hiển thị mật khẩu mặc định trong response lỗi và nuốt lỗi khi ghi session.

---

## 2. Phân biệt “đăng nhập chậm” và “đăng nhập lỗi”

Nếu “đăng nhập low” nghĩa là **đăng nhập lâu**, cần đo thời gian ở các đoạn:

```text
Request nhận vào
→ Tìm user
→ Hash::check
→ Tải roles/permissions
→ Ghi user_sessions
→ Update last_login
→ Trả response
```

Nếu “đăng nhập low” nghĩa là **đăng nhập lúc được lúc không hoặc login thất bại**, cần kiểm tra:

- Database container đã ready chưa.
- Cấu hình `DB_HOST` có đúng tên service Docker không.
- Bảng `user_sessions` có tồn tại không.
- Session insert có lỗi nhưng bị `catch` bỏ qua không.
- Token được lưu ở frontend có đúng format `Bearer <token>` không.
- User có `status = ACTIVE` không.
- Password hash có đúng driver/format không.
- API frontend gọi có đúng URL và CORS không.

---

# 3. Nguyên nhân đã xác định trong source code

## 3.1. Login eager-load permission rồi lại query permission lần nữa

Trong `AuthController@login`, code hiện tại dùng:

```php
$user = User::with('roles.permissions')
    ->where(...)
    ->first();
```

Sau đó lại gọi:

```php
$permissions = $user->getAllPermissions();
```

`getAllPermissions()` tiếp tục thực hiện query join `permissions`, `role_permissions` và `user_roles`. Vì vậy login vừa tải `roles.permissions`, vừa tải quyền lần thứ hai.

### Cách chỉnh

Chọn một trong hai hướng:

**Hướng A — Login chỉ tải role, sau đó query permissions một lần:**

```php
$user = User::with('roles:id,role_code')
    ->where(function ($q) use ($searchIdentifier) {
        $q->where('username', $searchIdentifier)
          ->orWhere('email', $searchIdentifier)
          ->orWhere('phone_number', $searchIdentifier);
    })
    ->first();

$rawRoleCodes = $user->roles->pluck('role_code')->values()->all();
$permissions = $user->getAllPermissions();
```

**Hướng B — Tải roles.permissions rồi lấy quyền từ collection, không query lại:**

```php
$permissions = $user->roles
    ->flatMap(fn ($role) => $role->permissions->pluck('permission_code'))
    ->unique()
    ->values()
    ->all();
```

Không dùng đồng thời cả hai cách.

## 3.2. `Hash::check()` vốn chậm có chủ đích

Source dùng `Hash::check()` với mật khẩu hash. Nếu bcrypt rounds là 12, một lần kiểm tra có thể mất khoảng vài trăm mili-giây tùy CPU. Đây không phải bug nếu chỉ khoảng 100–500 ms; đây là cơ chế chống brute-force.

### Cách chỉnh đúng

- Không tắt hash hoặc chuyển sang MD5/SHA1.
- Không giảm rounds chỉ để login nhanh nếu chưa benchmark.
- Chỉ kiểm tra `Hash::check()` sau khi đã tìm được user.
- Đo thời gian thật bằng log/Profiler trước khi thay đổi `BCRYPT_ROUNDS`.
- Nếu dùng Argon2/bcrypt, thống nhất một chuẩn hash trong toàn hệ thống.

## 3.3. Mật khẩu hard-code là lỗi bảo mật, không phải cách tối ưu

Source hiện chấp nhận các mật khẩu:

```php
123567
Cassavas@2026
admin123
password
```

Ngoài ra response lỗi còn hiển thị:

```text
Mật khẩu mặc định hệ thống là: 123567
```

### Cách chỉnh bắt buộc

Xóa toàn bộ logic bypass:

```php
if ($password === '123567' || ... ) {
    $isValidPassword = true;
}
```

Chỉ giữ:

```php
$isValidPassword = Hash::check($password, $user->password_hash);
```

Response lỗi chỉ nên là:

```php
return response()->json([
    'success' => false,
    'message' => 'Thông tin đăng nhập không hợp lệ.',
], 401);
```

Tài khoản demo phải được tạo bằng seeder, password phải lấy từ `.env` hoặc secret manager, không ghi trong controller.

## 3.4. Lỗi ghi user session đang bị nuốt

Source có:

```php
try {
    DB::table('user_sessions')->insert([...]);
    $user->updateQuietly([...]);
} catch (\Throwable) {
    // ignore session insert error
}
```

Nếu bảng, column, database hoặc constraint có lỗi, login vẫn trả thành công nhưng request tiếp theo bị `401` vì token không tồn tại trong `user_sessions`. Người dùng sẽ thấy hiện tượng “login được nhưng trang không load” hoặc “login chập chờn”.

### Cách chỉnh

Không nuốt lỗi im lặng. Log lỗi và trả lỗi server phù hợp:

```php
try {
    DB::transaction(function () use ($user, $tokenHash, $request) {
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'Web Dashboard',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'expires_at' => now()->addDays(7),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        $user->updateQuietly([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
        ]);
    });
} catch (\Throwable $e) {
    report($e);

    return response()->json([
        'success' => false,
        'message' => 'Không thể tạo phiên đăng nhập. Vui lòng thử lại.',
    ], 503);
}
```

## 3.5. Query login dùng OR trên ba cột

Login tìm theo `username`, `email` hoặc `phone_number`. Schema hiện có unique/index cho các cột này, nên thường vẫn đủ nhanh. Tuy nhiên cần kiểm tra bằng `EXPLAIN` trên database thực tế.

### Cách kiểm tra

```sql
EXPLAIN SELECT id, username, email, phone_number, password_hash, status
FROM users
WHERE username = '...'
   OR email = '...'
   OR phone_number = '...'
LIMIT 1;
```

Nếu query không dùng index, cân nhắc tách logic:

```php
$user = User::where('username', $identifier)->first()
    ?? User::where('email', $identifier)->first()
    ?? User::where('phone_number', $identifier)->first();
```

Không tạo thêm nhiều index trước khi có kết quả `EXPLAIN`.

---

# 4. Nguyên nhân làm trang load chậm

## 4.1. Resident overview trả toàn bộ dữ liệu không phân trang

`ResidentPortalController@overview` đang lấy bằng `get()` toàn bộ:

- invoices;
- tickets;
- amenity bookings;
- visitors;
- available amenities;
- ticket categories.

Khi dữ liệu tăng, một request sẽ trả rất nhiều dòng và payload JSON lớn.

### Cách chỉnh

Chỉ lấy dữ liệu cần cho dashboard:

```php
$invoices = DB::table('invoices')
    ->where('apartment_id', $apartmentId)
    ->select('id', 'invoice_number', 'status', 'total_amount', 'remaining_balance', 'due_date')
    ->orderByDesc('due_date')
    ->limit(10)
    ->get();
```

Các danh sách đầy đủ nên có API riêng:

```text
GET /api/v1/resident/overview
GET /api/v1/resident/invoices?page=1&per_page=20
GET /api/v1/resident/tickets?page=1&per_page=20
GET /api/v1/resident/bookings?page=1&per_page=20
GET /api/v1/resident/visitors?page=1&per_page=20
```

## 4.2. Dashboard quản lý có nhiều query trong một cache miss

`ManagementDashboardController@overview` thực hiện nhiều query: residents, invoices, tickets, bookings, blocks/apartments, maintenance logs và notifications.

Code đã có `Cache::remember(..., 30, ...)`, nhưng `.env.example` đặt:

```env
CACHE_STORE=database
```

Database cache vẫn tạo query vào bảng `cache`, nên không tối ưu bằng Redis. Ngoài ra cache key hiện là một key chung, không có version hoặc scope.

### Cách chỉnh

Production nên dùng Redis:

```env
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
```

Nếu chưa có Redis, dùng file cache cho môi trường cá nhân:

```env
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
```

Không dùng `database` cache làm phương án hiệu năng lâu dài khi traffic tăng.

## 4.3. Cache dashboard chỉ 30 giây và không có cache theo phạm vi

Nếu dashboard phải tải nhiều query, TTL 30 giây có thể tạo nhiều cache miss. Có thể dùng:

```php
$key = 'management_dashboard:v1:overview';
$data = Cache::remember($key, now()->addMinutes(2), fn () => $this->buildOverview());
```

Sau khi có thay đổi hóa đơn/ticket/booking, xóa cache theo event:

```php
Cache::forget('management_dashboard:v1:overview');
```

Không tăng TTL nếu số liệu cần gần realtime; cần xác định yêu cầu nghiệp vụ trước.

## 4.4. Query có thể thiếu index theo điều kiện lọc và sắp xếp

Các index cần kiểm tra bằng `EXPLAIN`:

```text
residents(user_id, is_active)
invoices(apartment_id, due_date)
invoices(status)
tickets(apartment_id, created_at)
tickets(creator_user_id, created_at)
amenity_bookings(apartment_id, booking_date, status)
amenity_bookings(resident_user_id, booking_date, status)
visitor_registrations(apartment_id, expected_arrival_time)
visitor_registrations(host_resident_user_id, expected_arrival_time)
amenities(is_active)
user_in_app_notifications(created_at)
```

Chỉ tạo index sau khi kiểm tra query thật. Composite index phải đặt cột theo thứ tự phù hợp với `WHERE`, `JOIN` và `ORDER BY`.

## 4.5. Request overview quá lớn

Không nên trả `apartments.*`, `tickets.*`, `amenity_bookings.*` nếu frontend chỉ hiển thị vài trường. Chọn cột cụ thể giúp giảm:

- thời gian đọc database;
- bộ nhớ PHP;
- kích thước JSON;
- thời gian truyền mạng;
- thời gian parse JSON trên trình duyệt.

---

# 5. Cấu hình runtime cần chỉnh

## 5.1. Development

```env
APP_ENV=local
APP_DEBUG=true
LOG_LEVEL=debug
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
```

Dùng để debug, không dùng cho production.

## 5.2. Production tối thiểu

```env
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BCRYPT_ROUNDS=12
```

Sau khi đổi `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
composer dump-autoload --optimize
```

Không chạy `php artisan serve` làm web server production. Dùng PHP-FPM với Nginx/Apache hoặc container production.

## 5.3. PHP OPcache

Dockerfile hiện bật OPcache nhưng vẫn có:

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

Đây phù hợp development hơn production. Production nên dùng:

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.validate_timestamps=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.revalidate_freq=0
```

Chỉ dùng `validate_timestamps=0` khi quy trình deploy luôn restart/reload PHP-FPM sau khi phát hành code.

---

# 6. Checklist đo trước khi chỉnh

## 6.1. Đo login

```bash
curl -sS -o /tmp/login.json -w '\nhttp=%{http_code} total=%{time_total}s\n' \\
  -H 'Content-Type: application/json' \\
  -d '{"username":"...","password":"..."}' \\
  http://localhost:8000/api/login
```

Đo ít nhất 20 lần và ghi p50/p95, không kết luận từ một lần gọi.

## 6.2. Đo endpoint dashboard

```bash
curl -sS -o /tmp/dashboard.json -w '\nhttp=%{http_code} total=%{time_total}s size=%{size_download}\n' \\
  -H 'Authorization: Bearer <token>' \\
  http://localhost:8000/api/management/overview
```

## 6.3. Bật query log trong local

```php
DB::listen(function ($query) {
    logger()->debug('sql', [
        'sql' => $query->sql,
        'bindings' => $query->bindings,
        'time_ms' => $query->time,
    ]);
});
```

Chỉ bật ở local/staging, không ghi password/token/PII vào log production.

## 6.4. Kiểm tra database

```sql
SHOW PROCESSLIST;
EXPLAIN ANALYZE <query chậm>;
SHOW INDEX FROM users;
SHOW INDEX FROM user_sessions;
```

---

# 7. Thứ tự ưu tiên chỉnh sửa

## P0 — Sửa ngay

1. Xóa password hard-code và không hiển thị mật khẩu trong response.
2. Không nuốt lỗi `user_sessions`.
3. Xác minh database engine thực tế. File schema dùng cú pháp SQL Server như `UNIQUEIDENTIFIER`, `NEWID()`, `DATETIME2`, trong khi Laravel config/migration đang dùng MySQL. Không được trộn hai schema.
4. Thêm rate limit và lockout cho login.
5. Bảo đảm endpoint resident lấy user từ token, không nhận tùy ý `user_id` từ query nếu endpoint đã xác thực.

## P1 — Tối ưu login

1. Bỏ eager-load `roles.permissions` dư thừa.
2. Tối ưu query permission và cache permission theo user/role.
3. Kiểm tra `EXPLAIN` query users.
4. Thêm index đúng cho session lookup và các bảng RBAC.
5. Đo p50/p95 login.

## P1 — Tối ưu load trang

1. Tách dashboard thành các API nhỏ hoặc giới hạn dữ liệu.
2. Thêm pagination/limit.
3. Chọn cột thay vì `*`.
4. Chuyển cache từ database sang Redis.
5. Tạo composite index sau khi có `EXPLAIN`.

## P2 — Tối ưu vận hành

1. Production cache config/route/view.
2. Tối ưu OPcache.
3. Đưa email, notification và report sang queue.
4. Thêm monitoring cho p95 latency, query time, error rate và DB connection pool.
5. Tạo load test với dữ liệu gần production.

---

# 8. Tiêu chí nghiệm thu sau khi chỉnh

| Hạng mục | Mục tiêu đề xuất |
|---|---|
| Login p95 trên local/staging | < 1 giây, không tính thời gian mạng bất thường |
| Dashboard cache hit p95 | < 300 ms |
| Dashboard cache miss p95 | < 1.5 giây với dữ liệu test đã xác định |
| HTTP 5xx | Không có trong smoke test |
| Login sai | Không tiết lộ user tồn tại hay password mặc định |
| Session insert lỗi | Trả lỗi rõ ràng, có log và không tạo login giả thành công |
| Resident overview | Có giới hạn dữ liệu, không trả toàn bộ lịch sử |
| Security | Có rate limit, lockout và ownership check |

Các con số trên là **mục tiêu đề xuất**, cần điều chỉnh theo môi trường, dữ liệu và yêu cầu của nhóm; không được ghi là kết quả đo nếu chưa chạy benchmark.
