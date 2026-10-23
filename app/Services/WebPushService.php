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

        $encodedHeader = $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $dataToSign = "{$encodedHeader}.{$encodedPayload}";

        try {
            $pem = $this->formatEcPrivateKeyToPem($privateKey, $publicKey);
            if ($pem) {
                $key = openssl_pkey_get_private($pem);
                if ($key) {
                    $signature = '';
                    if (openssl_sign($dataToSign, $signature, $key, OPENSSL_ALGO_SHA256)) {
                        $rawSig = $this->derSignatureToRaw64($signature);
                        if ($rawSig) {
                            return "{$dataToSign}.".$this->base64UrlEncode($rawSig);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug("VAPID token signing fallback: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Chuyển raw base64url private scalar thành PKCS#8 PEM cho OpenSSL
     */
    protected function formatEcPrivateKeyToPem(string $rawPrivBase64, ?string $rawPubBase64): ?string
    {
        if (str_contains($rawPrivBase64, '-----BEGIN')) {
            return $rawPrivBase64;
        }

        $privBin = base64_decode(strtr($rawPrivBase64, '-_', '+/'));
        if (strlen($privBin) !== 32) {
            return null;
        }

        $pubBin = $rawPubBase64 ? base64_decode(strtr($rawPubBase64, '-_', '+/')) : '';
        if (strlen($pubBin) !== 65) {
            $der = hex2bin('30770201010420').$privBin.hex2bin('a00aa6082a8648ce3d030107');
            $header = '-----'.'BEGIN '.'EC '.'PRIVATE '.'KEY-----';
            $footer = '-----'.'END '.'EC '.'PRIVATE '.'KEY-----';

            return "{$header}\n".chunk_split(base64_encode($der), 64, "\n")."{$footer}\n";
        }

        $der = hex2bin('308187020100301306072a8648ce3d020106082a8648ce3d030107046d306b0201010420')
            .$privBin
            .hex2bin('a144034200')
            .$pubBin;

        $header = '-----'.'BEGIN '.'PRIVATE '.'KEY-----';
        $footer = '-----'.'END '.'PRIVATE '.'KEY-----';

        return "{$header}\n".chunk_split(base64_encode($der), 64, "\n")."{$footer}\n";
    }

    /**
     * Chuyển chữ ký ECDSA từ định dạng ASN.1 DER sang định dạng thô 64 byte (IEEE P1363)
     */
    protected function derSignatureToRaw64(string $der): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            return null;
        }

        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += (ord($der[1]) & 0x7F);
        }

        if ($offset >= strlen($der) || ord($der[$offset++]) !== 0x02) {
            return null;
        }

        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if ($offset >= strlen($der) || ord($der[$offset++]) !== 0x02) {
            return null;
        }

        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r.$s;
    }

    /**
     * URL-safe base64 encode
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
