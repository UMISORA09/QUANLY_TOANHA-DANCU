<?php

namespace App\Services\Cicd\Contracts;

interface VercelApiClientInterface
{
    /**
     * Lấy danh sách deployments từ Vercel API
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeployments(int $limit = 5): array;
}
