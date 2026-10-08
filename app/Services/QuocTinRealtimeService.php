<?php

namespace App\Services;

use App\Events\QuocTinRealtimeEvent;
use App\Exceptions\ModuleCooldownException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuocTinRealtimeService
{
    public const DEFAULT_COOLDOWN_SECONDS = 120;

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
     * Start/Set a shared edit cooldown for a specific module on the server
     *
     * @return array<string, mixed>
     */
    public static function setModuleCooldown(
        string $module,
        int $seconds = self::DEFAULT_COOLDOWN_SECONDS,
        ?string $actorId = null,
        string $action = 'MUTATION'
    ): array {
        $canon = self::normalizeModuleName($module);
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

    /**
     * Check if a user is authorized to listen to a specific channel
     */
    public static function isUserAuthorizedForChannel(?object $user, string $channel): bool
    {
        if (! $user) {
            return false;
        }

        // Super Admin & Admin have access to all 5 channels
        if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
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

            default => false,
        };
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
            $cooldownData = self::setModuleCooldown($canonicalModule, self::DEFAULT_COOLDOWN_SECONDS, $actorId, strtoupper($action));
            $extra['cooldown_until'] = $cooldownData['cooldown_until'];
            $extra['cooldown_seconds'] = self::DEFAULT_COOLDOWN_SECONDS;
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
                $cooldownEventData = [
                    'module' => $canonicalModule,
                    'entity' => $entity,
                    'action' => 'EDIT_COOLDOWN_STARTED',
                    'entity_id' => $entityId,
                    'actor_id' => $actorId,
                    'cooldown_until' => $cooldownData['cooldown_until'],
                    'cooldown_seconds' => self::DEFAULT_COOLDOWN_SECONDS,
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
     * Retrieve events for channels since a specific event ID or millisecond timestamp
     *
     * @param  array<string>  $channels
     * @return array<int, array<string, mixed>>
     */
    public static function getEvents(
        array $channels,
        int $sinceId = 0,
        int $sinceMs = 0,
        int $limit = 50
    ): array {
        if (empty($channels)) {
            return [];
        }

        $query = DB::table('realtime_sync_events')
            ->whereIn('channel', $channels);

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        } elseif ($sinceMs > 0) {
            $query->where('created_at_ms', '>', $sinceMs);
        }

        $records = $query->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        $events = [];
        foreach ($records as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $payload['id'] = $row->id;
            $payload['channel'] = $row->channel;
            $events[] = $payload;
        }

        return $events;
    }
}
