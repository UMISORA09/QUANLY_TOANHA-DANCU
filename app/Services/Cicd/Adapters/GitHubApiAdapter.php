<?php

namespace App\Services\Cicd\Adapters;

use App\Services\Cicd\Contracts\GitHubApiClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubApiAdapter implements GitHubApiClientInterface
{
    protected string $owner;

    protected string $repo;

    protected ?string $token;

    protected string $apiBase;

    public function __construct(
        ?string $owner = null,
        ?string $repo = null,
        ?string $token = null
    ) {
        $this->owner = $owner ?: (config('services.github.owner') ?: 'UMISORA09');
        $this->repo = $repo ?: (config('services.github.repo') ?: 'QUANLY_TOANHA-DANCU');
        $this->token = $token ?: config('services.github.token');
        $this->apiBase = "https://api.github.com/repos/{$this->owner}/{$this->repo}";
    }

    /**
     * @return PendingRequest
     */
    protected function client()
    {
        $client = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'SmartCassavas-CICD/1.0',
        ])->timeout(10);

        if (! empty($this->token)) {
            $client = $client->withToken($this->token);
        }

        return $client;
    }

    public function get(string $endpoint, array $query = []): array
    {
        $url = str_starts_with($endpoint, 'http') ? $endpoint : "{$this->apiBase}/".ltrim($endpoint, '/');
        $res = $this->client()->get($url, $query);

        return $res->successful() ? (array) $res->json() : [];
    }

    public function post(string $endpoint, array $data = []): array
    {
        $url = str_starts_with($endpoint, 'http') ? $endpoint : "{$this->apiBase}/".ltrim($endpoint, '/');
        $res = $this->client()->post($url, $data);

        return $res->successful() ? (array) $res->json() : [];
    }

    public function getRepositoryInfo(): array
    {
        return $this->get('');
    }

    public function getWorkflowRuns(array $params = []): array
    {
        return $this->get('actions/runs', $params);
    }

    public function getDeployments(): array
    {
        $res = $this->get('deployments', ['per_page' => 20]);

        return is_array($res) ? $res : [];
    }

    public function getDeploymentStatuses(int|string $deploymentId): array
    {
        $res = $this->get("deployments/{$deploymentId}/statuses");

        return is_array($res) ? $res : [];
    }

    public function getBranches(): array
    {
        $res = $this->get('branches', ['per_page' => 30]);

        return is_array($res) ? $res : [];
    }

    public function getDefaultBranch(): string
    {
        $configBranch = config('services.github.deploy_branch');
        if (! empty($configBranch)) {
            return (string) $configBranch;
        }

        $repoData = $this->getRepositoryInfo();

        return (string) ($repoData['default_branch'] ?? 'master');
    }

    public function dispatchWorkflow(string $workflowId, string $ref, array $inputs = []): bool
    {
        $url = "{$this->apiBase}/actions/workflows/{$workflowId}/dispatches";
        $res = $this->client()->post($url, [
            'ref' => $ref,
            'inputs' => $inputs,
        ]);

        return $res->successful();
    }

    public function cancelWorkflowRun(int|string $runId): bool
    {
        $url = "{$this->apiBase}/actions/runs/{$runId}/cancel";
        $res = $this->client()->post($url);

        return $res->successful();
    }

    public function retryWorkflowRun(int|string $runId): bool
    {
        $url = "{$this->apiBase}/actions/runs/{$runId}/rerun";
        $res = $this->client()->post($url);

        return $res->successful();
    }
}
