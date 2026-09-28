<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Resident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResidentService
{
    /**
     * Danh sách nhân khẩu / cư dân với bộ lọc, tìm kiếm, sắp xếp và phân trang
     *
     * @param  array<string, mixed>  $params
     */
    public function list(array $params = []): LengthAwarePaginator
    {
        $search = trim((string) ($params['search'] ?? ''));
        $apartmentId = trim((string) ($params['apartment_id'] ?? ''));
        $residentType = trim((string) ($params['resident_type'] ?? ''));
        $isActive = isset($params['is_active']) && $params['is_active'] !== '' ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $isHead = isset($params['is_head_of_household']) && $params['is_head_of_household'] !== '' ? filter_var($params['is_head_of_household'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $sortBy = (string) ($params['sort_by'] ?? 'stay_start_date');
        $sortOrder = strtolower((string) ($params['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) ($params['limit'] ?? 15), 5), 100);

        $query = Resident::query()->with([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url,status',
            'apartment:id,apartment_number,block_id,floor_id,status',
        ]);

        // 1. Tìm kiếm theo Họ tên, Số điện thoại, CCCD/CMND, Email hoặc Số căn hộ
        if ($search !== '') {
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

        // 2. Lọc theo căn hộ
        if ($apartmentId !== '') {
            $query->where('apartment_id', $apartmentId);
        }

        // 3. Lọc theo loại cư dân (OWNER, TENANT, FAMILY_MEMBER)
        if ($residentType !== '') {
            $query->where('resident_type', strtoupper($residentType));
        }

        // 4. Lọc theo trạng thái hoạt động (Active/Inactive)
        if ($isActive !== null) {
            $query->where('is_active', $isActive ? 1 : 0);
        }

        // 5. Lọc theo vai trò chủ hộ
        if ($isHead !== null) {
            $query->where('is_head_of_household', $isHead ? 1 : 0);
        }

        // 6. Sắp xếp
        if ($sortBy === 'full_name') {
            $query->join('users', 'residents.user_id', '=', 'users.id')
                ->orderBy('users.full_name', $sortOrder)
                ->select('residents.*');
        } elseif ($sortBy === 'apartment_number') {
            $query->join('apartments', 'residents.apartment_id', '=', 'apartments.id')
                ->orderBy('apartments.apartment_number', $sortOrder)
                ->select('residents.*');
        } elseif (in_array($sortBy, ['created_at', 'stay_start_date', 'stay_end_date', 'vehicle_count'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('stay_start_date', 'desc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Lấy chi tiết thông tin nhân khẩu và danh sách toàn bộ thành viên trong cùng căn hộ
     *
     * @return array<string, mixed>
     */
    public function getById(string $id): array
    {
        $resident = Resident::with([
            'user:id,username,full_name,phone_number,email,national_id_number,gender,date_of_birth,avatar_url,status',
            'apartment:id,apartment_number,block_id,floor_id,room_type,status,gross_floor_area_sqm',
        ])->find($id);

        if (! $resident) {
            throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.');
        }

        // Lấy danh sách thành viên cùng sống trong căn hộ này
        $householdMembers = Resident::with([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
        ])
            ->where('apartment_id', $resident->apartment_id)
            ->where('is_active', 1)
            ->orderByDesc('is_head_of_household')
            ->orderBy('stay_start_date', 'asc')
            ->get();

        $headOfHousehold = $householdMembers->firstWhere('is_head_of_household', true);

        return [
            'resident' => $resident,
            'apartment' => $resident->apartment,
            'head_of_household' => $headOfHousehold,
            'household_members' => $householdMembers,
            'total_members' => $householdMembers->count(),
        ];
    }

    /**
     * Thêm cư dân / nhân khẩu mới vào căn hộ
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Resident
    {
        $apartmentId = $data['apartment_id'];
        $userId = $data['user_id'];
        $isHead = ! empty($data['is_head_of_household']);

        // 1. Kiểm tra cư dân đã tồn tại trong căn hộ chưa (tránh trùng lặp)
        $existingResident = Resident::where('apartment_id', $apartmentId)
            ->where('user_id', $userId)
            ->first();

        if ($existingResident) {
            throw ValidationException::withMessages([
                'user_id' => 'Người dùng này đã được đăng ký cư trú tại căn hộ này.',
            ]);
        }

        // 2. Kiểm tra quy tắc Chủ hộ: Một căn hộ chỉ có duy nhất 1 chủ hộ active
        if ($isHead) {
            $existingHead = Resident::with('user')
                ->where('apartment_id', $apartmentId)
                ->where('is_head_of_household', 1)
                ->where('is_active', 1)
                ->first();

            if ($existingHead) {
                $headName = $existingHead->user?->full_name ?? 'Cư dân hiện tại';
                throw ValidationException::withMessages([
                    'is_head_of_household' => "Căn hộ này đã có Chủ hộ đang hoạt động ({$headName}). Mỗi căn hộ chỉ được có duy nhất một chủ hộ.",
                ]);
            }
        }

        return DB::transaction(function () use ($data, $isHead) {
            if ($isHead && empty($data['relationship_to_head'])) {
                $data['relationship_to_head'] = 'SELF';
            }

            if (! isset($data['is_active'])) {
                $data['is_active'] = true;
            }

            $resident = Resident::create($data);

            return $resident->load([
                'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
            ]);
        });
    }

    /**
     * Cập nhật thông tin nhân khẩu căn hộ (Concurrency Safe with Optimistic Locking)
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|ResidentConflictException|ResidentNotFoundException
     */
    public function update(string $id, array $data): Resident
    {
        // 1. Kiểm tra bản ghi active
        $resident = Resident::find($id);

        if (! $resident) {
            // Kiểm tra xem đã bị xóa mềm trước đó chưa
            $softDeleted = Resident::withTrashed()->find($id);
            if ($softDeleted && $softDeleted->trashed()) {
                throw new ResidentConflictException('Cư dân đã được xóa hoặc không còn khả dụng.', 409);
            }

            throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.', 404);
        }

        // 2. Kiểm tra Optimistic Concurrency sơ bộ nếu client truyền updated_at
        if (! empty($data['updated_at'])) {
            $clientTime = Carbon::parse($data['updated_at'])->timestamp;
            $dbTime = $resident->updated_at ? $resident->updated_at->timestamp : 0;
            if ($clientTime !== $dbTime) {
                throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
            }
        }

        return DB::transaction(function () use ($id, $data) {
            // Khóa dòng với lockForUpdate để tránh race condition
            $lockedResident = Resident::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $lockedResident) {
                throw new ResidentConflictException('Cư dân đã được xóa hoặc không còn khả dụng.', 409);
            }

            // Tái kiểm tra phiên bản updated_at dưới lock giao dịch
            if (! empty($data['updated_at'])) {
                $clientTime = Carbon::parse($data['updated_at'])->timestamp;
                $dbTime = $lockedResident->updated_at ? $lockedResident->updated_at->timestamp : 0;
                if ($clientTime !== $dbTime) {
                    throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
                }
            }

            // Kiểm tra phân định chủ hộ: Khóa cư dân trong căn hộ để đảm bảo chỉ có tối đa 1 chủ hộ active
            if (isset($data['is_head_of_household']) && (bool) $data['is_head_of_household'] === true) {
                $otherHead = Resident::where('apartment_id', $lockedResident->apartment_id)
                    ->where('id', '!=', $lockedResident->id)
                    ->where('is_head_of_household', 1)
                    ->where('is_active', 1)
                    ->lockForUpdate()
                    ->first();

                if ($otherHead) {
                    $headName = $otherHead->user?->full_name ?? 'Cư dân hiện tại';
                    throw ValidationException::withMessages([
                        'is_head_of_household' => "Căn hộ này đã có Chủ hộ đang hoạt động ({$headName}). Vui lòng hủy chủ hộ cũ trước khi thiết lập chủ hộ mới.",
                    ]);
                }

                $data['relationship_to_head'] = 'SELF';
            }

            $currentUpdatedAt = $lockedResident->updated_at;
            unset($data['updated_at']);

            // Cập nhật an toàn với điều kiện WHERE id = ? AND updated_at = ?
            $affected = Resident::where('id', $lockedResident->id)
                ->where('updated_at', $currentUpdatedAt)
                ->update(array_merge($data, ['updated_at' => now()]));

            if ($affected === 0) {
                throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
            }

            return $lockedResident->fresh([
                'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
            ]);
        });
    }

    /**
     * Xóa mềm nhân khẩu an toàn đồng thời (Safe Concurrent Soft Delete)
     *
     * @throws ResidentConflictException|ResidentNotFoundException
     */
    public function delete(string $id): bool
    {
        $resident = Resident::find($id);

        if (! $resident) {
            // Kiểm tra xem đã bị xóa mềm trước đó chưa
            $softDeleted = Resident::withTrashed()->find($id);
            if ($softDeleted && $softDeleted->trashed()) {
                throw new ResidentConflictException('Cư dân đã được Admin khác xóa hoặc không còn tồn tại.', 409);
            }

            throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.', 404);
        }

        return DB::transaction(function () use ($id) {
            $lockedResident = Resident::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $lockedResident) {
                throw new ResidentConflictException('Cư dân đã được Admin khác xóa hoặc không còn tồn tại.', 409);
            }

            // Đánh dấu không còn hoạt động trước khi xóa mềm
            $lockedResident->update([
                'is_active' => false,
                'is_head_of_household' => false,
            ]);

            return (bool) $lockedResident->delete();
        });
    }

    /**
     * Danh sách tóm tắt các căn hộ phục vụ bộ lọc tìm kiếm trên giao diện
     *
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

class ResidentConflictException extends \RuntimeException
{
    public function __construct(string $message, protected int $statusCode = 409)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

class ResidentNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Cư dân không tồn tại trong hệ thống.', protected int $statusCode = 404)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
