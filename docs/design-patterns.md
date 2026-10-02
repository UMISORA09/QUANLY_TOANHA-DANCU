# TÀI LIỆU THIẾT KẾ VÀ ÁP DỤNG DESIGN PATTERNS

Dự án: **UMISORA09/QUANLY_TOANHA-DANCU**  
Mục tiêu kiến trúc: Chuẩn hóa kiến trúc hệ thống theo các Design Pattern tiêu chuẩn nhằm giảm coupling, tăng cohesion, nâng cao testability và maintainability, bảo toàn 100% behavior hiện tại mà không over-engineering.

---

## 1. TỔNG QUAN KIẾN TRÚC HỆ THỐNG

### 1.1. Backend Layers (Laravel 12 / PHP 8.4)
```text
HTTP / Presentation Layer
        │ (Form Requests, Route Bindings, DTO Transformation)
        ▼
   Controllers
        │ (Request Validation, Service Invocation, Response Serialization)
        ▼
Application Services (Service Layer)
        │ (Business Orchestration, Transactions, Policy Verification)
        ├─────────────────────────────┐
        ▼                             ▼
   Domain Logic / Events             DTOs (Type-safe Transport)
        │ (Events & Listeners)
        ▼
Repository Interface (Contract)
        │ (Decoupled Data Access Abstraction)
        ▼
Repository Implementation (Eloquent)
        │ (Query Scopes, Relationship Loading, Eager Loading)
        ▼
Eloquent Models / Database (MySQL 8 / Redis)
```

### 1.2. Frontend Layers (React 19 / TypeScript / Vite 8)
```text
React Page Component (Route Level)
        │
        ▼
Feature Container (Layout, Orchestration)
        │
        ▼
Custom Hooks Layer (State & Logic: useSearch, usePagination, useModalState, useFreshness)
        │
        ▼
Presentational Components (Pure UI, Reusable, Stylable)
        │
        ▼
API Service Layer (api.ts, cicdApi.ts, amenityCache.ts)
        │
        ▼
Laravel REST API Endpoints
```

---

## 2. CATALOG DESIGN PATTERNS ĐÃ ÁP DỤNG

### 2.1. Repository Pattern

#### Where used:
- Module Quản lý Cư dân (`App\Repositories\Contracts\ResidentRepositoryInterface`, `App\Repositories\Eloquent\EloquentResidentRepository`)
- Module Tiện ích Tòa nhà (`App\Repositories\Contracts\AmenityRepositoryInterface`, `App\Repositories\Eloquent\DatabaseAmenityRepository`)

#### Why used:
- `ResidentService` và `AmenityService` trước đây vừa đảm nhiệm business orchestration, vừa trực tiếp gọi Eloquent query builder, join bảng phức tạp, tính toán aggregate queries và xử lý database locking.
- Cần trừu tượng hóa data access layer để dễ dàng mock trong Unit Tests mà không cần khởi tạo cơ sở dữ liệu vật lý.

#### Problem solved:
- Phá vỡ sự phụ thuộc trực tiếp (tight coupling) giữa Service và Eloquent ORM.
- Tách biệt ranh giới giữa logic nghiệp vụ (quy định số lượng chủ hộ, phân định quan hệ, kiểm soát xung đột đồng thời) và logic truy vấn dữ liệu (whereHas, with, eager loading, join).

#### Before:
```php
// ResidentService.php trực tiếp query và join bảng
$query = Resident::query()->with([...]);
if ($search !== '') {
    $query->where(function ($q) use ($search) {
        $q->whereHas('user', ...)->orWhereHas('apartment', ...);
    });
}
if ($sortBy === 'full_name') {
    $query->join('users', 'residents.user_id', '=', 'users.id')->orderBy(...);
}
return $query->paginate($perPage);
```

#### After:
```php
// Controller / Service
public function __construct(
    protected ?ResidentRepositoryInterface $repository = null
) {
    $this->repository = $this->repository ?: app(ResidentRepositoryInterface::class);
}

public function list(array|ResidentFilterDTO $params = []): LengthAwarePaginator
{
    $filter = $params instanceof ResidentFilterDTO ? $params : ResidentFilterDTO::fromArray($params);
    return $this->repository->list($filter);
}
```

#### Trade-off:
- Thêm interface và class phụ trợ cho mỗi module dữ liệu phức tạp.
- Lợi ích thu được vượt trội: testability tăng 100%, có thể swap data source hoặc mock repository trong unit test tức thì.

---

### 2.2. Data Transfer Object (DTO) Pattern

#### Where used:
- `App\DTOs\SearchQueryDTO`: Chuẩn hóa tham số truy vấn tìm kiếm đa năng.
- `App\DTOs\ResidentFilterDTO`: Chuẩn hóa bộ lọc, sắp xếp, tìm kiếm cư dân và căn hộ.
- `App\DTOs\AmenityFilterDTO`: Chuẩn hóa bộ lọc danh mục, tòa block, trạng thái và phân trang tiện ích.

#### Why used:
- Trước đây dữ liệu filter truyền qua các layer dưới dạng `array<string, mixed>`. Thiếu type safety, dễ lỗi chính tả (`apartment_id` vs `apartmentId`, `is_active` boolean vs string), khó autocomplete và khó bảo trì khi mở rộng tham số.

#### Problem solved:
- Đảm bảo tính toàn vẹn dữ liệu (Type Safety & Immutability) thông qua `readonly class`.
- Cung cấp factory method `fromArray()` để parse, sanitize và validate kiểu dữ liệu an toàn ngay tại ranh giới đầu vào.

#### Before:
```php
// Nhận array lỏng lẻo
public function list(array $params = []): LengthAwarePaginator
{
    $search = trim((string) ($params['search'] ?? ''));
    $isActive = isset($params['is_active']) ? filter_var($params['is_active'], ...) : null;
    // ...
}
```

#### After:
```php
final readonly class ResidentFilterDTO
{
    public function __construct(
        public string $search = '',
        public string $apartmentId = '',
        public string $residentType = '',
        public ?bool $isActive = null,
        public ?bool $isHeadOfHousehold = null,
        public string $sortBy = 'stay_start_date',
        public string $sortOrder = 'desc',
        public int $limit = 15,
        public int $page = 1,
    ) {}

    public static function fromArray(array $params): self { ... }
}
```

#### Trade-off:
- Cần viết class DTO và mapping method.
- Giảm thiểu triệt để lỗi runtime do sai key, thiếu type casting.

---

### 2.3. Factory Pattern

#### Where used:
- `App\Services\Search\Factories\SearchDriverFactory`: Khởi tạo Search Driver strategy phù hợp với cấu hình môi trường.

#### Why used:
- Việc khởi tạo `SmartSearchDriver`, `MeilisearchDriver`, hay `ElasticsearchDriver` đòi hỏi các dependency khác nhau (HTTP Client, Elasticsearch Client, Config, Normalizer).
- `SearchManager` không nên tự `new` driver hoặc chứa logic phân nhánh instantiation phức tạp.

#### Problem solved:
- Đảm bảo tuân thủ nguyên lý Single Responsibility (SRP) và Open/Closed Principle (OCP).
- Khi thêm Search Engine mới (ví dụ TypesenseDriver), chỉ cần bổ sung vào `SearchDriverFactory` mà không cần chỉnh sửa `SearchManager`.

#### Before:
```php
// SearchManager tự phân nhánh và quản lý config khởi tạo driver
if ($driverName === 'elasticsearch') {
    return new ElasticsearchDriver(new Client([...]));
} elseif ($driverName === 'meilisearch') {
    return new MeilisearchDriver(...);
}
```

#### After:
```php
class SearchDriverFactory
{
    public function create(string $driver): SearchDriverInterface
    {
        return match (strtolower($driver)) {
            'smart', 'database', 'mysql' => app(SmartSearchDriver::class),
            'meilisearch', 'meili' => app(MeilisearchDriver::class),
            'elasticsearch', 'elastic' => app(ElasticsearchDriver::class),
            default => throw new InvalidArgumentException("Unsupported search driver: {$driver}"),
        };
    }
}
```

#### Trade-off:
- Thêm 1 lớp khởi tạo đối tượng, hoàn toàn xứng đáng vì tách biệt rõ ràng giữa "Tạo ra Driver" và "Sử dụng Driver".

---

### 2.4. Strategy Pattern

#### Where used:
1. **Search Subsystem**:
   - `SearchDriverInterface` (Contract)
   - `SmartSearchDriver` (MySQL Fulltext / Smart Algorithm)
   - `MeilisearchDriver` (Meilisearch Engine)
   - `ElasticsearchDriver` (Elasticsearch Cluster)
2. **Freshness Observability Subsystem**:
   - `FreshnessEvaluatorInterface` (Contract)
   - `CollectorFreshnessEvaluator` (Đánh giá độ trễ của hệ thống thu thập số liệu CI/CD & Metrics)

#### Why used:
- Các thuật toán tìm kiếm và logic đánh giá trạng thái Freshness có nhiều cơ chế tính toán khác nhau tùy theo nguồn dữ liệu (Database timestamp, Redis TTL, GitHub API timestamp).

#### Problem solved:
- Loại bỏ các khối `switch-case` hoặc `if-else` khổng lồ trong các service chính.
- Dễ dàng thay đổi chiến lược tìm kiếm hoặc kiểm tra sức khỏe hệ thống khi chạy qua file cấu hình `.env`.

#### Before:
```php
// FreshnessService.php chứa logic tính toán thời gian và phân nhánh cứng
if ($diff > $collectorWarning) {
    $status = 'STALE';
    $reason = "...";
}
```

#### After:
```php
interface FreshnessEvaluatorInterface
{
    public function evaluate(mixed $source, array $thresholds = []): array;
}

class CollectorFreshnessEvaluator implements FreshnessEvaluatorInterface
{
    public function evaluate(mixed $source, array $thresholds = []): array
    {
        // Encapsulated evaluation strategy
    }
}
```

#### Trade-off:
- Cần định nghĩa Interface rõ ràng cho các Strategy. Rất dễ mở rộng thêm các Evaluator mới (như DatabaseFreshnessEvaluator, ExternalApiFreshnessEvaluator).

---

### 2.5. Adapter Pattern

#### Where used:
- `App\Services\Cicd\Contracts\GitHubApiClientInterface` (Target Interface)
- `App\Services\Cicd\Adapters\GitHubApiAdapter` (Adapter Class)
- `App\Services\Cicd\GitHubActionsService` (Client)

#### Why used:
- `GitHubActionsService` trước đây gọi trực tiếp `Http::withToken(...)->get(...)` tới GitHub REST API v3.
- Nếu GitHub đổi URL endpoint, schema API, hoặc cần đổi sang SDK chính thức / Mock Server, toàn bộ business service sẽ bị ảnh hưởng.

#### Problem solved:
- Cô lập chi tiết HTTP Transport ra khỏi nghiệp vụ quản lý CI/CD Pipeline.
- Cho phép Unit Test `GitHubActionsService` mà không cần gọi API thật ra Internet hoặc mock HTTP facade ở mức global.

#### Before:
```php
// GitHubActionsService phụ thuộc trực tiếp vào HTTP Client và endpoint cứng
$response = Http::withToken($token)->get("https://api.github.com/repos/{$owner}/{$repo}/actions/workflows");
return $response->json();
```

#### After:
```php
interface GitHubApiClientInterface
{
    public function getWorkflows(): array;
    public function getWorkflowRuns(array $params = []): array;
    public function dispatchWorkflow(string $workflowId, string $ref, array $inputs = []): bool;
}

class GitHubApiAdapter implements GitHubApiClientInterface
{
    public function __construct(protected ?string $token = null, ...) {}
    // Adapter implementation handling HTTP details
}
```

#### Trade-off:
- Cần viết adapter map dữ liệu. Đảm bảo tuân thủ nghiêm ngặt nguyên lý Dependency Inversion Principle (DIP).

---

### 2.6. Observer & Event / Listener Pattern

#### Where used:
- Domain Events:
  - `App\Events\AmenityCreated`
  - `App\Events\AmenityUpdated`
  - `App\Events\AmenityDeleted`
- Listeners:
  - `App\Listeners\InvalidateAmenityCacheListener`

#### Why used:
- Khi một tiện ích (Amenity) được tạo mới, cập nhật hoặc xóa, có rất nhiều side effects cần thực thi:
  1. Xóa cache Redis / RAM (`amenityCache`).
  2. Ghi Audit Log.
  3. Re-index dữ liệu tìm kiếm (Search Indexing).
  4. Gửi thông báo đến cư dân quan tâm.
- Việc nhét toàn bộ các tác vụ này vào trong `AmenityService` làm phình to service, vi phạm SRP và làm chậm response time của API.

#### Problem solved:
- Hoàn toàn tách rời hành động chính (ghi dữ liệu nghiệp vụ) và các tác vụ phụ (side effects).
- Có thể chuyển các listener sang chế độ bất đồng bộ (`ShouldQueue`) khi lưu lượng tải tăng mà không ảnh hưởng tới logic nghiệp vụ.

#### Before:
```php
// AmenityService.php phải nhớ gọi xóa cache và re-index trực tiếp
$amenity->update($data);
Cache::forget("amenity:{$id}");
Cache::forget('amenities:list');
$this->searchService->reindex($amenity);
```

#### After:
```php
// AmenityService.php chỉ dispatch domain event
$amenity = $this->repository->update($id, $data);
event(new AmenityUpdated($amenity));
return $amenity;

// InvalidateAmenityCacheListener tự động lắng nghe và dọn dẹp cache
class InvalidateAmenityCacheListener
{
    public function handle(AmenityUpdated|AmenityDeleted|AmenityCreated $event): void
    {
        Cache::tags(['amenities'])->flush();
    }
}
```

#### Trade-off:
- Luồng thực thi chuyển sang dạng Event-driven, cần xem `EventServiceProvider` hoặc `AppServiceProvider` để kiểm tra các Listener đang đăng ký.

---

### 2.7. Frontend Design Patterns (React 19 & TypeScript)

#### Where used:
- `resources/js/Hooks/usePagination.ts`
- `resources/js/Hooks/useModalState.ts`
- `resources/js/Hooks/useSearch.ts`
- `resources/js/Hooks/useFreshness.ts`
- `resources/js/Hooks/usePermission.ts`

#### Why used:
- Các Page Component lớn như `AmenityManagement.tsx` và `CicdDashboard.tsx` có hơn 1500 dòng code, trộn lẫn:
  - State quản lý mở/đóng Modal (`isOpen`, `editItem`, `closeModal`).
  - State phân trang (`page`, `limit`, `totalPages`, thuật toán render danh sách số trang có dấu ba chấm `...`).
  - Search input debouncing, xử lý Race condition với `AbortController`.
  - Polling định kỳ dữ liệu độ tươi mới (Freshness) và xử lý lỗi.

#### Problem solved:
- Tách biệt hoàn toàn Business/State Logic ra khỏi Presentation Layer (Container/Presentational separation).
- Giúp các logic cốt lõi như Phân trang, Tìm kiếm chống Race-condition, Điều khiển Modal có thể tái sử dụng 100% ở bất kỳ màn hình nào trong toàn hệ thống.

---

## 3. BẢNG TỔNG HỢP MATRIX PATTERNS

| Module / Lớp | Pattern hiện có | Pattern áp dụng mới | Lý do kỹ thuật | Trạng thái |
| :--- | :--- | :--- | :--- | :--- |
| **Cư dân (Residents)** | Eloquent Query trực tiếp | **Repository Pattern** + **DTO Pattern** | Tách data access, type-safe filter, tối ưu hóa concurrency locking | **HOÀN THÀNH** |
| **Tiện ích (Amenities)** | Service CRUD thông thường | **Repository** + **DTO** + **Event/Listener** | Tách cache invalidation thành event side-effect, chuẩn hóa truy vấn | **HOÀN THÀNH** |
| **Tìm kiếm (Search)** | Strategy (Drivers) | **Factory Pattern** (`SearchDriverFactory`) | Chuẩn hóa khởi tạo Driver, bảo toàn Strategy và loại bỏ switch cứng | **HOÀN THÀNH** |
| **CI/CD Integrations** | Direct HTTP Calls | **Adapter Pattern** (`GitHubApiAdapter`) | Trừu tượng hóa kết nối HTTP tới GitHub, tăng testability | **HOÀN THÀNH** |
| **Freshness Observability**| Conditional branch | **Strategy Pattern** (`CollectorFreshnessEvaluator`)| Module hóa thuật toán tính toán độ trễ số liệu giám sát | **HOÀN THÀNH** |
| **Frontend UI/Logic** | Monolithic State | **Custom Hooks** (`usePagination`, `useModalState`, `useSearch`, `useFreshness`) | Tách logic phân trang, modal, debounce tìm kiếm khỏi render JSX | **HOÀN THÀNH** |

---

## 4. PATTERN CỐ TÌNH KHÔNG SỬ DỤNG (TRÁNH OVER-ENGINEERING)

Tuân thủ nghiêm ngặt nguyên tắc **YAGNI (You Aren't Gonna Need It)** và chỉ dẫn dự án:
1. **Không tạo Repository cho tất cả các Model CRUD đơn giản**: Các model danh mục như `Block`, `Floor`, `Role` là các bảng tra cứu cấu hình tĩnh, việc tạo Repository cho chúng là dư thừa và làm chậm quá trình phát triển mà không mang lại giá trị.
2. **Không áp dụng CQRS / Event Sourcing phức tạp**: Ứng dụng hiện tại có tải trọng REST API tiêu chuẩn, không cần tách riêng Write DB và Read DB để tránh tăng chi phí hạ tầng và độ trễ đồng bộ (eventual consistency).
3. **Không tạo Specification Hierarchy tầng tầng lớp lớp**: Các Eloquent Scope và Repository Filter DTO đã giải quyết trọn vẹn việc tìm kiếm lọc động mà vẫn giữ code gọn gàng, dễ đọc.
4. **Không biến Facade thành God Class**: Giữ các service đơn nhiệm, chỉ dùng Service Container Dependency Injection chuẩn mực của Laravel 12.
