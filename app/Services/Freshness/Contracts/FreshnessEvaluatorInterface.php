<?php

namespace App\Services\Freshness\Contracts;

use Carbon\Carbon;

interface FreshnessEvaluatorInterface
{
    /**
     * Thực hiện đánh giá mức độ tươi mới của nguồn dữ liệu tương ứng
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evaluate(Carbon $now, array $options = []): array;
}
