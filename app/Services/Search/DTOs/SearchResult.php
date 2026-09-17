<?php

namespace App\Services\Search\DTOs;

class SearchResult
{
    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public float $searchTimeMs,
        public string $engine,
        public bool $isFuzzy = false,
        public array $extra = []
    ) {}

    public function __get(string $name): mixed
    {
        if ($name === 'metadata') {
            return $this->extra;
        }

        return null;
    }

    public function getTotalPages(): int
    {
        return $this->total > 0 ? (int) ceil($this->total / $this->perPage) : 1;
    }

    /**
     * Chuyển đổi thành Array theo chuẩn API Response Contract
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => $this->getTotalPages(),
                'search_time_ms' => round($this->searchTimeMs, 2),
                'engine' => $this->engine,
                'is_fuzzy' => $this->isFuzzy,
                ...$this->extra,
            ],
        ];
    }
}
