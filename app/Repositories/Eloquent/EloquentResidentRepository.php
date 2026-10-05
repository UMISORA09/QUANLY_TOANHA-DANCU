<?php

namespace App\Repositories\Eloquent;

use App\DTOs\ResidentFilterDTO;
use App\Models\Apartment;
use App\Models\Resident;
use App\Models\User;
use App\Repositories\Contracts\ResidentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class EloquentResidentRepository implements ResidentRepositoryInterface
{
    public function list(ResidentFilterDTO $filter): LengthAwarePaginator
    {
        $query = Resident::query()->with([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url,status',
            'apartment:id,apartment_number,block_id,floor_id,status',
        ]);

        if ($filter->search !== '') {
            $search = $filter->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('national_id_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('apartment', function ($aq) use ($search) {
                    $aq->where('apartment_number', 'like', "%{$search}%");
                });
            });
        }

        if ($filter->apartmentId !== '') {
            $query->where('apartment_id', $filter->apartmentId);
        }

        if ($filter->residentType !== '') {
            $query->where('resident_type', strtoupper($filter->residentType));
        }

        if ($filter->isActive !== null) {
            $query->where('is_active', $filter->isActive ? 1 : 0);
        }

        if ($filter->isHeadOfHousehold !== null) {
            $query->where('is_head_of_household', $filter->isHeadOfHousehold ? 1 : 0);
        }

        $query->orderBy($filter->sortBy, $filter->sortOrder);

        return $query->paginate($filter->limit, ['*'], 'page', $filter->page);
    }

    public function findById(string $id): ?Resident
    {
        return Resident::with([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url,status',
            'apartment:id,apartment_number,block_id,floor_id,status',
        ])->find($id);
    }

    public function findByUserAndApartment(string $userId, string $apartmentId): ?Resident
    {
        return Resident::withTrashed()->where('apartment_id', $apartmentId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Resident
    {
        return Resident::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Resident $resident, array $data): Resident
    {
        $resident->update($data);

        return $resident->fresh([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url,status',
            'apartment:id,apartment_number,block_id,floor_id,status',
        ]);
    }

    public function delete(Resident $resident): bool
    {
        return (bool) $resident->delete();
    }

    public function findHouseholdHead(string $apartmentId): ?Resident
    {
        return Resident::where('apartment_id', $apartmentId)
            ->where('is_head_of_household', true)
            ->where('is_active', true)
            ->first();
    }

    public function hasActiveHouseholdHead(string $apartmentId, ?string $excludeResidentId = null): bool
    {
        $query = Resident::where('apartment_id', $apartmentId)
            ->where('is_head_of_household', true)
            ->where('is_active', true);

        if ($excludeResidentId !== null) {
            $query->where('id', '!=', $excludeResidentId);
        }

        return $query->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getApartments(): array
    {
        return Apartment::query()
            ->select('id', 'apartment_number', 'block_id', 'floor_id', 'status')
            ->orderBy('apartment_number', 'asc')
            ->get()
            ->toArray();
    }

    public function findApartment(string $apartmentId, bool $lock = false): ?Apartment
    {
        return Apartment::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($apartmentId);
    }

    public function findUser(string $userId): ?User
    {
        return User::find($userId);
    }

    /**
     * @return Collection<int, Resident>
     */
    public function getActiveHouseholdMembers(string $apartmentId): Collection
    {
        return Resident::with([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
        ])
            ->where('apartment_id', $apartmentId)
            ->where('is_active', 1)
            ->orderByDesc('is_head_of_household')
            ->orderBy('stay_start_date', 'asc')
            ->get();
    }

    public function findWithTrashed(string $id): ?Resident
    {
        return Resident::withTrashed()->find($id);
    }

    public function findAndLockForUpdate(string $id): ?Resident
    {
        return Resident::where('id', $id)->lockForUpdate()->first();
    }

    public function findActiveHouseholdHeadExcluding(string $apartmentId, string $excludeResidentId, bool $lockForUpdate = false): ?Resident
    {
        $query = Resident::with('user')
            ->where('apartment_id', $apartmentId)
            ->where('id', '!=', $excludeResidentId)
            ->where('is_head_of_household', 1)
            ->where('is_active', 1);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOptimistic(string $id, Carbon $currentUpdatedAt, array $data): int
    {
        return Resident::where('id', $id)
            ->where('updated_at', $currentUpdatedAt)
            ->update($data);
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function loadRelations(Resident $resident, array $relations = []): Resident
    {
        $defaultRelations = [
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
            'apartment:id,apartment_number,block_id',
        ];

        return $resident->load(! empty($relations) ? $relations : $defaultRelations);
    }

    /**
     * @return Collection<int, Apartment>
     */
    public function getApartmentsForFilter(): Collection
    {
        return Apartment::query()
            ->select('id', 'apartment_number', 'block_id', 'status')
            ->withCount(['residents' => function ($q) {
                $q->where('is_active', 1);
            }])
            ->with(['headOfHousehold.user:id,full_name,phone_number'])
            ->orderBy('apartment_number', 'asc')
            ->get();
    }
}
