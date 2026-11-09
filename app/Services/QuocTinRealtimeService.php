<?php

namespace App\Services;

use App\Events\QuocTinRealtimeEvent;
use App\Exceptions\ModuleCooldownException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuocTinRealtimeService
{
    public const DEFAULT_COOLDOWN_SECONDS = 120;

    public const RFID_COOLDOWN_SECONDS = 60; // Cooldown 1 phút cho Quản lý thẻ RFID

    /**
     * Normalize module name to standard identifier:
     * rbac | residents | temporary_registrations | account_provisioning | vehicles
     */
    public static function normalizeModuleName(string $module): string
    {
        $m = strtolower(trim($module));

        return match ($m) {
            'rbac', 'roles', 'permissions' => 'rbac',
            'residents', 'resident' => 'residents',
            'temporary-registrations', 'temporary_registrations', 'temporary_registration' => 'temporary_registrations',
            'account-provisioning', 'account_provisioning', 'accounts' => 'account_provisioning',
            'vehicles', 'vehicle' => 'vehicles',
            'rfid', 'rfid_cards', 'rfid-cards', 'cards', 'access_cards' => 'rfid_cards',
            'visitors', 'visitor', 'visitor_registrations', 'visitor-registrations' => 'visitors',
            'parcels', 'parcel', 'packages', 'package' => 'parcels',
            'schedules', 'schedule', 'staff_schedules', 'staff-schedules', 'shifts', 'shift' => 'schedules',
            default => str_replace('-', '_', $m),
        };
    }

    /**
     * Map module name to its authorized channel name
     */
    public static function getChannelForModule(string $module): string
    {
        $normalized = self::normalizeModuleName($module);

        return match ($normalized) {
            'rbac' => 'quoc-tin.rbac',
            'residents' => 'quoc-tin.residents',
            'temporary_registrations' => 'quoc-tin.temporary-registrations',
            'account_provisioning' => 'quoc-tin.account-provisioning',
            'vehicles' => 'quoc-tin.vehicles',
            'rfid_cards' => 'quoc-tin.rfid-cards',
            'visitors' => 'quoc-tin.visitors',
            'parcels' => 'quoc-tin.parcels',
            'schedules' => 'quoc-tin.schedules',
            default => str_starts_with($module, 'quoc-tin.') ? $module : "quoc-tin.{$module}",
        };
    }

    /**
     * Check if a module is currently locked in an edit cooldown
     *
     * @return array<string, mixed>|null Returns cooldown info array if locked, or null if unlocked
     */
    public static function getModuleCooldown(string $module): ?array
    {
        $canon = self::normalizeModuleName($module);
        $key = "realtime_cooldown:{$canon}";

        $data = Cache::get($key);
        if (! is_array($data)) {
            return null;
        }

        $untilTs = (int) ($data['cooldown_until_ts'] ?? 0);
        $now = time();

        if ($untilTs <= $now) {
            Cache::forget($key);

            return null;
        }

        $data['retry_after'] = max(1, $untilTs - $now);

        return $data;
    }

    /**
     * Check if a module is currently in cooldown (boolean check)
     */
    public static function isModuleInCooldown(string $module): bool
    {
        return self::getModuleCooldown($module) !== null;
    }

    /**
     * Assert that module is NOT in cooldown; throws ModuleCooldownException (409) if it is.
     *
     * @throws ModuleCooldownException
     */
    public static function assertNotInCooldown(string $module): void
    {
        $cooldown = self::getModuleCooldown($module);
        if ($cooldown !== null) {
            throw new ModuleCooldownException($module, $cooldown);
        }
    }

    /**
     * Get default cooldown seconds for a given module (rfid_cards = 60s, others = 120s)
     */
    public static function getCooldownSecondsForModule(string $module): int
    {
        $canon = self::normalizeModuleName($module);
        if ($canon === 'rfid_cards') {
            return self::RFID_COOLDOWN_SECONDS;
        }

        return self::DEFAULT_COOLDOWN_SECONDS;
    }

    /**
     * Start/Set a shared edit cooldown for a specific module on the server
     *
     * @return array<string, mixed>
     */
    public static function setModuleCooldown(
        string $module,
        ?int $seconds = null,
        ?string $actorId = null,
        string $action = 'MUTATION'
    ): array {
        $canon = self::normalizeModuleName($module);
        $seconds = $seconds ?? self::getCooldownSecondsForModule($canon);
        $now = now();
        $until = $now->copy()->addSeconds($seconds);

        $data = [
            'module' => $canon,
            'action' => $action,
            'actor_id' => $actorId,
            'cooldown_seconds' => $seconds,
            'cooldown_started_at' => $now->toIso8601String(),
            'cooldown_until' => $until->toIso8601String(),
            'cooldown_until_ts' => $until->timestamp,
        ];

        Cache::put("realtime_cooldown:{$canon}", $data, $seconds);

        return $data;
    }

    /**
     * Clear active cooldown for a module (e.g. testing or manual override)
     */
    public static function clearModuleCooldown(string $module): void
    {
        $canon = self::normalizeModuleName($module);
        Cache::forget("realtime_cooldown:{$canon}");
    }

    public const STAFF_ROLES = [
        'SUPER_ADMIN',
        'ADMIN',
        'BUILDING_MANAGER',
        'MANAGER',
        'RECEPTIONIST',
        'SECURITY_GUARD',
        'STAFF',
        'TECHNICIAN',
    ];

    public const RESIDENT_ROLES = [
        'RESIDENT_OWNER',
        'RESIDENT_TENANT',
        'RESIDENT',
        'MEMBER',
    ];

    /**
     * Kiểm tra user có vai trò nhân viên vận hành hay không
     */
    public static function isStaffUser(?object $user): bool
    {
        if (! $user || ! isset($user->id)) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            foreach (self::STAFF_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        try {
            return DB::table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $user->id)
                ->whereIn('roles.role_code', self::STAFF_ROLES)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Kiểm tra user có phải cư dân hợp lệ của tòa nhà hay không
     */
    public static function isResidentUser(?object $user): bool
    {
        if (! $user || ! isset($user->id)) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            foreach (self::RESIDENT_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        try {
            $hasRole = DB::table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $user->id)
                ->whereIn('roles.role_code', self::RESIDENT_ROLES)
                ->exists();

            if ($hasRole) {
                return true;
            }

            return DB::table('residents')
                ->where('user_id', $user->id)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->exists()
                || DB::table('apartment_owners')
                    ->where('owner_user_id', $user->id)
                    ->where('is_current_owner', 1)
                    ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lấy danh sách ID các căn hộ mà người dùng được phép truy cập
     *
     * @return array<string>
     */
    public static function getAuthorizedApartmentIds(?object $user): array
    {
        if (! $user || ! isset($user->id)) {
            return [];
        }

        try {
            $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();

            // 1. Căn hộ hợp lệ từ quan hệ cư trú (bảng residents)
            $residentAptIds = DB::table('residents')
                ->join('apartments', 'residents.apartment_id', '=', 'apartments.id')
                ->where('residents.user_id', $user->id)
                ->where('residents.is_active', 1)
                ->whereNull('residents.deleted_at')
                ->whereNull('apartments.deleted_at')
                ->where(function ($query) use ($today) {
                    $query->whereNull('residents.stay_start_date')
                        ->orWhere('residents.stay_start_date', '<=', $today);
                })
                ->where(function ($query) use ($today) {
                    $query->whereNull('residents.stay_end_date')
                        ->orWhere('residents.stay_end_date', '>=', $today);
                })
                ->pluck('residents.apartment_id')
                ->toArray();

            // 2. Căn hộ hợp lệ từ quan hệ sở hữu (bảng apartment_owners)
            $ownerAptIds = [];
            try {
                $ownerAptIds = DB::table('apartment_owners')
                    ->join('apartments', 'apartment_owners.apartment_id', '=', 'apartments.id')
                    ->where('apartment_owners.owner_user_id', $user->id)
                    ->where('apartment_owners.is_current_owner', 1)
                    ->whereNull('apartments.deleted_at')
                    ->where(function ($query) use ($today) {
                        $query->whereNull('apartment_owners.ownership_start_date')
                            ->orWhere('apartment_owners.ownership_start_date', '<=', $today);
                    })
                    ->where(function ($query) use ($today) {
                        $query->whereNull('apartment_owners.ownership_end_date')
                            ->orWhere('apartment_owners.ownership_end_date', '>=', $today);
                    })
                    ->pluck('apartment_owners.apartment_id')
                    ->toArray();
            } catch (\Throwable) {
                $ownerAptIds = [];
            }

            // Hợp nhất hai nguồn quyền duy nhất; loại bỏ hoàn toàn cấp quyền từ apartments.current_resident_user_id
            $all = array_merge($residentAptIds, $ownerAptIds);

            return array_values(array_unique(array_filter(array_map('strval', $all))));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check if a user is authorized to listen to a specific channel
     */
    public static function isUserAuthorizedForChannel(?object $user, string $channel): bool
    {
        if (! $user) {
            return false;
        }

        // Super Admin & Admin have access to all channels
        if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return match ($channel) {
            'quoc-tin.rbac' => RbacService::hasPermission($user, 'ROLE:VIEW')
                || RbacService::hasPermission($user, 'ROLE:MANAGE')
                || RbacService::hasPermission($user, 'PERMISSION:VIEW'),

            'quoc-tin.residents' => RbacService::hasPermission($user, 'RESIDENT:VIEW')
                || RbacService::hasPermission($user, 'RESIDENT:CREATE')
                || RbacService::hasPermission($user, 'RESIDENT:UPDATE'),

            'quoc-tin.temporary-registrations' => RbacService::hasPermission($user, 'TEMP_REG:VIEW')
                || RbacService::hasPermission($user, 'TEMP_REG:APPROVE')
                || RbacService::hasPermission($user, 'TEMP_REG:CREATE'),

            'quoc-tin.account-provisioning' => RbacService::hasPermission($user, 'USER:VIEW')
                || RbacService::hasPermission($user, 'USER:CREATE'),

            'quoc-tin.vehicles' => RbacService::hasPermission($user, 'VEHICLE:VIEW')
                || RbacService::hasPermission($user, 'VEHICLE:CREATE')
                || RbacService::hasPermission($user, 'VEHICLE:APPROVE'),

            'quoc-tin.rfid-cards', 'quoc-tin.rfid_cards' => self::isStaffUser($user)
                && (RbacService::hasPermission($user, 'RFID:VIEW')
                    || RbacService::hasPermission($user, 'ACCESS_CARD:VIEW')
                    || RbacService::hasPermission($user, 'RECEPTION:OPERATE')),

            'quoc-tin.visitors' => self::isStaffUser($user)
                && (RbacService::hasPermission($user, 'VISITOR:VIEW')
                    || RbacService::hasPermission($user, 'RECEPTION:OPERATE')),

            'quoc-tin.parcels' => self::isStaffUser($user)
                && (RbacService::hasPermission($user, 'PARCEL:VIEW')
                    || RbacService::hasPermission($user, 'RECEPTION:OPERATE')),

            'quoc-tin.schedules', 'quoc-tin.staff_schedules' => self::isStaffUser($user)
                && (RbacService::hasPermission($user, 'SCHEDULE:VIEW')
                    || RbacService::hasPermission($user, 'STAFF_SCHEDULE:VIEW')),

            default => false,
        };
    }

    /**
     * Check if a user is authorized to stream/poll events for a specific channel via HTTP SSE/Polling
     *
     * Nhân viên: áp dụng cùng quy tắc phân quyền như WebSocket channel
     * Cư dân: được phép nhận luồng 'quoc-tin.parcels' và 'quoc-tin.visitors' NẾU VÀ CHỈ NẾU
     * có ít nhất một căn hộ hợp lệ và còn thời hạn cư trú/sở hữu (getAuthorizedApartmentIds không rỗng).
     * Dữ liệu sau đó bắt buộc phải đi qua filterEventsForResident() để cô lập theo từng căn hộ.
     */
    public static function isUserAuthorizedForSseChannel(?object $user, string $channel): bool
    {
        if (! $user) {
            return false;
        }

        // Super Admin & Admin có quyền tất cả các kênh SSE
        if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // Nếu là nhân viên, ủy quyền hoàn toàn cho quy tắc phân quyền channel
        if (self::isStaffUser($user)) {
            return self::isUserAuthorizedForChannel($user, $channel);
        }

        // Nếu là cư dân: chỉ cho phép các module được phê duyệt cho cư dân
        // Với parcels và visitors: bắt buộc phải có ít nhất một căn hộ hợp lệ và còn thời hạn
        if ($channel === 'quoc-tin.parcels' || $channel === 'quoc-tin.visitors') {
            $allowedAptIds = self::getAuthorizedApartmentIds($user);

            return ! empty($allowedAptIds);
        }

        // Các kênh cư dân khác (nếu có cấp quyền cụ thể theo RBAC)
        return false;
    }

    /**
     * Check if a specific visitor registration record is currently locked in an edit cooldown
     *
     * @return array<string, mixed>|null
     */
    public static function getVisitorRecordCooldown(string $recordId): ?array
    {
        $key = "realtime_cooldown:visitor_record:{$recordId}";
        $data = Cache::get($key);
        if (! is_array($data)) {
            return null;
        }

        $untilTs = (int) ($data['cooldown_until_ts'] ?? 0);
        $now = time();

        if ($untilTs <= $now) {
            Cache::forget($key);

            return null;
        }

        $data['retry_after'] = max(1, $untilTs - $now);

        return $data;
    }

    /**
     * Set a record-level cooldown for a visitor registration (defaults to 60 seconds)
     *
     * @return array<string, mixed>
     */
    public static function setVisitorRecordCooldown(string $recordId, ?string $actorId = null, int $seconds = 60): array
    {
        $now = now();
        $until = $now->copy()->addSeconds($seconds);

        $data = [
            'record_id' => $recordId,
            'actor_id' => $actorId,
            'cooldown_seconds' => $seconds,
            'cooldown_started_at' => $now->toIso8601String(),
            'cooldown_until' => $until->toIso8601String(),
            'cooldown_until_ts' => $until->timestamp,
        ];

        Cache::put("realtime_cooldown:visitor_record:{$recordId}", $data, $seconds);

        return $data;
    }

    /**
     * Clear record-level cooldown for a visitor registration
     */
    public static function clearVisitorRecordCooldown(string $recordId): void
    {
        Cache::forget("realtime_cooldown:visitor_record:{$recordId}");
    }

    /**
     * Broadcast a real-time mutation event (MUST only be called after DB transaction commits)
     *
     * @param  string  $module  Module name: rbac | residents | temporary_registrations | account_provisioning | vehicles
     * @param  string  $entity  Entity name: role | resident | temporary_registration | user | vehicle
     * @param  string  $action  Action: CREATED | UPDATED | DELETED | APPROVED | REJECTED | TRANSFERRED | SYNCED | etc.
     * @param  string|null  $entityId  Target entity primary ID
     * @param  array<string, mixed>  $extra  Additional non-sensitive metadata
     * @param  string|null  $actorId  User ID who performed the mutation
     * @param  bool  $triggerCooldown  Whether to initiate the 120s server-side edit cooldown (default false)
     */
    public static function emit(
        string $module,
        string $entity,
        string $action,
        ?string $entityId = null,
        array $extra = [],
        ?string $actorId = null,
        bool $triggerCooldown = false
    ): void {
        $canonicalModule = self::normalizeModuleName($module);
        $channel = self::getChannelForModule($canonicalModule);
        $nowMs = (int) (microtime(true) * 1000);
        $updatedAt = $extra['updated_at'] ?? now()->toIso8601String();
        $version = (string) ($extra['version'] ?? $nowMs);

        // 1. Activate server-side cooldown for mutations if requested
        $cooldownData = null;
        if ($triggerCooldown && strtoupper($action) !== 'EDIT_COOLDOWN_STARTED') {
            $cdSeconds = self::getCooldownSecondsForModule($canonicalModule);
            $cooldownData = self::setModuleCooldown($canonicalModule, $cdSeconds, $actorId, strtoupper($action));
            $extra['cooldown_until'] = $cooldownData['cooldown_until'];
            $extra['cooldown_seconds'] = $cdSeconds;
            $extra['cooldown_until_ts'] = $cooldownData['cooldown_until_ts'];
        }

        // Sanitize payload: strictly filter out any credentials, secrets, or tokens
        $cleanExtra = collect($extra)->except([
            'password',
            'password_hash',
            'activation_token',
            'token',
            'secret',
            'remember_token',
            'refresh_token_hash',
        ])->toArray();

        $eventData = array_merge([
            'module' => $canonicalModule,
            'entity' => $entity,
            'action' => strtoupper($action),
            'entity_id' => $entityId,
            'updated_at' => $updatedAt,
            'version' => $version,
            'actor_id' => $actorId,
            'timestamp' => $nowMs,
        ], $cleanExtra);

        try {
            // 2. Persist mutation event into high-speed event store
            $eventId = DB::table('realtime_sync_events')->insertGetId([
                'channel' => $channel,
                'module' => $canonicalModule,
                'entity' => $entity,
                'action' => strtoupper($action),
                'entity_id' => $entityId,
                'version' => $version,
                'payload' => json_encode($eventData, JSON_UNESCAPED_UNICODE),
                'created_at_ms' => $nowMs,
                'created_at' => now(),
            ]);

            $eventData['id'] = $eventId;

            // 3. Dispatch Laravel Broadcasting event
            event(new QuocTinRealtimeEvent($channel, $eventData));

            // 4. Also emit EDIT_COOLDOWN_STARTED event to channel if cooldown was initiated
            if ($cooldownData !== null) {
                $cdSeconds = $cooldownData['cooldown_seconds'] ?? self::getCooldownSecondsForModule($canonicalModule);
                $cooldownEventData = [
                    'module' => $canonicalModule,
                    'entity' => $entity,
                    'action' => 'EDIT_COOLDOWN_STARTED',
                    'entity_id' => $entityId,
                    'actor_id' => $actorId,
                    'cooldown_until' => $cooldownData['cooldown_until'],
                    'cooldown_seconds' => $cdSeconds,
                    'cooldown_until_ts' => $cooldownData['cooldown_until_ts'],
                    'timestamp' => $nowMs + 1,
                ];

                $cdEventId = DB::table('realtime_sync_events')->insertGetId([
                    'channel' => $channel,
                    'module' => $canonicalModule,
                    'entity' => $entity,
                    'action' => 'EDIT_COOLDOWN_STARTED',
                    'entity_id' => $entityId,
                    'version' => (string) ($nowMs + 1),
                    'payload' => json_encode($cooldownEventData, JSON_UNESCAPED_UNICODE),
                    'created_at_ms' => $nowMs + 1,
                    'created_at' => now(),
                ]);

                $cooldownEventData['id'] = $cdEventId;
                event(new QuocTinRealtimeEvent($channel, $cooldownEventData));
            }

            // 5. Periodic clean up (prune events older than 15 minutes, 5% probability)
            if (mt_rand(1, 20) === 1) {
                DB::table('realtime_sync_events')
                    ->where('created_at', '<', now()->subMinutes(15))
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('QuocTinRealtimeService broadcast error: '.$e->getMessage(), [
                'channel' => $channel,
                'module' => $canonicalModule,
                'action' => $action,
            ]);
        }
    }

    /**
     * Lọc sự kiện realtime cho cư dân ở tầng server-side:
     * Chỉ cho phép nhận các sự kiện thuộc những căn hộ mà cư dân được phân quyền.
     * Ngăn chặn tuyệt đối việc rò rỉ bưu phẩm, khách, RFID và lịch trực của người khác.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function filterEventsForResident(array $events, object $user): array
    {
        if (empty($events)) {
            return [];
        }

        $allowedApartmentIds = self::getAuthorizedApartmentIds($user);

        // 1. Thu thập các entity_id cần tra cứu apartment_id trong batch để tránh N+1 query
        $missingParcelEntityIds = [];
        $missingVisitorRegIds = [];
        $missingVisitorCheckinLogIds = [];

        foreach ($events as $ev) {
            $channel = $ev['channel'] ?? '';
            $module = $ev['module'] ?? '';

            if ($channel === 'quoc-tin.parcels' || $module === 'parcels') {
                if (empty($ev['apartment_id']) && ! empty($ev['entity_id'])) {
                    $missingParcelEntityIds[] = (string) $ev['entity_id'];
                }
            } elseif ($channel === 'quoc-tin.visitors' || $module === 'visitors') {
                if (empty($ev['apartment_id'])) {
                    if (! empty($ev['registration_id'])) {
                        $missingVisitorRegIds[] = (string) $ev['registration_id'];
                    } elseif (($ev['entity'] ?? '') === 'visitor_registration' && ! empty($ev['entity_id'])) {
                        $missingVisitorRegIds[] = (string) $ev['entity_id'];
                    } elseif (($ev['entity'] ?? '') === 'visitor_checkin' && ! empty($ev['entity_id'])) {
                        $missingVisitorCheckinLogIds[] = (string) $ev['entity_id'];
                    }
                }
            }
        }

        // 2. Tra cứu hàng loạt (Batch query)
        $parcelAptMap = [];
        if (! empty($missingParcelEntityIds)) {
            try {
                $parcelAptMap = DB::table('parcels')
                    ->whereIn('id', array_unique($missingParcelEntityIds))
                    ->pluck('apartment_id', 'id')
                    ->map(fn ($val) => (string) $val)
                    ->toArray();
            } catch (\Throwable) {
                $parcelAptMap = [];
            }
        }

        $visitorAptMap = [];
        if (! empty($missingVisitorRegIds)) {
            try {
                $regMap = DB::table('visitor_registrations')
                    ->whereIn('id', array_unique($missingVisitorRegIds))
                    ->pluck('apartment_id', 'id')
                    ->map(fn ($val) => (string) $val)
                    ->toArray();
                $visitorAptMap += $regMap;
            } catch (\Throwable) {
                // ignore
            }
        }

        if (! empty($missingVisitorCheckinLogIds)) {
            try {
                $checkinMap = DB::table('visitor_checkin_logs')
                    ->whereIn('id', array_unique($missingVisitorCheckinLogIds))
                    ->pluck('apartment_id', 'id')
                    ->map(fn ($val) => (string) $val)
                    ->toArray();
                $visitorAptMap += $checkinMap;
            } catch (\Throwable) {
                // ignore
            }
        }

        // 3. Lọc sự kiện theo từng loại module/kênh
        $filtered = [];
        foreach ($events as $ev) {
            $channel = $ev['channel'] ?? '';
            $module = $ev['module'] ?? '';

            // Không bao giờ để lộ RFID, Lịch trực nhân viên, RBAC hoặc Account Provisioning cho cư dân
            if (
                $channel === 'quoc-tin.rfid-cards' || $module === 'rfid_cards'
                || $channel === 'quoc-tin.schedules' || $module === 'schedules'
                || $channel === 'quoc-tin.rbac' || $module === 'rbac'
                || $channel === 'quoc-tin.account-provisioning' || $module === 'account_provisioning'
            ) {
                continue;
            }

            // Lọc bưu phẩm (Parcels)
            if ($channel === 'quoc-tin.parcels' || $module === 'parcels') {
                if (empty($allowedApartmentIds)) {
                    continue;
                }

                $aptId = (string) ($ev['apartment_id'] ?? ($parcelAptMap[(string) ($ev['entity_id'] ?? '')] ?? ''));
                if ($aptId === '' || ! in_array($aptId, $allowedApartmentIds, true)) {
                    continue;
                }

                $ev['apartment_id'] = $aptId;
                $filtered[] = $ev;

                continue;
            }

            // Lọc khách đến thăm (Visitors)
            if ($channel === 'quoc-tin.visitors' || $module === 'visitors') {
                if (empty($allowedApartmentIds)) {
                    continue;
                }

                $aptId = (string) ($ev['apartment_id'] ?? ($visitorAptMap[(string) ($ev['registration_id'] ?? '')] ?? ($visitorAptMap[(string) ($ev['entity_id'] ?? '')] ?? '')));
                if ($aptId === '' || ! in_array($aptId, $allowedApartmentIds, true)) {
                    continue;
                }

                $ev['apartment_id'] = $aptId;
                $filtered[] = $ev;

                continue;
            }

            // Các kênh cư dân khác (vehicles, residents, temporary-registrations)
            $filtered[] = $ev;
        }

        return array_values($filtered);
    }

    /**
     * Retrieve events for channels since a specific event ID or millisecond timestamp.
     * Áp dụng Server-side Event Filtering khi người dùng là cư dân.
     *
     * @param  array<string>|string  $channels
     * @return array<int, array<string, mixed>>
     */
    public static function getEvents(
        array|string $channels,
        int $sinceId = 0,
        int|object|null $sinceMs = 0,
        int|object|null $limit = 50,
        ?object $user = null
    ): array {
        // Hỗ trợ cả hai chữ ký gọi:
        // 1. getEvents($channels, $sinceId, $sinceMs, $limit, $user)
        // 2. getEvents($channel, $sinceId, $user)
        if (is_object($sinceMs)) {
            $user = $sinceMs;
            $sinceMs = 0;
            $limit = is_int($limit) ? $limit : 50;
        } elseif (is_object($limit)) {
            $user = $limit;
            $limit = 50;
        }

        $channelList = is_array($channels) ? $channels : [$channels];
        $sinceMsInt = is_int($sinceMs) ? $sinceMs : 0;
        $limitInt = is_int($limit) ? $limit : 50;

        if (empty($channelList)) {
            return [];
        }

        $query = DB::table('realtime_sync_events')
            ->whereIn('channel', $channelList);

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        } elseif ($sinceMsInt > 0) {
            $query->where('created_at_ms', '>', $sinceMsInt);
        }

        $records = $query->orderBy('id', 'asc')
            ->limit($limitInt)
            ->get();

        $events = [];
        foreach ($records as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $payload['id'] = $row->id;
            $payload['channel'] = $row->channel;
            $events[] = $payload;
        }

        // Server-side Event Filtering:
        // Nếu có user:
        // - Nhân viên vận hành (Staff/Admin): Giữ nguyên phạm vi sự kiện đầy đủ
        // - Cư dân hoặc người dùng thường: Lọc chặt chẽ theo căn hộ được phép
        if ($user !== null) {
            if (self::isStaffUser($user)) {
                return $events;
            }

            return self::filterEventsForResident($events, $user);
        }

        // Nếu không truyền $user: Chặn toàn bộ các kênh nhạy cảm để tránh rò rỉ nặc danh
        $hasSensitiveChannel = false;
        foreach ($channelList as $ch) {
            if (in_array($ch, ['quoc-tin.parcels', 'quoc-tin.visitors', 'quoc-tin.rfid-cards', 'quoc-tin.schedules', 'quoc-tin.staff_schedules'], true)) {
                $hasSensitiveChannel = true;
                break;
            }
        }

        if ($hasSensitiveChannel) {
            $safeEvents = [];
            foreach ($events as $ev) {
                $ch = $ev['channel'] ?? '';
                if (! in_array($ch, ['quoc-tin.parcels', 'quoc-tin.visitors', 'quoc-tin.rfid-cards', 'quoc-tin.schedules', 'quoc-tin.staff_schedules'], true)) {
                    $safeEvents[] = $ev;
                }
            }

            return $safeEvents;
        }

        return $events;
    }
}
