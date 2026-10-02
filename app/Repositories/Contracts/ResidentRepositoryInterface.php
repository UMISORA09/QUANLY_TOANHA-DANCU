<?php

namespace App\Repositories\Contracts;

use App\DTOs\ResidentFilterDTO;
use App\Models\Apartment;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ResidentRepositoryInterface
{
    public function list(ResidentFilterDTO $filter): LengthAwarePaginator;

    public function findById(string $id): ?Resident;

    public function findByUserAndApartment(string $userId, string $apartmentId): ?Resident;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Resident;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Resident $resident, array $data): Resident;

    public function delete(Resident $resident): bool;

    public function findHouseholdHead(string $apartmentId): ?Resident;

    public function hasActiveHouseholdHead(string $apartmentId, ?string $excludeResidentId = null): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getApartments(): array;

    public function findApartment(string $apartmentId): ?Apartment;

    public function findUser(string $userId): ?User;
}
