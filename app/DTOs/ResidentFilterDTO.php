<?php

namespace App\DTOs;

final readonly class ResidentFilterDTO
{
    public function __construct(
        public string $search = '',
        public string $apartmentId = '',
        public string $residentType = '',
        public ?bool $isActive = null,
        public ?bool $isHeadOfHousehold = null,
        public string $sortBy = 'stay_start_date',
        public string $sortOrder = 'desc',
        public int $page = 1,
        public int $limit = 15
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $isActive = isset($data['is_active']) && $data['is_active'] !== ''
            ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $isHead = isset($data['is_head_of_household']) && $data['is_head_of_household'] !== ''
            ? filter_var($data['is_head_of_household'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        return new self(
            search: trim((string) ($data['search'] ?? '')),
            apartmentId: trim((string) ($data['apartment_id'] ?? '')),
            residentType: trim((string) ($data['resident_type'] ?? '')),
            isActive: $isActive,
            isHeadOfHousehold: $isHead,
            sortBy: (string) ($data['sort_by'] ?? 'stay_start_date'),
            sortOrder: strtolower((string) ($data['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            page: max((int) ($data['page'] ?? 1), 1),
            limit: min(max((int) ($data['limit'] ?? 15), 5), 100)
        );
    }
}
