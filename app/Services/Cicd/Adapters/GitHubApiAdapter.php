<?php

namespace App\Services\Cicd\Adapters;

use App\Services\Cicd\Contracts\GitHubApiClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    public function getWorkflowRunsWithStatus(array $params = []): array
    {
        try {
            $response = $this->client()->get("{$this->apiBase}/actions/runs", $params);
            $statusCode = $response->status();

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'status' => $statusCode,
                    'data' => is_array($data) ? $data : [],
                    'error' => null,
                ];
            }

            $errorMsg = match ($statusCode) {
                401 => 'Bad credentials (check GITHUB_TOKEN)',
                403 => 'Forbidden / Rate limited / Missing permissions (Resource not accessible by personal access token)',
                404 => 'Repository not found or token lacks workflow scope',
                default => 'GitHub API error HTTP '.$statusCode,
            };

            return [
                'success' => false,
                'status' => $statusCode,
                'data' => [],
                'error' => $errorMsg,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'data' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getWorkflowRunJobs(int|string $runId): array
    {
        try {
            $response = $this->client()->get("{$this->apiBase}/actions/runs/{$runId}/jobs");
            if ($response->successful()) {
                $data = $response->json();

                return is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
            }
        } catch (Throwable) {
        }

        return [];
    }

    public function getWorkflowRunLogs(int|string $runId, ?string $jobId = null): ?string
    {
        try {
            $client = $this->client()->timeout(15);
            $url = $jobId
                ? "{$this->apiBase}/actions/jobs/{$jobId}/logs"
                : "{$this->apiBase}/actions/runs/{$runId}/logs";

            $response = $client->get($url);
            if ($response->successful()) {
                return $response->body();
            }
        } catch (Throwable) {
        }

        return null;
    }

    public function getWorkflowRunsByWorkflow(string $workflowId, array $params = []): array
    {
        try {
            $response = $this->client()->get("{$this->apiBase}/actions/workflows/{$workflowId}/runs", $params);
            if ($response->successful()) {
                $data = $response->json();

                return is_array($data['workflow_runs'] ?? null) ? $data['workflow_runs'] : [];
            }
        } catch (Throwable) {
        }

        return [];
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

    public function getDeploymentsWithStatuses(int $limit = 5): array
    {
        try {
            $deploymentsRes = $this->client()->get("{$this->apiBase}/deployments", [
                'per_page' => $limit,
            ]);

            if (! $deploymentsRes->successful()) {
                throw new \RuntimeException('GitHub Deployments API HTTP '.$deploymentsRes->status());
            }

            $deployments = $deploymentsRes->json();
            if (! is_array($deployments) || empty($deployments)) {
                return [];
            }

            $apiBase = $this->apiBase;
            $token = $this->token;

            $responses = Http::pool(function (Pool $pool) use ($deployments, $apiBase, $token) {
                $requests = [];
                foreach ($deployments as $dep) {
                    $id = $dep['id'] ?? null;
                    if ($id) {
                        $req = $pool->as((string) $id)
                            ->withHeaders([
                                'Accept' => 'application/vnd.github.v3+json',
                                'User-Agent' => 'SmartCassavas-CICD/1.0',
                            ])
                            ->timeout(5);

                        if (! empty($token)) {
                            $req = $req->withToken($token);
                        }

                        $requests[] = $req->get("{$apiBase}/deployments/{$id}/statuses");
                    }
                }

                return $requests;
            });

            $result = [];
            foreach ($deployments as $dep) {
                $id = (string) ($dep['id'] ?? '');
                $statuses = [];
                if (isset($responses[$id]) && $responses[$id]->successful()) {
                    $statuses = is_array($responses[$id]->json()) ? $responses[$id]->json() : [];
                } else {
                    throw new \RuntimeException('GitHub deployment statuses unavailable');
                }
                $result[] = [
                    'deployment' => $dep,
                    'statuses' => $statuses,
                ];
            }

            return $result;
        } catch (Throwable $e) {
            throw new \RuntimeException('GitHub Deployments API unavailable', 0, $e);
        }
    }

    public function getBranches(): array
    {
        $res = $this->get('branches', ['per_page' => 30]);

        return is_array($res) ? $res : [];
    }

    public function getCommits(array $params = []): array
    {
        try {
            $response = $this->client()->get("{$this->apiBase}/commits", $params);
            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [];
            }
        } catch (Throwable) {
        }

        return [];
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

    public function dispatchWorkflowExtended(string $workflowId, string $ref, array $inputs = []): array
    {
        try {
            $url = "{$this->apiBase}/actions/workflows/{$workflowId}/dispatches";
            $response = $this->client()->post($url, [
                'ref' => $ref,
                'inputs' => $inputs,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'error' => null];
            }

            $msg = $response->json('message') ?? $response->body();

            return ['success' => false, 'error' => "GitHub API ({$response->status()}): {$msg}"];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function dispatchWorkflow(string $workflowId, string $ref, array $inputs = []): bool
    {
        $res = $this->dispatchWorkflowExtended($workflowId, $ref, $inputs);

        return $res['success'];
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

    public function probeEndpoint(string $url, int $timeout = 2): ?array
    {
        $start = microtime(true);
        try {
            $response = Http::timeout($timeout)->get($url);
            $elapsedMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'successful' => $response->successful(),
                'status' => $response->status(),
                'json' => $response->json(),
                'elapsed_ms' => $elapsedMs,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
