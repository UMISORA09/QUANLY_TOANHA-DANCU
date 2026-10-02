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
     * @return array<int, array<string, mixed>>
     */
    public function getDeployments(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDeploymentStatuses(int|string $deploymentId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBranches(): array;

    public function getDefaultBranch(): string;

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function dispatchWorkflow(string $workflowId, string $ref, array $inputs = []): bool;

    public function cancelWorkflowRun(int|string $runId): bool;

    public function retryWorkflowRun(int|string $runId): bool;
}
