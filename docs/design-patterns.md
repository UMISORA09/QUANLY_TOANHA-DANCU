# TÀI LIỆU THIẾT KẾ VÀ ÁP DỤNG DESIGN PATTERNS

Dự án: **UMISORA09/QUANLY_TOANHA-DANCU**  
Branch: `DangNguyen/Fix_all`  
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
        │ (Query Scopes, Relationship Loading, Concurrency Lock)
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
- **Module Quản lý Cư dân**:
  - Contract: [ResidentRepositoryInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Contracts/ResidentRepositoryInterface.php)
  - Concrete: [EloquentResidentRepository.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Eloquent/EloquentResidentRepository.php)
  - Service: [ResidentService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/ResidentService.php)
- **Module Tiện ích Tòa nhà**:
  - Contract: [AmenityRepositoryInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Contracts/AmenityRepositoryInterface.php)
  - Concrete: [DatabaseAmenityRepository.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Eloquent/DatabaseAmenityRepository.php)
  - Service: [AmenityService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/AmenityService.php)

#### Why used:
- `AmenityService` và `ResidentService` trước đây vừa quản lý business flow, vừa trực tiếp gọi `DB::table(...)`, Eloquent model query builder, join bảng phức tạp, concurrency locks (`lockForUpdate`), và optimistic updates.
- Cần cô lập tầng truy vấn CSDL để tách bạch hoàn toàn trách nhiệm nghiệp vụ và dữ liệu, phục vụ unit testing không phụ thuộc CSDL vật lý.

#### Problem solved:
- Phá vỡ sự phụ thuộc trực tiếp (tight coupling) giữa Service và Eloquent Models/Database.
- Trong `ResidentService`:
  - Toàn bộ data access (`findById`, `getActiveHouseholdMembers`, `findByUserAndApartment`, `findHouseholdHead`, `findWithTrashed`, `findAndLockForUpdate`, `findActiveHouseholdHeadExcluding`, `updateOptimistic`, `loadRelations`, `getApartmentsForFilter`) được đưa vào `ResidentRepositoryInterface` và triển khai trong `EloquentResidentRepository`.
  - Service giữ lại trọn vẹn: boundary transaction `DB::transaction(...)`, validation logic, quy tắc "một căn hộ chỉ có duy nhất một chủ hộ active", xử lý race condition và xung đột concurrency 409 (`ResidentConflictException`).
- Trong `AmenityService`:
  - Mọi query phức tạp (`getAmenityDetail`, `getSlotCountsForAmenities`, `getActiveBookingCountsForAmenities`, `getAmenityBookingsWithDetails`, `isCodeExists`, `getPeakBookings`) nằm gọn trong `DatabaseAmenityRepository`.

#### Before (`ResidentService.php`):
```php
// Trực tiếp gọi Eloquent query builder và locking trong Service:
$lockedResident = Resident::where('id', $id)->lockForUpdate()->first();
$otherHead = Resident::where('apartment_id', $lockedResident->apartment_id)
    ->where('id', '!=', $lockedResident->id)
    ->where('is_head_of_household', 1)
    ->where('is_active', 1)
    ->lockForUpdate()
    ->first();
$affected = Resident::where('id', $lockedResident->id)
    ->where('updated_at', $currentUpdatedAt)
    ->update(array_merge($data, ['updated_at' => $newUpdatedAt]));
```

#### After (`ResidentService.php`):
```php
// Toàn bộ data access đi qua ResidentRepositoryInterface:
$lockedResident = $this->repository->findAndLockForUpdate($id);
$otherHead = $this->repository->findActiveHouseholdHeadExcluding($lockedResident->apartment_id, $lockedResident->id, true);
$affected = $this->repository->updateOptimistic($lockedResident->id, $currentUpdatedAt, array_merge($data, ['updated_at' => $newUpdatedAt]));
```

---

### 2.2. Adapter Pattern

#### Where used:
1. **GitHub API Adapter**:
   - Contract: [GitHubApiClientInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Contracts/GitHubApiClientInterface.php)
   - Adapter: [GitHubApiAdapter.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Adapters/GitHubApiAdapter.php)
   - Client / Consumer: [GitHubActionsService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/GitHubActionsService.php)
2. **Vercel API Adapter**:
   - Contract: [VercelApiClientInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Contracts/VercelApiClientInterface.php)
   - Adapter: [VercelApiAdapter.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/Adapters/VercelApiAdapter.php)
   - Client / Consumer: [GitHubActionsService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/GitHubActionsService.php)

#### Why used:
- `GitHubActionsService` trước đây gọi trực tiếp `Http::withToken(...)`, `Http::pool(...)`, `Http::timeout(...)` tới GitHub API và Vercel API tại 16 điểm khác nhau trong file.
- Vi phạm Single Responsibility Principle (Service vừa xử lý logic tính toán CI/CD metrics, vừa quản lý HTTP transport, timeouts, tokens, pool handling).
- Làm giảm khả năng viết Unit Test do phải mock toàn cục `Http::fake` thay vì mock interface client.

#### Capabilities đã được Adapter hóa:
- Workflow runs có status & error normalization: `getWorkflowRunsWithStatus`
- Chi tiết jobs của pipeline: `getWorkflowRunJobs`
- Pipeline logs: `getWorkflowRunLogs`
- Chạy workflow cụ thể: `getWorkflowRunsByWorkflow`
- Deployments kèm statuses lấy song song qua HTTP Pool: `getDeploymentsWithStatuses`
- Git commits fallback: `getCommits`
- Git branches fallback: `getBranches`
- Default branch: `getDefaultBranch` / `getRepositoryInfo`
- Trigger workflow dispatch có error details: `dispatchWorkflowExtended`
- Retry workflow run: `retryWorkflowRun`
- Cancel workflow run: `cancelWorkflowRun`
- Health probe endpoints: `probeEndpoint` (cho Meilisearch, Elasticsearch, Web health)
- Vercel deployments & live URL health probe: `getDeployments`, `probeDeploymentHealth`

#### Before:
```php
// GitHubActionsService.php trực tiếp gọi Laravel Http facade:
$response = Http::withToken($this->token)
    ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
    ->timeout(4.0)
    ->get("{$this->apiBase}/actions/runs", $queryParams);

$poolResponses = Http::pool(function ($pool) use ($topDeps) { ... });
```

#### After:
```php
// GitHubActionsService.php không còn dòng Http:: nào; hoàn toàn delegate cho adapter:
$res = $this->apiClient->getWorkflowRunsWithStatus($queryParams);
$rawDeployments = $this->apiClient->getDeploymentsWithStatuses(10);
$jobs = $this->apiClient->getWorkflowRunJobs($id);
$body = $this->apiClient->getWorkflowRunLogs($id, $jobId);
```

---

### 2.3. Dependency Injection (DI) & Inversion of Control (IoC)

#### Where used:
- [AppServiceProvider.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Providers/AppServiceProvider.php)
- Toàn bộ Service Layer: `GitHubActionsService`, `FreshnessService`, `ResidentService`, `AmenityService`, `SearchManager`.
- Controller Layer: `AmenityController`, `ResidentController`, `DevOpsApiController`, `FreshnessApiController`, `AuthController`.

#### Problem solved:
- **Xóa bỏ hoàn toàn Service Locator trong Business Services**:
  - `GitHubActionsService` trước đây gọi `app(SearchManager::class)` -> Nay được constructor injection: `protected SearchManager $searchManager`.
  - `FreshnessService` trước đây có fallback `buildDefaultRegistry()` và tự `new` 5 strategies -> Nay được container inject tập trung: `FreshnessStrategyRegistry $registry`.
  - Mọi Controller nhận dependencies qua constructor, không resolve động `app(...)`.

#### DI Registrations in `AppServiceProvider`:
```php
// 1. Search Subsystem
$this->app->singleton(SearchDriverFactory::class, fn () => new SearchDriverFactory);
$this->app->singleton(SearchManager::class, fn ($app) => new SearchManager($app->make(SearchDriverFactory::class)));

// 2. Repositories
$this->app->bind(ResidentRepositoryInterface::class, EloquentResidentRepository::class);
$this->app->bind(AmenityRepositoryInterface::class, DatabaseAmenityRepository::class);

// 3. External API Adapters
$this->app->bind(GitHubApiClientInterface::class, GitHubApiAdapter::class);
$this->app->bind(VercelApiClientInterface::class, VercelApiAdapter::class);

// 4. Freshness Subsystem (Single Source of Truth)
$this->app->singleton(FreshnessStrategyRegistry::class, function ($app) {
    $config = config('freshness', []);
    $cicd = $app->make(GitHubActionsService::class);

    return new FreshnessStrategyRegistry([
        new CollectorFreshnessStrategy($config),
        new GitHubFreshnessStrategy($cicd, $config),
        new DeploymentFreshnessStrategy($cicd, $config),
        new ApplicationHealthStrategy($config),
        new DatabaseFreshnessStrategy($config),
    ]);
});
```

---

### 2.4. Factory Pattern

#### Where used:
- [SearchDriverFactory.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Search/Factories/SearchDriverFactory.php)

#### Why used:
- `SearchManager` không tự phân nhánh cấu hình và không tự `new` các concrete driver.
- Tuân thủ Open/Closed Principle (OCP): Khi bổ sung Search Driver mới (ví dụ: OpenSearch), chỉ cần bổ sung Driver và mở rộng case trong `SearchDriverFactory` mà không sửa logic điều phối trong `SearchManager`.

#### Implementation:
```php
class SearchDriverFactory
{
    public function make(string $name, array $config = []): SearchDriverInterface
    {
        return match ($name) {
            'smart', 'database', 'ponytail' => new SmartSearchDriver($config),
            'meilisearch' => new MeilisearchDriver($config),
            'elasticsearch' => new ElasticsearchDriver($config),
            default => throw new InvalidArgumentException("Search driver [{$name}] is not supported."),
        };
    }
}
```

---

### 2.5. Strategy Pattern

#### Where used:
1. **Search Subsystem**:
   - Contract: `SearchDriverInterface`
   - Concrete Strategies: `SmartSearchDriver` (MySQL / Smart Algorithm), `MeilisearchDriver`, `ElasticsearchDriver`.
2. **Freshness Observability Subsystem**:
   - Contract: [FreshnessSourceStrategyInterface.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Contracts/FreshnessSourceStrategyInterface.php)
   - Registry: [FreshnessStrategyRegistry.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/FreshnessStrategyRegistry.php)
   - Concrete Strategies:
     - [CollectorFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/CollectorFreshnessStrategy.php) (Giám sát Collector heartbeat)
     - [GitHubFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/GitHubFreshnessStrategy.php) (Giám sát CI/CD runs & GitHub API)
     - [DeploymentFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/DeploymentFreshnessStrategy.php) (Giám sát phiên bản triển khai Vercel / GitHub)
     - [ApplicationHealthStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/ApplicationHealthStrategy.php) (Giám sát observation từ /health probe)
     - [DatabaseFreshnessStrategy.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Freshness/Strategies/DatabaseFreshnessStrategy.php) (Giám sát độ tươi mới dữ liệu bảng CSDL)

#### Enforced Container-Managed Strategy Injection:
- `FreshnessService` chỉ chịu trách nhiệm orchestrate, aggregate, derive overall state, và track incidents.
- Không tồn tại method `buildDefaultRegistry()` hay bất kỳ `new Strategy(...)` nào trong `FreshnessService`.
- Registry hỗ trợ `bindCicdService()` để liên kết instance `GitHubActionsService` (cả mock và real) một cách trong suốt.

---

### 2.6. Data Transfer Object (DTO) Pattern

#### Where used:
- `App\DTOs\SearchQueryDTO`: Chuẩn hóa tham số truy vấn tìm kiếm (query, filters, limit, sort).
- `App\DTOs\ResidentFilterDTO`: Chuẩn hóa bộ lọc, sắp xếp, tìm kiếm cư dân và căn hộ.
- `App\DTOs\AmenityFilterDTO`: Chuẩn hóa bộ lọc danh mục, tòa block, trạng thái và phân trang tiện ích.

#### Why used:
- Đảm bảo type safety, immutability (`final readonly class`), cung cấp factory method `fromArray()` và `toArray()`.
- Ngăn ngừa lỗi runtime do truy cập sai key array hoặc truyền sai kiểu dữ liệu giữa Controller, Service và Repository.

---

### 2.7. Observer & Domain Event / Listener Pattern

#### Where used:
- Domain Events:
  - `App\Events\AmenityCreated`
  - `App\Events\AmenityUpdated`
  - `App\Events\AmenityDeleted`
- Listeners:
  - `App\Listeners\InvalidateAmenityCacheListener`

#### Problem solved:
- **Chuẩn hóa Side Effects & Xóa bỏ Duplicate Invalidation**:
  - Trước đây, khi tạo/sửa/xóa tiện ích, cả `AmenityService` dispatch Event và `AmenityController` đều gọi `$this->bumpDataVersion()`, dẫn tới phiên bản dữ liệu bị increment 2 lần và cache bị invalidate 2 lần.
  - Sau refactor: Toàn bộ side effect (tăng `amenities_data_version`, xóa cache Redis / Memory qua `SearchCacheService::invalidate()`) được chuyển tập trung về `InvalidateAmenityCacheListener`. Controller chỉ nhận kết quả và trả về HTTP JSON response.

---

### 2.8. Frontend Architecture Patterns (React 19 & TypeScript)

#### Where used:
- [roleResolver.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Auth/roleResolver.ts): Chuẩn hóa role và xác định default route.
- [authService.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Auth/authService.ts): Quản lý lưu trữ và vòng đời session.
- [navigationService.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Domain/Routing/navigationService.ts): Nhận diện route category và áp dụng quy tắc bảo vệ phân quyền.
- [useAuth.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Hooks/useAuth.ts): Custom hook quản lý auth state.
- [app.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/app.tsx): Tái cấu trúc thành AppShell / Router tinh gọn.

#### Architecture:
```text
App Shell (app.tsx)
      ↓
Navigation & Auth Orchestration (navigationService, roleResolver, authService)
      ↓
Hooks & Domain Services (useAuth, useResidentFilter, useAmenityFilter)
      ↓
API Services (residentService, amenityService, cicdService)
      ↓
Presentational Components & Pages
```
- Không biến `app.tsx` thành God Component.
- Không tạo Strategy class phức tạp chỉ cho việc ánh xạ role đơn giản (sử dụng configuration & dictionary mapping tinh gọn, hiệu quả).

---

## 3. BẢNG TỔNG HỢP MATRIX PATTERNS

| Pattern | Module Áp Dụng | Abstraction / Contract | Implementation | Benefit Đạt Được | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Adapter** | CI/CD & Deploy | `GitHubApiClientInterface`, `VercelApiClientInterface` | `GitHubApiAdapter`, `VercelApiAdapter` | Loại bỏ 100% direct `Http::` khỏi business service, cô lập HTTP transport | **PASS** |
| **Repository** | Cư dân & Tiện ích | `ResidentRepositoryInterface`, `AmenityRepositoryInterface` | `EloquentResidentRepository`, `DatabaseAmenityRepository` | Tách hoàn toàn data access khỏi Service, hỗ trợ concurrency locks | **PASS** |
| **Strategy** | Tìm kiếm & Freshness | `SearchDriverInterface`, `FreshnessSourceStrategyInterface` | `SmartSearchDriver`, `MeilisearchDriver`, `CollectorFreshnessStrategy`, ... | OCP: dễ mở rộng driver/nguồn quan sát mới mà không sửa core service | **PASS** |
| **Factory** | Tìm kiếm | `SearchDriverFactory` | `SearchDriverFactory::make()` | `SearchManager` không tự `new` concrete driver, tuân thủ SRP/OCP | **PASS** |
| **DTO** | Request & Filters | `ResidentFilterDTO`, `AmenityFilterDTO`, `SearchQueryDTO` | `final readonly class` with `fromArray()` | Type safety, immutability, loại bỏ lỗi typo mảng lỏng lẻo | **PASS** |
| **Event / Listener** | Tiện ích Side Effects | `AmenityCreated`, `AmenityUpdated`, `AmenityDeleted` | `InvalidateAmenityCacheListener` | Chuẩn hóa side effects, loại bỏ duplicate cache invalidation | **PASS** |
| **Dependency Injection** | Toàn bộ ứng dụng | Laravel Service Container via `AppServiceProvider` | Constructor Injection trong toàn bộ Services & Controllers | Loại bỏ Service Locator, tăng 100% testability với mocks | **PASS** |
| **SOLID Principles** | Toàn bộ hệ thống | SRP, OCP, LSP, ISP, DIP | Codebase hoàn chỉnh | Giảm coupling, tăng cohesion, không có God Class | **PASS** |

---

## 4. KNOWN ARCHITECTURAL TRADE-OFFS (CỐ TÌNH KHÔNG SỬ DỤNG)

Tuân thủ nghiêm ngặt nguyên tắc **YAGNI (You Aren't Gonna Need It)** và tránh Over-engineering:
1. **Không tạo Generic / God Repository**: Không gom tất cả các model vào một "BaseRepository" khổng lồ. Mỗi repository chỉ phục vụ một bounded context cụ thể (`ResidentRepositoryInterface`, `AmenityRepositoryInterface`).
2. **Không tạo Repository cho tất cả các bảng từ điển/cấu hình tĩnh**: Các model danh mục đơn giản như `Block`, `Floor`, `Role` là các bảng tra cứu cấu hình, việc tạo Repository riêng cho chúng là abstraction dư thừa.
3. **Giữ Transaction Boundary ở Service Layer**: Không đẩy `DB::transaction(...)` vào Repository vì transaction cần bao trùm nhiều bước nghiệp vụ, validation và conflict checks trước khi commit.
4. **Không chia nhỏ God Interface nếu capability còn gắn kết**: `GitHubApiClientInterface` cung cấp đầy đủ các endpoint liên quan đến CI/CD runner và deployments; việc chia nhỏ thành 4 interface riêng biệt là không cần thiết ở quy mô hiện tại.
5. **Không áp dụng CQRS / Event Sourcing phức tạp**: Ứng dụng là hệ thống quản lý tòa nhà tiêu chuẩn với tải trọng REST API; không cần tách rời Read DB và Write DB để tránh tăng chi phí hạ tầng và độ trễ đồng bộ dữ liệu.
6. **Không tạo Strategy Class Hierarchy cho Role Mapping ở Frontend**: Configuration và dictionary mapping trong `roleResolver.ts` ngắn gọn, tường minh và dễ bảo trì hơn rất nhiều so với việc tạo 5 class Strategy riêng biệt cho từng role.
