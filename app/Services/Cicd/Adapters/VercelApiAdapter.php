<?php

namespace App\Services\Cicd\Adapters;

use App\Services\Cicd\Contracts\VercelApiClientInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class VercelApiAdapter implements VercelApiClientInterface
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $projectId = null
    ) {
        $this->token = $this->token ?: (string) env('VERCEL_TOKEN');
        $this->projectId = $this->projectId ?: (string) env('VERCEL_PROJECT_ID');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDeployments(int $limit = 5): array
    {
        if (empty($this->token) || empty($this->projectId)) {
            return [];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(3)
                ->get('https://api.vercel.com/v6/deployments', [
                    'projectId' => $this->projectId,
                    'limit' => $limit,
                ]);

            if ($response->successful()) {
                $deployments = $response->json('deployments');

                return is_array($deployments) ? $deployments : [];
            }
        } catch (Throwable) {
            // Gracefully handle network / auth issues
        }

        return [];
    }

    public function probeDeploymentHealth(string $url, int $timeout = 3): bool
    {
        try {
            $resp = Http::timeout($timeout)->withoutVerifying()->get($url);

            return $resp->successful() || in_array($resp->status(), [401, 403], true);
        } catch (Throwable) {
            return false;
        }
    }
}
