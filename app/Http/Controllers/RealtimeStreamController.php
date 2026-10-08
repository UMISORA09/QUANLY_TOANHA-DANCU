<?php

namespace App\Http\Controllers;

use App\Services\QuocTinRealtimeService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealtimeStreamController extends Controller
{
    /**
     * Parse and authorize requested channels for the current user
     *
     * @return array<string>
     */
    protected function getAuthorizedChannels(Request $request, ?object $user): array
    {
        $rawChannels = (string) $request->input('channels', '');
        $requested = array_values(array_filter(array_map('trim', explode(',', $rawChannels))));

        if (empty($requested)) {
            // Default to all 5 channels if not explicitly specified
            $requested = [
                'quoc-tin.rbac',
                'quoc-tin.residents',
                'quoc-tin.temporary-registrations',
                'quoc-tin.account-provisioning',
                'quoc-tin.vehicles',
            ];
        }

        $authorized = [];
        foreach ($requested as $ch) {
            $normalized = str_starts_with($ch, 'quoc-tin.') ? $ch : QuocTinRealtimeService::getChannelForModule($ch);
            if (QuocTinRealtimeService::isUserAuthorizedForChannel($user, $normalized)) {
                $authorized[] = $normalized;
            }
        }

        return array_values(array_unique($authorized));
    }

    /**
     * SSE Stream Endpoint: GET /api/realtime/stream
     */
    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        $user = RbacService::resolveUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $authorizedChannels = $this->getAuthorizedChannels($request, $user);
        if (empty($authorizedChannels)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: You do not have permission for the requested channels.',
            ], 403);
        }

        $sinceId = (int) $request->input('since_id', 0);
        $sinceMs = (int) $request->input('since_ms', 0);

        // Giải phóng khóa session PHP ngay lập tức để không block các request mutation khác
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return response()->stream(function () use ($authorizedChannels, $sinceId, $sinceMs) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }

            // Disable output buffering
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('implicit_flush', '1');

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            ob_implicit_flush(true);

            // Gửi 2KB padding để ép trình duyệt và web server/proxy xả buffer chunk ngay lập tức
            echo ': '.str_repeat(' ', 2048)."\n\n";
            @ob_flush();
            @flush();

            // Initial connection acknowledgment
            $connectData = [
                'type' => 'connected',
                'channels' => $authorizedChannels,
                'timestamp' => (int) (microtime(true) * 1000),
            ];
            echo "event: connected\ndata: ".json_encode($connectData, JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            @flush();

            $lastEventId = $sinceId;

            // Catch-up initial missed events
            if ($sinceId > 0 || $sinceMs > 0) {
                $backlog = QuocTinRealtimeService::getEvents($authorizedChannels, $sinceId, $sinceMs, 50);
                foreach ($backlog as $ev) {
                    echo "id: {$ev['id']}\nevent: QuocTinEvent\ndata: ".json_encode($ev, JSON_UNESCAPED_UNICODE)."\n\n";
                    $lastEventId = max($lastEventId, (int) $ev['id']);
                }
                @ob_flush();
                @flush();
            }

            // Stream loop: Chạy tối đa 2.0s hoặc thoát ngay khi có event mới
            // Điều này đảm bảo PHP CLI Server (php -S) gửi toàn bộ bytes qua socket ngay lập tức mà không buffer
            $startTime = microtime(true);

            while ((microtime(true) - $startTime) < 2.0) {
                if (connection_aborted()) {
                    break;
                }

                // Query new events since $lastEventId
                $newEvents = QuocTinRealtimeService::getEvents($authorizedChannels, $lastEventId, 0, 20);
                if (! empty($newEvents)) {
                    foreach ($newEvents as $ev) {
                        echo "id: {$ev['id']}\nevent: QuocTinEvent\ndata: ".json_encode($ev, JSON_UNESCAPED_UNICODE)."\n\n";
                        $lastEventId = max($lastEventId, (int) $ev['id']);
                    }
                    @ob_flush();
                    @flush();
                    // Thoát vòng lặp ngay khi có event để client B nhận ngay lập tức!
                    break;
                }

                // Kiểm tra lại sau 50ms
                usleep(50000);
            }

            // Graceful cycle signal for auto-reconnect
            echo "event: cycle\ndata: ".json_encode(['last_id' => $lastEventId, 'reconnect' => true], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            @flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'none',
        ]);
    }

    /**
     * Fast Poll / Catch-Up Endpoint: GET /api/realtime/events
     */
    public function events(Request $request): JsonResponse
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        $user = RbacService::resolveUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $authorizedChannels = $this->getAuthorizedChannels($request, $user);
        if (empty($authorizedChannels)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: You do not have permission for the requested channels.',
            ], 403);
        }

        $sinceId = (int) $request->input('since_id', 0);
        $sinceMs = (int) $request->input('since_ms', 0);
        $limit = min((int) $request->input('limit', 50), 100);

        $events = QuocTinRealtimeService::getEvents($authorizedChannels, $sinceId, $sinceMs, $limit);

        return response()->json([
            'success' => true,
            'events' => $events,
            'channels' => $authorizedChannels,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * Get active cooldown status for modules: GET /api/realtime/cooldown
     */
    public function cooldown(Request $request): JsonResponse
    {
        $modulesParam = $request->input('modules', $request->input('module', 'all'));
        $modules = $modulesParam === 'all'
            ? ['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles']
            : explode(',', (string) $modulesParam);

        $results = [];
        foreach ($modules as $m) {
            $canon = QuocTinRealtimeService::normalizeModuleName(trim($m));
            $cd = QuocTinRealtimeService::getModuleCooldown($canon);
            $results[$canon] = [
                'is_in_cooldown' => $cd !== null,
                'cooldown_until' => $cd['cooldown_until'] ?? null,
                'retry_after' => (int) ($cd['retry_after'] ?? 0),
                'cooldown_seconds' => (int) ($cd['cooldown_seconds'] ?? 0),
                'actor_id' => $cd['actor_id'] ?? null,
                'action' => $cd['action'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
    }
}
