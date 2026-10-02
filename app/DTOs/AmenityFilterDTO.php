<?php

namespace App\DTOs;

final readonly class AmenityFilterDTO
{
    public function __construct(
        public ?string $search = null,
        public ?string $categoryId = null,
        public ?string $blockId = null,
        public ?string $isActive = null,
        public string $sort = 'created_at_desc',
        public int $page = 1,
        public int $limit = 10
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            search: isset($data['search']) && trim((string) $data['search']) !== '' ? trim((string) $data['search']) : null,
            categoryId: isset($data['category_id']) && trim((string) $data['category_id']) !== '' ? trim((string) $data['category_id']) : null,
            blockId: isset($data['block_id']) && trim((string) $data['block_id']) !== '' ? trim((string) $data['block_id']) : null,
            isActive: isset($data['is_active']) && $data['is_active'] !== '' && $data['is_active'] !== null ? (string) $data['is_active'] : null,
            sort: (string) ($data['sort'] ?? 'created_at_desc'),
            page: max((int) ($data['page'] ?? 1), 1),
            limit: max(min((int) ($data['limit'] ?? 10), 100), 1)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'category_id' => $this->categoryId,
            'block_id' => $this->blockId,
            'is_active' => $this->isActive,
            'sort' => $this->sort,
            'page' => $this->page,
            'limit' => $this->limit,
        ];
    }
}
