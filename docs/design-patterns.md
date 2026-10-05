# TÀI LIỆU THIẾT KẾ VÀ ÁP DỤNG DESIGN PATTERNS

Dự án: **UMISORA09/QUANLY_TOANHA-DANCU**  
Mục tiêu kiến trúc: Triển khai hoàn chỉnh kiến trúc **Design Pattern + SOLID + Dependency Injection** cho toàn bộ dự án dựa trên code thực tế hiện tại; giảm coupling, tăng cohesion, testability, maintainability, extensibility; loại bỏ God Class / God Service; tách data access và external integration khỏi business logic; chuẩn hóa side effects; và bảo toàn 100% behavior hiện tại.

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
        ├─────────────────────────────┬─────────────────────────────┐
        ▼                             ▼                             ▼
Domain Logic / Events             DTOs (Type-safe Transport)     External Adapters
        │ (Events & Listeners)                                     │ (GitHub / Vercel API)
        ▼                                                          ▼
Repository Interface (Contract)                                External Web APIs
        │ (Decoupled Data Access Abstraction)
        ▼
Repository Implementation (Eloquent / Query Builder)
        │ (Query Scopes, Relationship Loading, Eager Loading)
        ▼
Database & Cache (MySQL 8 / Redis)
```

### 1.2. Frontend Layers (React 19 / TypeScript / Vite 8)
```text
App Shell (app.tsx)
        │ (Code Splitting with React.lazy, Suspense, Error Boundaries)
        ├───────────────────────────────┬───────────────────────────────┐
        ▼                               ▼                               ▼
NavigationService               AuthService & roleResolver           useAuth Hook
(Route Matching & Protection)   (Session & Role Strategy)           (Auth State)
        │                               │                               │
        └───────────────────────────────┼───────────────────────────────┘
                                        ▼
                              Feature Pages & Containers
                                        │
                                        ▼
                              Custom Hooks Layer
                (useSearch, usePagination, useModalState, useFreshness, usePermission)
                                        │
                                        ▼
                              Presentational Components & SCSS
                        (custom.scss, _variables, _mixins, _animations)
                                        │
                                        ▼
                              API Service Adapters (api.ts, cicdApi.ts, amenityCache.ts)
```

---

## 2. CATALOG DESIGN PATTERNS ĐÃ ÁP DỤNG

### 2.1. Repository Pattern

#### Where used:
- Module Quản lý Cư dân:
  - Contract: [ResidentRepositoryInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Contracts/ResidentRepositoryInterface.php)
  - Concrete: [EloquentResidentRepository.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Eloquent/EloquentResidentRepository.php)
- Module Tiện ích Tòa nhà:
  - Contract: [AmenityRepositoryInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Contracts/AmenityRepositoryInterface.php)
  - Concrete: [DatabaseAmenityRepository.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Eloquent/DatabaseAmenityRepository.php)

#### Why used:
- `AmenityService` và `ResidentService` trước đây vừa quản lý business flow, vừa trực tiếp gọi `DB::table(...)`, Eloquent query builder, join bảng phức tạp, tính toán aggregate queries và xử lý database locking.
- Cần trừu tượng hóa data access layer để mock trong Unit Tests mà không cần database vật lý.

#### Problem solved:
- Phá vỡ sự phụ thuộc trực tiếp (tight coupling) giữa Service và Database.
- Toàn bộ query phức tạp (`getAmenityDetail`, `getSlotCountsForAmenities`, `getActiveBookingCountsForAmenities`, `getAmenityBookingsWithDetails`, `isCodeExists`, `getPeakBookings`) được đóng gói trong `DatabaseAmenityRepository`.
- `AmenityService` thuần túy là orchestration layer, không còn dòng `DB::table(...)` nào.

#### Before:
```php
// AmenityService.php trực tiếp gọi DB::table
$slotCounts = DB::table('amenity_slots')
    ->select('amenity_id', DB::raw('count(*) as aggregate'))
    ->whereIn('amenity_id', $amenityIds)
    ->groupBy('amenity_id')
    ->pluck('aggregate', 'amenity_id');
```

#### After:
```php
// AmenityService.php phụ thuộc AmenityRepositoryInterface qua Constructor Injection
public function __construct(
    protected SearchManager $searchManager,
    protected AmenityRepositoryInterface $amenityRepository
) {}

// Truy vấn thông qua repository contract:
$slotCounts = $this->amenityRepository->getSlotCountsForAmenities($amenityIds);
```

#### Trade-off:
- Thêm interface và implementation class.
- Lợi ích: tách biệt hoàn toàn data access khỏi business logic, testability đạt 100%.

---

### 2.2. Dependency Injection (DI) & Inversion of Control (IoC)

#### Where used:
- [AppServiceProvider.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Providers/AppServiceProvider.php)
- `AmenityService`, `ResidentService`, `SearchManager`, `GitHubActionsService`, `FreshnessService`.

#### Why used:
- Loại bỏ toàn bộ việc `new ClassName()` trực tiếp trong business layer (ví dụ: `new SearchManager()`, `new SearchDriverFactory()`, `new SmartSearchDriver()`).
- Đưa quyền kiểm soát vòng đời và instantiation sang Laravel Service Container.

#### Before:
```php
public function __construct(?SearchManager $searchManager = null)
{
    $this->searchManager = $searchManager ?: new SearchManager();
}
```

#### After:
```php
public function __construct(
    protected SearchManager $searchManager,
    protected AmenityRepositoryInterface $amenityRepository
) {}
```

---

### 2.3. Factory Pattern

#### Where used:
- [SearchDriverFactory.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Search/Factories/SearchDriverFactory.php)

#### Why used:
- `SearchManager` không nên tự phân nhánh và tự `new` các concrete search driver.
- Cần tuân thủ Single Responsibility Principle (SRP) và Open/Closed Principle (OCP): thêm Search Engine mới chỉ cần mở rộng Factory.

#### Before:
```php
// SearchManager tự new concrete drivers
if ($driver === 'smart') {
    return new SmartSearchDriver($config);
}
```

#### After:
```php
public function __construct(
    protected SearchDriverFactory $factory
) {}

public function driver(?string $name = null): SearchDriverInterface
{
    $driverName = $name ?: config('search.default', 'smart');
    if (! isset($this->drivers[$driverName])) {
        $this->drivers[$driverName] = $this->factory->make($driverName, config("search.drivers.{$driverName}", []));
    }
    return $this->drivers[$driverName];
}
```

---

### 2.4. Strategy Pattern

#### Where used:
1. **Search Subsystem**:
   - Contract: `SearchDriverInterface`
   - Concrete: `SmartSearchDriver` (MySQL / Smart Algorithm), `MeilisearchDriver`, `ElasticsearchDriver`.
2. **Freshness Observability Subsystem**:
   - Contract: [FreshnessSourceStrategyInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Contracts/FreshnessSourceStrategyInterface.php)
   - Registry: [FreshnessStrategyRegistry.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/FreshnessStrategyRegistry.php)
   - Concrete Strategies:
     - [CollectorFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/CollectorFreshnessStrategy.php) (Thu thập và giám sát Collector heartbeat)
     - [GitHubFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/GitHubFreshnessStrategy.php) (Giám sát CI/CD runs & GitHub API)
     - [DeploymentFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/DeploymentFreshnessStrategy.php) (Giám sát phiên bản triển khai Vercel / GitHub)
     - [ApplicationHealthStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/ApplicationHealthStrategy.php) (Giám sát observation từ /health probe)
     - [DatabaseFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/DatabaseFreshnessStrategy.php) (Giám sát độ tươi mới dữ liệu bảng CSDL)

#### Why used:
- `FreshnessService` trước đây là một God Service chứa hơn 740 dòng code, trực tiếp xử lý HTTP, query database, tính toán timestamp cho 5 nguồn khác nhau.
- Tách mỗi nguồn giám sát thành một Strategy độc lập triển khai `FreshnessSourceStrategyInterface`, cho phép `FreshnessService` chỉ đóng vai trò Orchestration & Aggregation.

#### Before:
```php
// FreshnessService.php chứa toàn bộ logic truy vấn CSDL, gọi GitHub API, kiểm tra health probe
public function evaluateGitHubFreshness(Carbon $now, bool $force = false): array { ... 120 lines ... }
public function evaluateHealthFreshness(Carbon $now): array { ... 100 lines ... }
public function evaluateDatabaseFreshness(Carbon $now, bool $force = false): array { ... 180 lines ... }
```

#### After:
```php
// FreshnessService.php thuần túy delegate cho Strategy tương ứng qua Registry
public function evaluateGitHubFreshness(Carbon $now, bool $force = false): array
{
    return $this->registry->for('github_actions')->evaluate($now, $force);
}

public function evaluateHealthFreshness(Carbon $now): array
{
    return $this->registry->for('application_health')->evaluate($now);
}

public function evaluateDatabaseFreshness(Carbon $now, bool $force = false): array
{
    return $this->registry->for('database')->evaluate($now, $force);
}
```

---

### 2.5. Adapter Pattern

#### Where used:
1. **GitHub API Adapter**:
   - Contract: `GitHubApiClientInterface`
   - Adapter: `GitHubApiAdapter`
   - Client: `GitHubActionsService`
2. **Vercel API Adapter**:
   - Contract: [VercelApiClientInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Contracts/VercelApiClientInterface.php)
   - Adapter: [VercelApiAdapter.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Adapters/VercelApiAdapter.php)
   - Client: `GitHubActionsService`

#### Why used:
- `GitHubActionsService` trước đây gọi trực tiếp HTTP client (`Http::withToken(...)`) ra Internet tại các endpoint của GitHub và Vercel.
- Cần cô lập các chi tiết HTTP transport (timeout, token header, response normalization) vào trong Adapter, giúp business service hoàn toàn độc lập và dễ dàng test/mock.

#### Before:
```php
// GitHubActionsService trực tiếp gọi Http tới Vercel
$response = Http::withToken($token)->timeout(10)->get("https://api.vercel.com/v6/deployments", [...]);
```

#### After:
```php
// GitHubActionsService nhận VercelApiClientInterface qua Constructor Injection
public function __construct(
    protected GitHubApiClientInterface $client,
    protected ?VercelApiClientInterface $vercelClient = null
) {}

$deployments = $this->getVercelClient()->getDeployments($projectId, $teamId, 10);
```

---

### 2.6. Data Transfer Object (DTO) Pattern

#### Where used:
- `App\DTOs\SearchQueryDTO`: Chuẩn hóa tham số truy vấn tìm kiếm.
- `App\DTOs\ResidentFilterDTO`: Chuẩn hóa bộ lọc, sắp xếp, tìm kiếm cư dân và căn hộ.
- `App\DTOs\AmenityFilterDTO`: Chuẩn hóa bộ lọc danh mục, tòa block, trạng thái và phân trang tiện ích.

#### Why used:
- Đảm bảo type safety, immutability (`final readonly class`), cung cấp factory method `fromArray()` và `toArray()`.
- Ngăn ngừa lỗi runtime do truy cập sai key array hoặc truyền sai kiểu dữ liệu.

---

### 2.7. Observer & Domain Event / Listener Pattern

#### Where used:
- Domain Events:
  - `App\Events\AmenityCreated`
  - `App\Events\AmenityUpdated`
  - `App\Events\AmenityDeleted`
- Listeners:
  - `App\Listeners\InvalidateAmenityCacheListener`

#### Why used:
- Tách biệt side effect (xóa cache Redis / RAM, tăng version cache) ra khỏi nghiệp vụ lưu trữ tiện ích.
- `AmenityService` chỉ tập trung vào transaction và logic nghiệp vụ, dispatch Event để các listener tự xử lý side effect.

---

### 2.8. Frontend Architecture Patterns (React 19 & TypeScript)

#### Where used:
- [roleResolver.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Auth/roleResolver.ts): Chuẩn hóa role và xác định default route.
- [authService.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Auth/authService.ts): Quản lý lưu trữ và vòng đời session.
- [navigationService.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Routing/navigationService.ts): Nhận diện route category và áp dụng quy tắc bảo vệ phân quyền.
- [useAuth.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Hooks/useAuth.ts): Custom hook quản lý auth state.
- [app.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/app.tsx): Tái cấu trúc thành AppShell / Router tinh gọn.

#### Why used:
- `app.tsx` ban đầu là một God Component chứa đồng thời logic authentication, session parsing, role normalization, URL route matching, access control và rendering.
- Tách thành các Domain service chuyên trách giúp `app.tsx` giảm đáng kể độ phức tạp, trong khi vẫn giữ nguyên 100% routing semantics và bảo toàn hoàn toàn tệp `resources/css/scss/custom.scss`.

---

## 3. BẢNG TỔNG HỢP MATRIX PATTERNS

| Module | Before | Pattern | After | Benefit | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Tiện ích (Amenities)** | Service gọi DB::table trực tiếp, tự new SearchManager | **Repository** + **DI** + **Event/Listener** | `AmenityService` -> `AmenityRepositoryInterface` (`DatabaseAmenityRepository`) | Tách data access, không còn query trực tiếp trong Service, testability 100% | **PASS** |
| **Cư dân (Residents)** | Eloquent Query trực tiếp trong Service | **Repository** + **DTO** + **DI** | `ResidentService` -> `ResidentRepositoryInterface` (`EloquentResidentRepository`) | Tách data access, type-safe filter, tối ưu hóa concurrency locking | **PASS** |
| **Tìm kiếm (Search)** | `SearchManager` tự new drivers | **Strategy** + **Factory** + **DI** | `SearchDriverFactory` -> `SearchDriverInterface` (`Smart`, `Meili`, `Elastic`) | OCP: thêm search driver mới không sửa `SearchManager` | **PASS** |
| **External CI/CD & Deploy** | Trực tiếp gọi `Http::withToken` tới GitHub & Vercel | **Adapter Pattern** + **DI** | `GitHubApiClientInterface` + `VercelApiClientInterface` | Cô lập HTTP transport, mock dễ dàng trong Unit Tests | **PASS** |
| **Freshness Observability** | `FreshnessService` là God Service (740+ dòng) | **Strategy Pattern** + **Registry** + **DI** | `FreshnessStrategyRegistry` -> 5 strategies riêng biệt | Tách biệt các nguồn giám sát (Collector, GitHub, Deployment, Health, DB) | **PASS** |
| **Data Transfer** | Truyền mảng `$data`, `$params` lỏng lẻo | **DTO Pattern** | `SearchQueryDTO`, `AmenityFilterDTO`, `ResidentFilterDTO` | Type safety, immutability, ngăn ngừa lỗi typo runtime | **PASS** |
| **Domain Side Effects** | Invalidate cache trực tiếp trong Service | **Event / Listener** (Observer) | `AmenityCreated`, `AmenityUpdated`, `AmenityDeleted` -> `InvalidateAmenityCacheListener` | Tách rời tác vụ phụ khỏi transaction nghiệp vụ | **PASS** |
| **Frontend Shell** | `app.tsx` là God Component (auth, routing, render) | **Domain Service** + **Custom Hook** + **Router Shell** | `roleResolver`, `authService`, `navigationService`, `useAuth`, `AppShell` | Cohesive, tách biệt state & presentation, bảo tồn SCSS | **PASS** |

---

## 4. PATTERN CỐ TÌNH KHÔNG SỬ DỤNG (TRÁNH OVER-ENGINEERING)

Tuân thủ nghiêm ngặt nguyên tắc **YAGNI (You Aren't Gonna Need It)**:
1. **Không tạo Repository cho tất cả các Model CRUD đơn giản**: Các model danh mục như `Block`, `Floor`, `Role` là các bảng tra cứu cấu hình tĩnh, việc tạo Repository cho chúng là dư thừa.
2. **Không áp dụng CQRS / Event Sourcing phức tạp**: Ứng dụng hiện tại có tải trọng REST API tiêu chuẩn, không cần tách riêng Write DB và Read DB để tránh tăng chi phí hạ tầng và độ trễ đồng bộ.
3. **Không tạo Command Pattern cho các thao tác CRUD cơ bản**: Chỉ áp dụng cho các workflow nhiều bước (Rollback, Freshness probe), không áp dụng cho Create/Update/Delete đơn giản.
4. **Không tạo 10 class Strategy chỉ để thay vài nhánh role đơn giản trên Frontend**: Áp dụng configuration & object mapping trong `roleResolver.ts` tinh gọn và hiệu quả hơn class hierarchy.
