<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ModuleCooldownException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $cooldownData
     */
    public function __construct(
        public string $module,
        public array $cooldownData = [],
        string $message = 'Chức năng đang tạm khóa chỉnh sửa. Vui lòng thử lại sau.'
    ) {
        parent::__construct($message, 409);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(): JsonResponse
    {
        $untilTs = (int) ($this->cooldownData['cooldown_until_ts'] ?? 0);
        $retryAfter = max(1, $untilTs - time());

        return response()->json([
            'success' => false,
            'error' => 'COOLDOWN_ACTIVE',
            'message' => $this->getMessage(),
            'module' => $this->module,
            'cooldown_until' => $this->cooldownData['cooldown_until'] ?? null,
            'retry_after' => $retryAfter,
            'cooldown_seconds' => (int) ($this->cooldownData['cooldown_seconds'] ?? 120),
            'actor_id' => $this->cooldownData['actor_id'] ?? null,
        ], 409);
    }
}
