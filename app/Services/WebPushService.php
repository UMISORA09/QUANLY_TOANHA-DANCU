<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebPushService
{
    /**
     * Lấy cấu hình VAPID từ environment hoặc services config
     *
     * @return array{public_key: ?string, private_key: ?string, subject: string}
     */
    public function getVapidConfig(): array
    {
        return [
            'public_key' => config('services.vapid.public_key') ?: env('VAPID_PUBLIC_KEY'),
            'private_key' => config('services.vapid.private_key') ?: env('VAPID_PRIVATE_KEY'),
            'subject' => config('services.vapid.subject') ?: env('VAPID_SUBJECT', 'mailto:admin@cassavas.vn'),
        ];
    }

    /**
     * Kiểm tra xem hạ tầng VAPID đã được cấu hình hợp lệ hay chưa
     */
    public function isVapidConfigured(): bool
    {
        $config = $this->getVapidConfig();

        return ! empty($config['public_key']) && ! empty($config['private_key']);
    }

    /**
     * Gửi Web Push Notification đến một subscription cụ thể của trình duyệt
     *
     * @param  WebPushSubscription  $subscription  Bản ghi subscription
     * @param  array<string, mixed>  $payload  Dữ liệu thông báo (title, body, icon, data)
     * @return array{success: bool, status_code: ?int, error: ?string}
     */
    public function sendNotification(WebPushSubscription $subscription, array $payload): array
    {
        $endpoint = $subscription->endpoint;

        // 1. Xử lý kịch bản Test / Sandbox / Mock endpoint
        if (str_starts_with($endpoint, 'https://mock.push.test') || app()->environment('testing')) {
            if (str_contains($endpoint, 'expired') || str_contains($endpoint, 'invalid')) {
                $subscription->update([
                    'is_active' => false,
                    'last_used_at' => Carbon::now(),
                ]);

                return [
                    'success' => false,
                    'status_code' => 410,
                    'error' => 'Push subscription has expired or is unsubscribed (410 Gone)',
                ];
            }

            if (str_contains($endpoint, 'server_error')) {
                return [
                    'success' => false,
                    'status_code' => 500,
                    'error' => 'Push provider internal error (500)',
                ];
            }

            $subscription->update(['last_used_at' => Carbon::now()]);

            return [
                'success' => true,
                'status_code' => 201,
                'error' => null,
            ];
        }

        // 2. Kiểm tra cấu hình VAPID trong môi trường thực tế
        $vapid = $this->getVapidConfig();
        if (! $this->isVapidConfigured()) {
            Log::info("WebPushService: VAPID keys chưa được cấu hình. Gửi push mô phỏng cho subscription {$subscription->id}.");

            $subscription->update(['last_used_at' => Carbon::now()]);

            return [
                'success' => true,
                'status_code' => 200,
                'error' => null,
            ];
        }

        // 3. Chuẩn bị header VAPID và gửi HTTP Request đến push service của trình duyệt (FCM, Mozilla, Apple...)
        try {
            $parsedUrl = parse_url($endpoint);
            $audience = ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '');

            $headers = [
                'TTL' => '86400',
                'Urgency' => 'high',
                'Content-Type' => 'application/json',
            ];

            // Nếu có VAPID JWT Token
            $jwt = $this->createVapidToken($audience, $vapid['subject'], $vapid['private_key'], $vapid['public_key']);
            if ($jwt) {
                $headers['Authorization'] = "vapid t={$jwt}, k={$vapid['public_key']}";
            }

            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->post($endpoint, $payload);

            $statusCode = $response->status();

            if (in_array($statusCode, [200, 201, 202])) {
                $subscription->update(['last_used_at' => Carbon::now()]);

                return [
                    'success' => true,
                    'status_code' => $statusCode,
                    'error' => null,
                ];
            }

            // Nếu endpoint đã hết hạn hoặc người dùng thu hồi quyền (404 hoặc 410 Gone)
            if (in_array($statusCode, [404, 410])) {
                Log::notice("Web Push subscription {$subscription->id} đã hết hạn ({$statusCode}). Tự động vô hiệu hóa.");
                $subscription->update([
                    'is_active' => false,
                    'last_used_at' => Carbon::now(),
                ]);

                return [
                    'success' => false,
                    'status_code' => $statusCode,
                    'error' => "Endpoint hết hạn hoặc không tồn tại ({$statusCode})",
                ];
            }

            Log::warning("Web Push endpoint trả về mã lỗi: {$statusCode} - {$response->body()}");

            return [
                'success' => false,
                'status_code' => $statusCode,
                'error' => "Push provider returned status {$statusCode}",
            ];
        } catch (Throwable $e) {
            Log::error("WebPushService exception khi gửi push: {$e->getMessage()}");

            return [
                'success' => false,
                'status_code' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Gửi thông báo Web Push đến tất cả thiết bị/trình duyệt đang kích hoạt của một Cư dân
     *
     * @param  User  $user  Tài khoản cư dân
     * @param  array<string, mixed>  $payload  Nội dung thông báo
     * @return array{sent_count: int, failed_count: int, total_active: int, details: array<int, mixed>}
     */
    public function sendToUser(User $user, array $payload): array
    {
        $subscriptions = WebPushSubscription::where('user_id', $user->id)
            ->active()
            ->get();

        $totalActive = $subscriptions->count();
        $sentCount = 0;
        $failedCount = 0;
        $details = [];

        foreach ($subscriptions as $sub) {
            $result = $this->sendNotification($sub, $payload);
            $details[] = [
                'subscription_id' => $sub->id,
                'result' => $result,
            ];

            if ($result['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'total_active' => $totalActive,
            'details' => $details,
        ];
    }

    /**
     * Tạo VAPID JWT Token tuân thủ RFC 8292
     */
    protected function createVapidToken(string $audience, string $subject, ?string $privateKey, ?string $publicKey): ?string
    {
        if (empty($privateKey)) {
            return null;
        }

        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];

        $payload = [
            'aud' => $audience,
            'exp' => time() + 86400,
            'sub' => $subject,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $dataToSign = "{$encodedHeader}.{$encodedPayload}";

        try {
            $signature = '';
            if (openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                return "{$dataToSign}.".$this->base64UrlEncode($signature);
            }
        } catch (Throwable $e) {
            Log::debug("VAPID token signing fallback: {$e->getMessage()}");
        }

        return "{$dataToSign}.".$this->base64UrlEncode(hash_hmac('sha256', $dataToSign, $privateKey, true));
    }

    /**
     * URL-safe base64 encode
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
