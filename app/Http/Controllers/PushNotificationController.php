<?php

namespace App\Http\Controllers;

use App\Models\WebPushSubscription;
use App\Services\RbacService;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PushNotificationController extends Controller
{
    public function __construct(
        protected WebPushService $pushService
    ) {}

    /**
     * Đăng ký hoặc cập nhật Web Push Subscription của Cư dân
     */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn cần đăng nhập để đăng ký nhận thông báo.',
            ], 401);
        }

        $validated = $request->validate([
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'content_encoding' => 'nullable|string|in:aes128gcm,aesgcm',
        ]);

        $endpoint = $validated['endpoint'];
        $endpointHash = hash('sha256', $endpoint);

        try {
            $subscription = WebPushSubscription::updateOrCreate(
                ['endpoint_hash' => $endpointHash],
                [
                    'user_id' => $user->id,
                    'endpoint' => $endpoint,
                    'public_key' => $validated['keys']['p256dh'],
                    'auth_token' => $validated['keys']['auth'],
                    'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                    'is_active' => true,
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                    'last_used_at' => Carbon::now(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký nhận thông báo Web Push thành công.',
                'subscription_id' => $subscription->id,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lưu thông tin đăng ký thông báo: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hủy đăng ký hoặc vô hiệu hóa Web Push Subscription của Cư dân
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn cần đăng nhập để thao tác.',
            ], 401);
        }

        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $endpointHash = hash('sha256', $validated['endpoint']);

        // Chỉ cho phép người dùng vô hiệu hóa subscription của chính mình (chống can thiệp người khác)
        $affected = WebPushSubscription::where('user_id', $user->id)
            ->where(function ($q) use ($validated, $endpointHash) {
                $q->where('endpoint_hash', $endpointHash)
                    ->orWhere('endpoint', $validated['endpoint']);
            })
            ->update([
                'is_active' => false,
                'last_used_at' => Carbon::now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => $affected > 0 ? 'Đã hủy đăng ký nhận thông báo thành công.' : 'Không tìm thấy subscription tương ứng của bạn.',
            'affected' => $affected,
        ], 200);
    }

    /**
     * Kiểm tra trạng thái đăng ký Web Push của tài khoản hiện tại
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'is_subscribed' => false,
                'count' => 0,
            ], 200);
        }

        $activeCount = WebPushSubscription::where('user_id', $user->id)
            ->active()
            ->count();

        return response()->json([
            'success' => true,
            'is_subscribed' => $activeCount > 0,
            'active_devices' => $activeCount,
        ], 200);
    }

    /**
     * Lấy VAPID Public Key để frontend khởi tạo push subscription
     */
    public function vapidPublicKey(): JsonResponse
    {
        $config = $this->pushService->getVapidConfig();

        return response()->json([
            'success' => true,
            'public_key' => $config['public_key'],
            'is_configured' => $this->pushService->isVapidConfigured(),
        ], 200);
    }
}
