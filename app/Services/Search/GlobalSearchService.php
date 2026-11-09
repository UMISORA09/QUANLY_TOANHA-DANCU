<?php

namespace App\Services\Search;

use App\Models\AccessCard;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRegistration;
use App\Services\RbacService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client as MeiliClient;
use Throwable;

class GlobalSearchService
{
    /**
     * Tất cả các loại đối tượng được hỗ trợ
     */
    public const SUPPORTED_TYPES = [
        'resident' => 'Cư dân & Nhân khẩu',
        'temporary_registration' => 'Tạm trú / Tạm vắng',
        'vehicle' => 'Phương tiện & Biển số',
        'access_card' => 'Thẻ RFID',
        'visitor' => 'Khách viếng thăm',
        'parcel' => 'Bưu phẩm giao nhận',
    ];

    protected ?MeiliClient $meiliClient = null;

    protected bool $meiliAvailable = false;

    protected ?string $meiliError = null;

    public function __construct()
    {
        $this->initMeilisearch();
    }

    /**
     * Khởi tạo kết nối tới Meilisearch
     */
    protected function initMeilisearch(): void
    {
        $host = config('scout.meilisearch.host', env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'));
        $key = config('scout.meilisearch.key', env('MEILISEARCH_KEY'));

        try {
            $this->meiliClient = new MeiliClient($host, $key);
            $health = $this->meiliClient->health();

            if (($health['status'] ?? '') === 'available') {
                $this->meiliAvailable = true;
                $this->meiliError = null;
            } else {
                $this->meiliAvailable = false;
                $this->meiliError = 'Meilisearch health status: '.($health['status'] ?? 'unknown');
            }
        } catch (Throwable $e) {
            $this->meiliAvailable = false;
            $this->meiliError = $e->getMessage();
        }
    }

    /**
     * Kiểm tra trạng thái sức khỏe của Search Engine
     *
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        $indexesStats = [];

        if ($this->meiliAvailable && $this->meiliClient) {
            try {
                $indexes = $this->meiliClient->getIndexes();
                foreach ($indexes->getResults() as $idx) {
                    $stats = $idx->stats();
                    $indexesStats[$idx->getUid()] = [
                        'numberOfDocuments' => $stats['numberOfDocuments'] ?? 0,
                        'isIndexing' => $stats['isIndexing'] ?? false,
                    ];
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return [
            'status' => $this->meiliAvailable ? 'healthy' : 'degraded',
            'engine' => 'meilisearch',
            'available' => $this->meiliAvailable,
            'error' => $this->meiliError,
            'indexes' => $indexesStats,
            'supported_types' => self::SUPPORTED_TYPES,
        ];
    }

    /**
     * Thực hiện tìm kiếm tổng thể đa phân hệ
     *
     * @param  array{
     *     q?: string,
     *     type?: string,
     *     status?: string,
     *     limit?: int,
     *     page?: int
     * }  $params
     * @return array<string, mixed>
     */
    public function search(User $user, array $params = []): array
    {
        $startTime = microtime(true);
        $rawQuery = trim((string) ($params['q'] ?? $params['query'] ?? ''));
        $typeFilter = trim((string) ($params['type'] ?? 'all'));
        $statusFilter = isset($params['status']) ? trim((string) $params['status']) : null;
        $limit = max(min((int) ($params['limit'] ?? 20), 50), 1);
        $page = max((int) ($params['page'] ?? 1), 1);

        // Truy vấn rỗng -> trả về danh sách rỗng hợp lệ ngay lập tức
        if ($rawQuery === '') {
            return [
                'data' => [],
                'meta' => [
                    'query' => '',
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $limit,
                    'total_pages' => 0,
                    'engine' => $this->meiliAvailable ? 'meilisearch' : 'database_fallback',
                    'is_fallback' => ! $this->meiliAvailable,
                    'search_time_ms' => 0.0,
                    'types_searched' => [],
                ],
            ];
        }

        // Xác định các quyền hạn và phạm vi căn hộ của người dùng
        $userScope = $this->resolveUserScope($user);

        // Lọc các type người dùng được phép tìm kiếm
        $typesToSearch = $this->determineTypesToSearch($userScope, $typeFilter);

        $results = collect();
        $isFallback = ! $this->meiliAvailable;
        $fallbackReason = $this->meiliError;

        if ($this->meiliAvailable) {
            try {
                $results = $this->searchViaMeilisearch($rawQuery, $typesToSearch, $userScope, $statusFilter, $limit);
            } catch (Throwable $e) {
                // Fallback nếu Meilisearch ném lỗi trong quá trình thực thi
                $isFallback = true;
                $fallbackReason = 'Meilisearch runtime exception: '.$e->getMessage();
                $results = $this->searchViaDatabase($rawQuery, $typesToSearch, $userScope, $statusFilter, $limit);
            }
        } else {
            $results = $this->searchViaDatabase($rawQuery, $typesToSearch, $userScope, $statusFilter, $limit);
        }

        // Phân trang kết quả
        $total = $results->count();
        $offset = ($page - 1) * $limit;
        $pagedItems = $results->slice($offset, $limit)->values()->all();

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'data' => $pagedItems,
            'meta' => [
                'query' => $rawQuery,
                'total' => $total,
                'page' => $page,
                'per_page' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
                'engine' => $isFallback ? 'database_fallback' : 'meilisearch',
                'is_fallback' => $isFallback,
                'fallback_reason' => $isFallback ? $fallbackReason : null,
                'search_time_ms' => $elapsedMs,
                'types_searched' => $typesToSearch,
            ],
        ];
    }

    /**
     * Xác định quyền và danh sách căn hộ của người dùng
     *
     * @return array{
     *     user: User,
     *     isAdminOrManager: bool,
     *     isStaffOrReception: bool,
     *     apartmentIds: array<string>,
     *     permissions: array<string, bool>
     * }
     */
    protected function resolveUserScope(User $user): array
    {
        $hasPermission = fn (string $perm): bool => RbacService::hasPermission($user, $perm);

        $isAdminOrManager = $user->isSuperAdmin() ||
            $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'DEV']) ||
            $hasPermission('SYSTEM:CONFIG') ||
            $hasPermission('RESIDENT:MANAGE');

        $isStaffOrReception = $isAdminOrManager ||
            $user->hasRole(['RECEPTIONIST', 'SECURITY', 'TECHNICIAN']) ||
            $hasPermission('PARCEL:RECEIVE') ||
            $hasPermission('RECEPTION:VISITOR_CHECKIN');

        // Lấy danh sách căn hộ mà cư dân này thuộc về
        $apartmentIds = DB::table('residents')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('apartment_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return [
            'user' => $user,
            'isAdminOrManager' => $isAdminOrManager,
            'isStaffOrReception' => $isStaffOrReception,
            'apartmentIds' => $apartmentIds,
            'permissions' => [
                'resident_view' => $hasPermission('RESIDENT:VIEW') || $isAdminOrManager,
                'temp_reg_view' => $hasPermission('TEMPORARY_RESIDENCE:VIEW') || $isAdminOrManager,
                'vehicle_view' => $hasPermission('VEHICLE:VIEW') || $isAdminOrManager,
                'rfid_view' => $hasPermission('RFID:VIEW') || $isStaffOrReception,
                'visitor_view' => $hasPermission('RECEPTION:VISITOR_VIEW') || $isStaffOrReception,
                'parcel_view' => $hasPermission('PARCEL:VIEW') || $isStaffOrReception,
            ],
        ];
    }

    /**
     * Xác định danh sách phân hệ được phép tìm kiếm
     *
     * @param  array<string, mixed>  $scope
     * @return array<string>
     */
    protected function determineTypesToSearch(array $scope, string $requestedType): array
    {
        $allowed = [];

        // 1. Cư dân: Admin/Staff xem tất cả; Cư dân chỉ tìm trong căn hộ của mình
        if ($scope['permissions']['resident_view'] || ! empty($scope['apartmentIds'])) {
            $allowed[] = 'resident';
        }

        // 2. Tạm trú tạm vắng: Quản trị hoặc cư dân có căn hộ
        if ($scope['permissions']['temp_reg_view'] || ! empty($scope['apartmentIds'])) {
            $allowed[] = 'temporary_registration';
        }

        // 3. Phương tiện: Quản trị/kỹ thuật hoặc cư dân có căn hộ
        if ($scope['permissions']['vehicle_view'] || ! empty($scope['apartmentIds'])) {
            $allowed[] = 'vehicle';
        }

        // 4. Thẻ RFID: Lễ tân/Bảo vệ/Admin hoặc cư dân
        if ($scope['permissions']['rfid_view'] || ! empty($scope['apartmentIds'])) {
            $allowed[] = 'access_card';
        }

        // 5. Khách viếng thăm: Lễ tân/Bảo vệ/Admin hoặc cư dân
        $allowed[] = 'visitor';

        // 6. Bưu phẩm: Lễ tân/Bảo vệ/Admin hoặc cư dân
        $allowed[] = 'parcel';

        if ($requestedType !== 'all') {
            return in_array($requestedType, $allowed, true) ? [$requestedType] : [];
        }

        return $allowed;
    }

    /**
     * Tìm kiếm thông qua Meilisearch Server
     *
     * @param  array<string>  $types
     * @param  array<string, mixed>  $scope
     */
    protected function searchViaMeilisearch(
        string $query,
        array $types,
        array $scope,
        ?string $statusFilter,
        int $limit
    ): Collection {
        $results = collect();

        foreach ($types as $type) {
            $indexName = match ($type) {
                'resident' => 'residents',
                'temporary_registration' => 'temporary_registrations',
                'vehicle' => 'vehicles',
                'access_card' => 'access_cards',
                'visitor' => 'visitor_registrations',
                'parcel' => 'parcels',
                default => null,
            };

            if (! $indexName) {
                continue;
            }

            try {
                $index = $this->meiliClient->index($indexName);

                // Xây dựng bộ lọc Meilisearch tương ứng với phân quyền
                $filterConditions = $this->buildMeiliFilter($type, $scope, $statusFilter);

                $searchOptions = [
                    'limit' => $limit,
                ];

                if (! empty($filterConditions)) {
                    $searchOptions['filter'] = implode(' AND ', $filterConditions);
                }

                $searchRes = $index->search($query, $searchOptions);
                $hits = $searchRes->getHits();

                if (empty($hits) && ! str_starts_with($query, '"') && (str_contains($query, '-') || str_contains($query, '_') || str_contains($query, '.'))) {
                    $exactRes = $index->search('"'.$query.'"', $searchOptions);
                    $hits = $exactRes->getHits();
                }

                foreach ($hits as $hit) {
                    $formatted = $this->formatHit($type, $hit, $scope);
                    if ($formatted) {
                        $results->push($formatted);
                    }
                }
            } catch (Throwable $e) {
                // Nếu 1 index bị lỗi, ghi log hoặc bỏ qua để các index khác tiếp tục
                error_log("Meilisearch search error on index {$indexName}: ".$e->getMessage());
            }
        }

        return $this->rankResults($results, $query);
    }

    /**
     * Xây dựng biểu thức Filter của Meilisearch theo RBAC
     *
     * @param  array<string, mixed>  $scope
     * @return array<string>
     */
    protected function buildMeiliFilter(string $type, array $scope, ?string $statusFilter): array
    {
        $filters = [];

        // Scope căn hộ đối với cư dân thông thường
        if (! $scope['isAdminOrManager'] && ! $scope['isStaffOrReception']) {
            $apartmentIds = $scope['apartmentIds'];
            $userId = (string) $scope['user']->id;

            if (empty($apartmentIds)) {
                // Nếu cư dân chưa được gán căn hộ nào, chỉ cho phép tìm bản ghi của chính họ
                $userFilter = match ($type) {
                    'resident' => "user_id = '{$userId}'",
                    'temporary_registration' => "resident_user_id = '{$userId}'",
                    'vehicle' => "owner_user_id = '{$userId}'",
                    'access_card' => "assigned_user_id = '{$userId}'",
                    'visitor' => "host_resident_user_id = '{$userId}'",
                    'parcel' => "recipient_user_id = '{$userId}'",
                    default => null,
                };
                if ($userFilter) {
                    $filters[] = $userFilter;
                }
            } else {
                $aptFilter = implode(' OR ', array_map(fn ($id) => "apartment_id = '{$id}'", $apartmentIds));
                $cardAptFilter = implode(' OR ', array_map(fn ($id) => "assigned_apartment_id = '{$id}'", $apartmentIds));

                match ($type) {
                    'resident' => $filters[] = "({$aptFilter})",
                    'temporary_registration' => $filters[] = "({$aptFilter})",
                    'vehicle' => $filters[] = "({$aptFilter} OR owner_user_id = '{$userId}')",
                    'access_card' => $filters[] = "({$cardAptFilter} OR assigned_user_id = '{$userId}')",
                    'visitor' => $filters[] = "({$aptFilter} OR host_resident_user_id = '{$userId}')",
                    'parcel' => $filters[] = "({$aptFilter} OR recipient_user_id = '{$userId}')",
                    default => null,
                };
            }
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            if ($type === 'resident') {
                $isActiveBool = in_array(strtolower($statusFilter), ['1', 'true', 'active'], true) ? 'true' : 'false';
                $filters[] = "is_active = {$isActiveBool}";
            } elseif ($type === 'vehicle') {
                $isActiveBool = in_array(strtolower($statusFilter), ['1', 'true', 'active'], true) ? 'true' : 'false';
                $filters[] = "is_active = {$isActiveBool}";
            } elseif ($type === 'temporary_registration') {
                $filters[] = "police_status = '{$statusFilter}'";
            } elseif ($type === 'access_card') {
                $filters[] = "status = '{$statusFilter}'";
            } elseif ($type === 'visitor') {
                $filters[] = "qr_pass_status = '{$statusFilter}'";
            } elseif ($type === 'parcel') {
                $filters[] = "status = '{$statusFilter}'";
            }
        }

        return $filters;
    }

    /**
     * Fallback sang truy vấn CSDL MySQL khi Meilisearch không sẵn sàng
     *
     * @param  array<string>  $types
     * @param  array<string, mixed>  $scope
     */
    protected function searchViaDatabase(
        string $query,
        array $types,
        array $scope,
        ?string $statusFilter,
        int $limit
    ): Collection {
        $results = collect();
        $q = '%'.$query.'%';

        foreach ($types as $type) {
            match ($type) {
                'resident' => $this->dbSearchResidents($results, $q, $scope, $statusFilter, $limit),
                'temporary_registration' => $this->dbSearchTemporaryRegistrations($results, $q, $scope, $statusFilter, $limit),
                'vehicle' => $this->dbSearchVehicles($results, $q, $scope, $statusFilter, $limit),
                'access_card' => $this->dbSearchAccessCards($results, $q, $scope, $statusFilter, $limit),
                'visitor' => $this->dbSearchVisitors($results, $q, $scope, $statusFilter, $limit),
                'parcel' => $this->dbSearchParcels($results, $q, $scope, $statusFilter, $limit),
                default => null,
            };
        }

        return $this->rankResults($results, $query);
    }

    protected function dbSearchResidents(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = Resident::with(['user', 'apartment.block'])
            ->whereHas('user', function (EloquentBuilder $sub) use ($q) {
                $sub->where('full_name', 'like', $q)
                    ->orWhere('phone_number', 'like', $q)
                    ->orWhere('national_id_number', 'like', $q);
            })
            ->orWhereHas('apartment', function (EloquentBuilder $sub) use ($q) {
                $sub->where('apartment_number', 'like', $q);
            })
            ->orWhere('occupation', 'like', $q);

        if (! $scope['isAdminOrManager'] && ! empty($scope['apartmentIds'])) {
            $query->whereIn('apartment_id', $scope['apartmentIds']);
        }

        if ($statusFilter !== null) {
            $query->where('is_active', in_array(strtolower($statusFilter), ['1', 'true', 'active'], true));
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('resident', $item->toSearchableArray(), $scope));
        }
    }

    protected function dbSearchTemporaryRegistrations(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = TemporaryRegistration::with(['resident.user', 'apartment.block'])
            ->where(function (EloquentBuilder $builder) use ($q) {
                $builder->where('police_reference_code', 'like', $q)
                    ->orWhere('reason', 'like', $q)
                    ->orWhereHas('resident.user', function (EloquentBuilder $sub) use ($q) {
                        $sub->where('full_name', 'like', $q)
                            ->orWhere('phone_number', 'like', $q);
                    })
                    ->orWhereHas('apartment', function (EloquentBuilder $sub) use ($q) {
                        $sub->where('apartment_number', 'like', $q);
                    });
            });

        if (! $scope['isAdminOrManager'] && ! empty($scope['apartmentIds'])) {
            $query->whereIn('apartment_id', $scope['apartmentIds']);
        }

        if ($statusFilter !== null) {
            $query->where('police_status', $statusFilter);
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('temporary_registration', $item->toSearchableArray(), $scope));
        }
    }

    protected function dbSearchVehicles(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = Vehicle::with(['owner', 'apartment.block'])
            ->where(function (EloquentBuilder $builder) use ($q) {
                $builder->where('license_plate', 'like', $q)
                    ->orWhere('brand', 'like', $q)
                    ->orWhere('model', 'like', $q)
                    ->orWhere('registration_certificate_number', 'like', $q)
                    ->orWhereHas('owner', function (EloquentBuilder $sub) use ($q) {
                        $sub->where('full_name', 'like', $q);
                    })
                    ->orWhereHas('apartment', function (EloquentBuilder $sub) use ($q) {
                        $sub->where('apartment_number', 'like', $q);
                    });
            });

        if (! $scope['isAdminOrManager']) {
            $userId = (string) $scope['user']->id;
            $aptIds = $scope['apartmentIds'];
            $query->where(function ($b) use ($userId, $aptIds) {
                $b->where('owner_user_id', $userId)
                    ->when(! empty($aptIds), fn ($sub) => $sub->orWhereIn('apartment_id', $aptIds));
            });
        }

        if ($statusFilter !== null) {
            $query->where('is_active', in_array(strtolower($statusFilter), ['1', 'true', 'active'], true));
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('vehicle', $item->toSearchableArray(), $scope));
        }
    }

    protected function dbSearchAccessCards(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = AccessCard::with(['assignedUser', 'assignedApartment.block', 'assignedVehicle'])
            ->where(function (EloquentBuilder $builder) use ($q) {
                $builder->where('card_number', 'like', $q)
                    ->orWhere('card_uid', 'like', $q)
                    ->orWhereHas('assignedUser', fn ($sub) => $sub->where('full_name', 'like', $q))
                    ->orWhereHas('assignedApartment', fn ($sub) => $sub->where('apartment_number', 'like', $q))
                    ->orWhereHas('assignedVehicle', fn ($sub) => $sub->where('license_plate', 'like', $q));
            });

        if (! $scope['isAdminOrManager'] && ! $scope['isStaffOrReception']) {
            $userId = (string) $scope['user']->id;
            $aptIds = $scope['apartmentIds'];
            $query->where(function ($b) use ($userId, $aptIds) {
                $b->where('assigned_user_id', $userId)
                    ->when(! empty($aptIds), fn ($sub) => $sub->orWhereIn('assigned_apartment_id', $aptIds));
            });
        }

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('access_card', $item->toSearchableArray(), $scope));
        }
    }

    protected function dbSearchVisitors(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = VisitorRegistration::with(['hostUser', 'apartment.block'])
            ->where(function (EloquentBuilder $builder) use ($q) {
                $builder->where('registration_code', 'like', $q)
                    ->orWhere('visitor_name', 'like', $q)
                    ->orWhere('visitor_phone', 'like', $q)
                    ->orWhere('vehicle_license_plate', 'like', $q)
                    ->orWhere('visit_purpose', 'like', $q)
                    ->orWhereHas('hostUser', fn ($sub) => $sub->where('full_name', 'like', $q))
                    ->orWhereHas('apartment', fn ($sub) => $sub->where('apartment_number', 'like', $q));
            });

        if (! $scope['isAdminOrManager'] && ! $scope['isStaffOrReception']) {
            $userId = (string) $scope['user']->id;
            $aptIds = $scope['apartmentIds'];
            $query->where(function ($b) use ($userId, $aptIds) {
                $b->where('host_resident_user_id', $userId)
                    ->when(! empty($aptIds), fn ($sub) => $sub->orWhereIn('apartment_id', $aptIds));
            });
        }

        if ($statusFilter !== null) {
            $query->where('qr_pass_status', $statusFilter);
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('visitor', $item->toSearchableArray(), $scope));
        }
    }

    protected function dbSearchParcels(Collection &$results, string $q, array $scope, ?string $statusFilter, int $limit): void
    {
        $query = Parcel::with(['apartment.block', 'recipientUser'])
            ->where(function (EloquentBuilder $builder) use ($q) {
                $builder->where('tracking_number', 'like', $q)
                    ->orWhere('recipient_name', 'like', $q)
                    ->orWhere('recipient_phone', 'like', $q)
                    ->orWhere('courier_company', 'like', $q)
                    ->orWhereHas('apartment', fn ($sub) => $sub->where('apartment_number', 'like', $q));
            });

        if (! $scope['isAdminOrManager'] && ! $scope['isStaffOrReception']) {
            $userId = (string) $scope['user']->id;
            $aptIds = $scope['apartmentIds'];
            $query->where(function ($b) use ($userId, $aptIds) {
                $b->where('recipient_user_id', $userId)
                    ->when(! empty($aptIds), fn ($sub) => $sub->orWhereIn('apartment_id', $aptIds));
            });
        }

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        foreach ($query->limit($limit)->get() as $item) {
            $results->push($this->formatHit('parcel', $item->toSearchableArray(), $scope));
        }
    }

    /**
     * Định dạng bản ghi theo Unified Result Schema và áp dụng che thông tin bảo mật (masking)
     *
     * @param  array<string, mixed>  $hit
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    protected function formatHit(string $type, array $hit, array $scope): array
    {
        $canViewFullSensitive = $scope['isAdminOrManager'] || $scope['isStaffOrReception'];

        return match ($type) {
            'resident' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'resident',
                'type_label' => 'Cư dân',
                'identifier' => (string) ($hit['full_name'] ?? ''),
                'title' => $hit['full_name'] ?? 'Cư dân',
                'subtitle' => 'Căn hộ '.($hit['apartment_number'] ?? 'N/A').($hit['block_name'] ? ' • '.$hit['block_name'] : ''),
                'detail' => ($hit['is_head_of_household'] ? 'Chủ hộ' : 'Thành viên')
                    .' • '.$this->maskPhone($hit['phone_number'] ?? '', $canViewFullSensitive),
                'badge' => $hit['is_active'] ? 'Đang cư trú' : 'Đã chuyển đi',
                'badge_variant' => $hit['is_active'] ? 'success' : 'neutral',
                'status' => $hit['is_active'] ? 'ACTIVE' : 'INACTIVE',
                'url' => '/admin/residents?search='.urlencode($hit['full_name'] ?? ''),
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'apartment_id' => $hit['apartment_id'] ?? null,
                    'national_id_masked' => $this->maskNationalId($hit['national_id_number'] ?? '', $canViewFullSensitive),
                ],
            ],
            'temporary_registration' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'temporary_registration',
                'type_label' => 'Tạm trú / Tạm vắng',
                'identifier' => (string) ($hit['police_reference_code'] ?? ''),
                'title' => $hit['resident_name'] ?? 'Hồ sơ tạm trú/vắng',
                'subtitle' => 'Căn hộ '.($hit['apartment_number'] ?? 'N/A').' • '.($hit['registration_type'] === 'TEMPORARY_STAY' ? 'ĐK Tạm trú' : 'ĐK Tạm vắng'),
                'detail' => 'Mã CA: '.($hit['police_reference_code'] ?: 'Chờ cấp')
                    .' • '.($hit['start_date'] ?? '').' đến '.($hit['end_date'] ?? ''),
                'badge' => $this->translatePoliceStatus($hit['police_status'] ?? ''),
                'badge_variant' => match ($hit['police_status'] ?? '') {
                    'APPROVED_BY_POLICE' => 'success',
                    'PENDING_POLICE_APPROVAL' => 'warning',
                    'REJECTED_BY_POLICE' => 'danger',
                    default => 'neutral',
                },
                'status' => $hit['police_status'] ?? 'DRAFT',
                'url' => '/admin/temporary-registration?search='.urlencode($hit['resident_name'] ?? ''),
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'registration_type' => $hit['registration_type'] ?? null,
                    'police_reference_code' => $hit['police_reference_code'] ?? null,
                ],
            ],
            'vehicle' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'vehicle',
                'type_label' => 'Phương tiện',
                'identifier' => (string) ($hit['license_plate'] ?? ''),
                'title' => $hit['license_plate'] ?? 'Biển số xe',
                'subtitle' => 'Căn hộ '.($hit['apartment_number'] ?? 'N/A').' • '.trim(($hit['brand'] ?? '').' '.($hit['model'] ?? '')),
                'detail' => 'Chủ xe: '.($hit['owner_name'] ?? 'N/A')
                    .' • '.$this->translateVehicleCategory($hit['vehicle_category'] ?? ''),
                'badge' => $hit['is_active'] ? 'Hoạt động' : 'Tạm ngưng',
                'badge_variant' => $hit['is_active'] ? 'success' : 'danger',
                'status' => $hit['is_active'] ? 'ACTIVE' : 'INACTIVE',
                'url' => '/admin/vehicles?search='.urlencode($hit['license_plate'] ?? ''),
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'license_plate' => $hit['license_plate'] ?? '',
                    'vehicle_category' => $hit['vehicle_category'] ?? '',
                ],
            ],
            'access_card' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'access_card',
                'type_label' => 'Thẻ RFID',
                'identifier' => (string) ($hit['card_number'] ?? ''),
                'title' => 'Thẻ '.$hit['card_number'].' ('.$hit['card_uid'].')',
                'subtitle' => 'Căn hộ '.($hit['apartment_number'] ?? 'N/A').($hit['vehicle_license_plate'] ? ' • Xe: '.$hit['vehicle_license_plate'] : ''),
                'detail' => 'Gán cho: '.($hit['user_name'] ?? 'N/A')
                    .' • Loại: '.($hit['card_type'] ?? 'ELEVATOR'),
                'badge' => match ($hit['status'] ?? '') {
                    'ACTIVE' => 'Đang hoạt động',
                    'LOCKED' => 'Đã khóa',
                    'LOST' => 'Báo mất',
                    'EXPIRED' => 'Hết hạn',
                    default => $hit['status'] ?? 'N/A',
                },
                'badge_variant' => match ($hit['status'] ?? '') {
                    'ACTIVE' => 'success',
                    'LOCKED', 'LOST' => 'danger',
                    default => 'warning',
                },
                'status' => $hit['status'] ?? 'ACTIVE',
                'url' => '/admin/rfid-cards?search='.urlencode($hit['card_number'] ?? ''),
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'card_number' => $hit['card_number'] ?? '',
                    'card_uid' => $hit['card_uid'] ?? '',
                ],
            ],
            'visitor' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'visitor',
                'type_label' => 'Khách viếng thăm',
                'identifier' => (string) ($hit['registration_code'] ?? ''),
                'title' => $hit['visitor_name'] ?? 'Khách',
                'subtitle' => 'Mã: '.($hit['registration_code'] ?? 'N/A').' • Căn hộ '.($hit['apartment_number'] ?? 'N/A'),
                'detail' => 'Đón tiếp bởi: '.($hit['host_name'] ?? 'Cư dân')
                    .' • '.$this->maskPhone($hit['visitor_phone'] ?? '', $canViewFullSensitive),
                'badge' => match ($hit['qr_pass_status'] ?? '') {
                    'ACTIVE' => 'Chờ tiếp đón',
                    'USED' => 'Đã check-in',
                    'EXPIRED' => 'Hết hạn',
                    'CANCELLED' => 'Đã hủy',
                    default => $hit['qr_pass_status'] ?? 'N/A',
                },
                'badge_variant' => match ($hit['qr_pass_status'] ?? '') {
                    'ACTIVE' => 'info',
                    'USED' => 'success',
                    'EXPIRED', 'CANCELLED' => 'neutral',
                    default => 'warning',
                },
                'status' => $hit['qr_pass_status'] ?? 'ACTIVE',
                'url' => $scope['isStaffOrReception']
                    ? '/le-tan/khach?search='.urlencode($hit['registration_code'] ?? '')
                    : '/cu-dan/khach-vieng-tham',
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'registration_code' => $hit['registration_code'] ?? '',
                    'vehicle_license_plate' => $hit['vehicle_license_plate'] ?? null,
                ],
            ],
            'parcel' => [
                'id' => (string) ($hit['id'] ?? ''),
                'type' => 'parcel',
                'type_label' => 'Bưu phẩm',
                'identifier' => (string) ($hit['tracking_number'] ?? ''),
                'title' => 'Vận đơn: '.($hit['tracking_number'] ?? 'N/A'),
                'subtitle' => 'Người nhận: '.($hit['recipient_name'] ?? 'N/A').' • Căn hộ '.($hit['apartment_number'] ?? 'N/A'),
                'detail' => 'ĐVVC: '.($hit['courier_company'] ?? 'Giao hàng')
                    .' • '.$this->maskPhone($hit['recipient_phone'] ?? '', $canViewFullSensitive),
                'badge' => match ($hit['status'] ?? '') {
                    'RECEIVED_AT_RECEPTION', 'RECEIVED' => 'Chờ cư dân nhận',
                    'DELIVERED_TO_RESIDENT', 'COLLECTED' => 'Đã bàn giao',
                    'RETURNED_TO_SENDER' => 'Đã hoàn trả',
                    default => $hit['status'] ?? 'Chờ nhận',
                },
                'badge_variant' => match ($hit['status'] ?? '') {
                    'RECEIVED_AT_RECEPTION', 'RECEIVED' => 'warning',
                    'DELIVERED_TO_RESIDENT', 'COLLECTED' => 'success',
                    default => 'neutral',
                },
                'status' => $hit['status'] ?? 'RECEIVED',
                'url' => $scope['isStaffOrReception']
                    ? '/le-tan/buu-pham?search='.urlencode($hit['tracking_number'] ?? '')
                    : '/cu-dan/buu-pham',
                'created_at' => $hit['created_at'] ?? null,
                'meta' => [
                    'tracking_number' => $hit['tracking_number'] ?? '',
                    'courier_company' => $hit['courier_company'] ?? '',
                ],
            ],
            default => [],
        };
    }

    /**
     * Sắp xếp kết quả ưu tiên khớp chính xác (Exact match priority)
     */
    protected function rankResults(Collection $results, string $query): Collection
    {
        $cleanQuery = strtolower(trim($query));

        return $results->sortByDesc(function ($item) use ($cleanQuery) {
            $score = 0;
            $title = strtolower($item['title'] ?? '');
            $subtitle = strtolower($item['subtitle'] ?? '');

            // 1. Khớp chính xác hoàn toàn tiêu đề (Mã RFID, Biển số xe, Vận đơn)
            if ($title === $cleanQuery) {
                $score += 1000;
            }

            // 2. Tiêu đề bắt đầu bằng từ khóa
            if (str_starts_with($title, $cleanQuery)) {
                $score += 500;
            }

            // 3. Tiêu đề chứa từ khóa
            if (str_contains($title, $cleanQuery)) {
                $score += 200;
            }

            // 4. Subtitle chứa từ khóa (ví dụ số căn hộ "0801")
            if (str_contains($subtitle, $cleanQuery)) {
                $score += 100;
            }

            return $score;
        });
    }

    /**
     * Che số điện thoại bảo vệ quyền riêng tư
     */
    public function maskPhone(?string $phone, bool $canViewFull = false): string
    {
        if (! $phone) {
            return '';
        }
        if ($canViewFull) {
            return $phone;
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) >= 9) {
            return substr($clean, 0, 3).'****'.substr($clean, -3);
        }

        return '******';
    }

    /**
     * Che số CMND/CCCD
     */
    public function maskNationalId(?string $id, bool $canViewFull = false): string
    {
        if (! $id) {
            return '';
        }
        if ($canViewFull) {
            return $id;
        }

        $clean = trim($id);
        if (strlen($clean) >= 8) {
            return substr($clean, 0, 3).'******'.substr($clean, -3);
        }

        return '********';
    }

    protected function translatePoliceStatus(string $status): string
    {
        return match ($status) {
            'APPROVED_BY_POLICE' => 'Công an đã duyệt',
            'PENDING_POLICE_APPROVAL' => 'Chờ công an duyệt',
            'REJECTED_BY_POLICE' => 'Công an từ chối',
            default => 'Mới tạo',
        };
    }

    protected function translateVehicleCategory(string $category): string
    {
        return match (strtoupper($category)) {
            'MOTORBIKE', 'XE_MAY' => 'Xe máy',
            'CAR_4_SEATS', 'OTO_4_CHO' => 'Ô tô 4 chỗ',
            'CAR_7_SEATS', 'OTO_7_CHO' => 'Ô tô 7 chỗ',
            'ELECTRIC_BICYCLE', 'XE_DAP_DIEN' => 'Xe đạp điện',
            'BICYCLE', 'XE_DAP' => 'Xe đạp',
            default => $category ?: 'Phương tiện',
        };
    }
}
