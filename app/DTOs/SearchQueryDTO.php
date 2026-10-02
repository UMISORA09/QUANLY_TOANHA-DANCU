<?php

namespace App\DTOs;

final readonly class SearchQueryDTO
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $index,
        public string $query,
        public array $filters = [],
        public array $options = []
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (string) ($data['index'] ?? 'amenities'),
            query: (string) ($data['query'] ?? ($data['q'] ?? ($data['search'] ?? ''))),
            filters: (array) ($data['filters'] ?? []),
            options: (array) ($data['options'] ?? [
                'page' => (int) ($data['page'] ?? 1),
                'limit' => (int) ($data['limit'] ?? ($data['per_page'] ?? 10)),
                'sort' => (string) ($data['sort'] ?? 'created_at_desc'),
            ])
        );
    }
}
