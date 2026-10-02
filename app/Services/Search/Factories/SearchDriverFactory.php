<?php

namespace App\Services\Search\Factories;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ElasticsearchDriver;
use App\Services\Search\Drivers\MeilisearchDriver;
use App\Services\Search\Drivers\SmartSearchDriver;
use InvalidArgumentException;

class SearchDriverFactory
{
    /**
     * Tạo driver tương ứng dựa theo cấu hình
     */
    public function make(string $name, array $config = []): SearchDriverInterface
    {
        return match ($name) {
            'smart', 'database', 'ponytail' => new SmartSearchDriver($config),
            'meilisearch' => new MeilisearchDriver($config),
            'elasticsearch' => new ElasticsearchDriver($config),
            default => throw new InvalidArgumentException("Search driver [{$name}] is not supported."),
        };
    }
}
