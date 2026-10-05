<?php

namespace App\Services\Cicd\Contracts;

interface GitHubApiClientInterface
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = []): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getRepositoryInfo(): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getWorkflowRuns(array $params = []): array;

    /**
     * Lấy kết quả workflow runs có đầy đủ metadata status và error message
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, status: int, data: array<string, mixed>, error: ?string}
     */
    public function getWorkflowRunsWithStatus(array $params = []): array;

    /**
     * Lấy danh sách jobs của một workflow run
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWorkflowRunJobs(int|string $runId): array;

    /**
     * Lấy log nội dung của một workflow run hoặc job
     */
    public function getWorkflowRunLogs(int|string $runId, ?string $jobId = null): ?string;

    /**
     * Lấy danh sách workflow runs theo workflow ID cụ thể
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function getWorkflowRunsByWorkflow(string $workflowId, array $params = []): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDeployments(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDeploymentStatuses(int|string $deploymentId): array;

    /**
     * Lấy deployments kèm statuses được tải song song qua HTTP Pool
     *
     * @return array<int, array{deployment: array<string, mixed>, statuses: array<int, array<string, mixed>>}>
     */
    public function getDeploymentsWithStatuses(int $limit = 5): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBranches(): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function getCommits(array $params = []): array;

    public function getDefaultBranch(): string;

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{success: bool, error: ?string}
     */
    public function dispatchWorkflowExtended(string $workflowId, string $ref, array $inputs = []): array;

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function dispatchWorkflow(string $workflowId, string $ref, array $inputs = []): bool;

    public function cancelWorkflowRun(int|string $runId): bool;

    public function retryWorkflowRun(int|string $runId): bool;

    /**
     * Kiểm tra endpoint HTTP (health check / ping)
     *
     * @return array{successful: bool, status: int, json: mixed, elapsed_ms: float}|null
     */
    public function probeEndpoint(string $url, int $timeout = 2): ?array;
}
