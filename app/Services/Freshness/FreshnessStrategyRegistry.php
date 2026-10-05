<?php

namespace App\Services\Freshness;

use App\Services\Freshness\Contracts\FreshnessSourceStrategyInterface;
use InvalidArgumentException;

class FreshnessStrategyRegistry
{
    /**
     * @var array<string, FreshnessSourceStrategyInterface>
     */
    protected array $strategies = [];

    /**
     * @param  array<int|string, FreshnessSourceStrategyInterface>  $strategies
     */
    public function __construct(array $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->register($strategy);
        }
    }

    public function register(FreshnessSourceStrategyInterface $strategy): self
    {
        $this->strategies[$strategy->source()] = $strategy;

        return $this;
    }

    public function for(string $source): FreshnessSourceStrategyInterface
    {
        $strategy = $this->get($source);
        if (! $strategy) {
            throw new InvalidArgumentException("Không tìm thấy chiến lược đánh giá cho nguồn [{$source}].");
        }

        return $strategy;
    }

    public function get(string $source): ?FreshnessSourceStrategyInterface
    {
        return $this->strategies[$source] ?? null;
    }

    /**
     * @return array<string, FreshnessSourceStrategyInterface>
     */
    public function all(): array
    {
        return $this->strategies;
    }
}
