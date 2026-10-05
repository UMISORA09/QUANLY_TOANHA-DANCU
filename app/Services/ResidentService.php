<?php

namespace App\Services;

use App\DTOs\ResidentFilterDTO;
use App\Models\Apartment;
use App\Models\Resident;
use App\Repositories\Contracts\ResidentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResidentService
{
    public function __construct(
        protected ResidentRepositoryInterface $repository
    ) {}

    /**
     * Danh sách nhân khẩu / cư dân với bộ lọc, tìm kiếm, sắp xếp và phân trang
     *
     * @param  array<string, mixed>|ResidentFilterDTO  $params
     */
    public function list(array|ResidentFilterDTO $params = []): LengthAwarePaginator
    {
        $filter = $params instanceof ResidentFilterDTO ? $params : ResidentFilterDTO::fromArray($params);

        return $this->repository->list($filter);
    }

    /**
     * Lấy chi tiết thông tin nhân khẩu và danh sách toàn bộ thành viên trong cùng căn hộ
     *
     * @return array<string, mixed>
     */
    public function getById(string $id): array
    {
        $resident = $this->repository->findById($id);

        if (! $resident) {
            throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.');
        }

        // Lấy danh sách thành viên cùng sống trong căn hộ này qua Repository
        $householdMembers = $this->repository->getActiveHouseholdMembers($resident->apartment_id);

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

        // 1. Kiểm tra cư dân đã tồn tại trong căn hộ chưa qua Repository
        $existingResident = $this->repository->findByUserAndApartment($userId, $apartmentId);

        if ($existingResident) {
            throw ValidationException::withMessages([
                'user_id' => 'Người dùng này đã được đăng ký cư trú tại căn hộ này.',
            ]);
        }

        // 2. Kiểm tra quy tắc Chủ hộ: Một căn hộ chỉ có duy nhất 1 chủ hộ active
        if ($isHead) {
            $existingHead = $this->repository->findHouseholdHead($apartmentId);

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

            $resident = $this->repository->create($data);

            return $this->repository->loadRelations($resident);
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
        $resident = $this->repository->findById($id);

        if (! $resident) {
            // Kiểm tra xem đã bị xóa mềm trước đó chưa
            $softDeleted = $this->repository->findWithTrashed($id);
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
            $lockedResident = $this->repository->findAndLockForUpdate($id);

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
                $otherHead = $this->repository->findActiveHouseholdHeadExcluding($lockedResident->apartment_id, $lockedResident->id, true);

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

            $newUpdatedAt = Carbon::now();
            if ($currentUpdatedAt && $newUpdatedAt->timestamp <= $currentUpdatedAt->timestamp) {
                $newUpdatedAt = $currentUpdatedAt->copy()->addSecond();
            }

            // Cập nhật an toàn với điều kiện WHERE id = ? AND updated_at = ? qua Repository
            $affected = $this->repository->updateOptimistic(
                $lockedResident->id,
                $currentUpdatedAt,
                array_merge($data, ['updated_at' => $newUpdatedAt])
            );

            if ($affected === 0) {
                throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
            }

            return $this->repository->loadRelations($lockedResident->fresh());
        });
    }

    /**
     * Xóa mềm nhân khẩu an toàn đồng thời (Safe Concurrent Soft Delete)
     *
     * @throws ResidentConflictException|ResidentNotFoundException
     */
    public function delete(string $id): bool
    {
        $resident = $this->repository->findById($id);

        if (! $resident) {
            // Kiểm tra xem đã bị xóa mềm trước đó chưa
            $softDeleted = $this->repository->findWithTrashed($id);
            if ($softDeleted && $softDeleted->trashed()) {
                throw new ResidentConflictException('Cư dân đã được Admin khác xóa hoặc không còn tồn tại.', 409);
            }

            throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.', 404);
        }

        return DB::transaction(function () use ($id) {
            $lockedResident = $this->repository->findAndLockForUpdate($id);

            if (! $lockedResident) {
                throw new ResidentConflictException('Cư dân đã được Admin khác xóa hoặc không còn tồn tại.', 409);
            }

            // Đánh dấu không còn hoạt động trước khi xóa mềm
            $this->repository->update($lockedResident, [
                'is_active' => false,
                'is_head_of_household' => false,
            ]);

            return $this->repository->delete($lockedResident);
        });
    }

    /**
     * Danh sách tóm tắt các căn hộ phục vụ bộ lọc tìm kiếm trên giao diện
     *
     * @return Collection<int, Apartment>
     */
    public function getApartmentsForFilter(): Collection
    {
        return $this->repository->getApartmentsForFilter();
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
