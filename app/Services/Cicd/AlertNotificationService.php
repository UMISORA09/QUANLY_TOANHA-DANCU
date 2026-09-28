<?php

namespace App\Services\Cicd;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertNotificationService
{
    /**
     * Gửi cảnh báo sự cố tới Discord hoặc Slack thông qua Webhook.
     *
     * @param  string  $severity  'critical' | 'warning' | 'info' | 'resolved'
     * @param  string  $title  Tiêu đề thông báo
     * @param  string  $message  Nội dung chi tiết
     * @param  array<string, mixed>  $metadata  Các thông số bổ sung (Service, Metric, Threshold, Commit, etc.)
     */
    public function sendAlert(string $severity, string $title, string $message, array $metadata = []): bool
    {
        $discordWebhook = env('DISCORD_WEBHOOK_URL');
        $slackWebhook = env('SLACK_WEBHOOK_URL');

        if (empty($discordWebhook) && empty($slackWebhook)) {
            Log::info("DevOps Alert ($severity): $title - $message", $metadata);

            return false;
        }

        $colorMap = [
            'critical' => 15158332, // Đỏ (#E74C3C)
            'warning' => 15105570,  // Cam (#E67E22)
            'info' => 3447003,      // Xanh dương (#3498DB)
            'resolved' => 3066993,  // Xanh lá (#2ECC71)
        ];

        $color = $colorMap[strtolower($severity)] ?? 3447003;

        // Gửi tới Discord nếu cấu hình
        if (! empty($discordWebhook)) {
            try {
                $fields = [];
                foreach ($metadata as $key => $value) {
                    $fields[] = [
                        'name' => ucfirst(str_replace('_', ' ', $key)),
                        'value' => (string) $value,
                        'inline' => true,
                    ];
                }

                Http::timeout(5)->post($discordWebhook, [
                    'embeds' => [
                        [
                            'title' => $title,
                            'description' => $message,
                            'color' => $color,
                            'fields' => $fields,
                            'footer' => [
                                'text' => 'SMART CASSAVAS DevOps Alerting System',
                            ],
                            'timestamp' => now()->toIso8601String(),
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send Discord alert: '.$e->getMessage());
            }
        }

        // Gửi tới Slack nếu cấu hình
        if (! empty($slackWebhook)) {
            try {
                Http::timeout(5)->post($slackWebhook, [
                    'text' => "*$title*\n$message",
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send Slack alert: '.$e->getMessage());
            }
        }

        return true;
    }
}
