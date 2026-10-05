<?php

namespace App\Services\Freshness\Contracts;

use Carbon\Carbon;

interface FreshnessSourceStrategyInterface
{
    /**
     * Tên định danh duy nhất của nguồn quan sát
     */
    public function source(): string;

    /**
     * Thực hiện đánh giá mức độ tươi mới của nguồn dữ liệu
     *
     * @return array<string, mixed>
     */
    public function evaluate(Carbon $now, bool $force = false): array;
}
