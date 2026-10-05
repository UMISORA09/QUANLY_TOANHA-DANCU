<?php

namespace App\Repositories\Contracts;

use App\DTOs\AmenityFilterDTO;

interface AmenityRepositoryInterface
{
    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int, total_pages: int}
     */
    public function getPaginated(AmenityFilterDTO $filter): array;

    public function findById(string $id): ?object;

    public function findByCode(string $code, ?string $excludeId = null): ?object;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): bool;

    public function softDelete(string $id): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array;

    public function findCategory(string $id): ?object;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(string $id, array $data): bool;

    public function deleteCategory(string $id): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTimeSlots(string $amenityId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlackouts(string $amenityId): array;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBookings(string $amenityId, array $filters = []): array;

    public function countActiveBookings(string $amenityId): int;

    public function getPeakBookings(string $amenityId): int;

    public function isCodeExists(string $code, ?string $excludeId = null): bool;

    public function getAmenityDetail(string $id): ?object;

    /**
     * @param  array<int, string>  $amenityIds
     * @return array<string, array{total: int, active: int}>
     */
    public function getSlotCountsForAmenities(array $amenityIds): array;

    /**
     * @param  array<int, string>  $amenityIds
     * @return array<string, int>
     */
    public function getActiveBookingCountsForAmenities(array $amenityIds): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAmenityBookingsWithDetails(string $amenityId): array;
}
