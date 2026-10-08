<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        $columns = [
            'residents.id',
            'residents.user_id',
            'residents.apartment_id',
            'residents.resident_type',
            'residents.is_head_of_household',
            'residents.stay_start_date',
            'residents.stay_end_date',
            'residents.relationship_to_head',
            'residents.occupation',
            'residents.vehicle_count',
            'residents.is_active',
            'residents.created_at',
            'residents.updated_at',
        ];

        $query = Resident::query()
            ->select($columns)
            ->with([
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
            $query->where('residents.apartment_id', $apartmentId);
        }

        // 3. Lọc theo loại cư dân (OWNER, TENANT, FAMILY_MEMBER)
        if ($residentType !== '') {
            $query->where('residents.resident_type', strtoupper($residentType));
        }

        // 4. Lọc theo trạng thái hoạt động (Active/Inactive)
        if ($isActive !== null) {
            $query->where('residents.is_active', $isActive ? 1 : 0);
        }

        // 5. Lọc theo vai trò chủ hộ
        if ($isHead !== null) {
            $query->where('residents.is_head_of_household', $isHead ? 1 : 0);
        }

        // 6. Sắp xếp
        if ($sortBy === 'full_name') {
            $query->join('users', 'residents.user_id', '=', 'users.id')
                ->orderBy('users.full_name', $sortOrder)
                ->select($columns);
        } elseif ($sortBy === 'apartment_number') {
            $query->join('apartments', 'residents.apartment_id', '=', 'apartments.id')
                ->orderBy('apartments.apartment_number', $sortOrder)
                ->select($columns);
        } elseif (in_array($sortBy, ['created_at', 'stay_start_date', 'stay_end_date', 'vehicle_count'], true)) {
            $query->orderBy('residents.'.$sortBy, $sortOrder);
        } else {
            $query->orderBy('residents.stay_start_date', 'desc');
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
        QuocTinRealtimeService::assertNotInCooldown('residents');

        $apartmentId = $data['apartment_id'];
        $userId = $data['user_id'];
        $isHead = ! empty($data['is_head_of_household']);

        // 1. Kiểm tra cư dân đã tồn tại trong căn hộ chưa bằng index seek siêu nhẹ (không hydrate model)
        $alreadyExists = Resident::where('apartment_id', $apartmentId)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Người dùng này đã được đăng ký cư trú tại căn hộ này.',
            ]);
        }

        // 2. Kiểm tra quy tắc Chủ hộ: Chỉ đọc user_id qua composite index idx_residents_apt_active_head
        if ($isHead) {
            $existingHeadUserId = Resident::where('apartment_id', $apartmentId)
                ->where('is_active', 1)
                ->where('is_head_of_household', 1)
                ->value('user_id');

            if ($existingHeadUserId) {
                $headName = User::where('id', $existingHeadUserId)->value('full_name') ?? 'Cư dân hiện tại';
                throw ValidationException::withMessages([
                    'is_head_of_household' => "Căn hộ này đã có Chủ hộ đang hoạt động ({$headName}). Mỗi căn hộ chỉ được có duy nhất một chủ hộ.",
                ]);
            }
        }

        $resident = DB::transaction(function () use ($data, $isHead, $apartmentId) {
            if ($isHead) {
                // Khóa căn hộ để chống race condition khi 2 admin cùng thêm chủ hộ đồng thời
                DB::table('apartments')->where('id', $apartmentId)->lockForUpdate()->first();

                $existingHeadUserId = Resident::where('apartment_id', $apartmentId)
                    ->where('is_active', 1)
                    ->where('is_head_of_household', 1)
                    ->value('user_id');

                if ($existingHeadUserId) {
                    $headName = User::where('id', $existingHeadUserId)->value('full_name') ?? 'Cư dân hiện tại';
                    throw ValidationException::withMessages([
                        'is_head_of_household' => "Căn hộ này đã có Chủ hộ đang hoạt động ({$headName}). Mỗi căn hộ chỉ được có duy nhất một chủ hộ.",
                    ]);
                }

                if (empty($data['relationship_to_head'])) {
                    $data['relationship_to_head'] = 'SELF';
                }
            }

            if (! isset($data['is_active'])) {
                $data['is_active'] = true;
            }

            try {
                $res = Resident::create($data);
            } catch (QueryException $e) {
                if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                    throw new ResidentConflictException('Cư dân này đã được thêm vào căn hộ bởi quản trị viên khác.', 409);
                }
                throw $e;
            }

            Cache::forget('apartments_resident_filter');

            return $res;
        });

        // Tải các quan hệ cần thiết SAU KHI commit transaction để thời gian giữ lock là ngắn nhất
        $created = $resident->load([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
            'apartment:id,apartment_number,block_id',
        ]);

        QuocTinRealtimeService::emit('residents', 'resident', 'CREATED', $created->id, [
            'apartment_id' => $created->apartment_id,
            'user_id' => $created->user_id,
            'is_head_of_household' => (bool) $created->is_head_of_household,
            'updated_at' => $created->updated_at?->toIso8601String(),
        ]);

        return $created;
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
        QuocTinRealtimeService::assertNotInCooldown('residents');

        $updated = DB::transaction(function () use ($id, $data) {
            // Khóa dòng trực tiếp trong transaction để tránh SELECT dư thừa trước đó
            $lockedResident = Resident::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $lockedResident) {
                $softDeleted = Resident::withTrashed()->find($id);
                if ($softDeleted && $softDeleted->trashed()) {
                    throw new ResidentConflictException('Cư dân đã được xóa hoặc không còn khả dụng.', 409);
                }

                throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.', 404);
            }

            // Kiểm tra Optimistic Concurrency dưới lock giao dịch
            if (! empty($data['updated_at'])) {
                $clientTime = Carbon::parse($data['updated_at'])->timestamp;
                $dbTime = $lockedResident->updated_at ? $lockedResident->updated_at->timestamp : 0;
                if ($clientTime !== $dbTime) {
                    throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
                }
            }

            // Kiểm tra phân định chủ hộ: chỉ truy vấn user_id qua index khi thiết lập chủ hộ mới
            if (isset($data['is_head_of_household']) && (bool) $data['is_head_of_household'] === true) {
                DB::table('apartments')->where('id', $lockedResident->apartment_id)->lockForUpdate()->first();

                $otherHeadUserId = Resident::where('apartment_id', $lockedResident->apartment_id)
                    ->where('id', '!=', $lockedResident->id)
                    ->where('is_active', 1)
                    ->where('is_head_of_household', 1)
                    ->value('user_id');

                if ($otherHeadUserId) {
                    $headName = User::where('id', $otherHeadUserId)->value('full_name') ?? 'Cư dân hiện tại';
                    throw ValidationException::withMessages([
                        'is_head_of_household' => "Căn hộ này đã có Chủ hộ đang hoạt động ({$headName}). Vui lòng hủy chủ hộ cũ trước khi thiết lập chủ hộ mới.",
                    ]);
                }

                $data['relationship_to_head'] = 'SELF';
            }

            $currentUpdatedAt = $lockedResident->updated_at;
            unset($data['updated_at']);

            $newUpdatedAt = now();
            if ($currentUpdatedAt && $newUpdatedAt->timestamp <= $currentUpdatedAt->timestamp) {
                $newUpdatedAt = $currentUpdatedAt->copy()->addSecond();
            }

            // Cập nhật an toàn với điều kiện WHERE id = ? AND updated_at = ?
            $affected = Resident::where('id', $lockedResident->id)
                ->where('updated_at', $currentUpdatedAt)
                ->update(array_merge($data, ['updated_at' => $newUpdatedAt]));

            if ($affected === 0) {
                throw new ResidentConflictException('Thông tin cư dân đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
            }

            Cache::forget('apartments_resident_filter');

            // Cập nhật thuộc tính trên đối tượng trong bộ nhớ, không cần gọi fresh() tốn query
            $lockedResident->fill($data);
            $lockedResident->updated_at = $newUpdatedAt;

            return $lockedResident;
        });

        // Nạp relations SAU KHI commit transaction để giải phóng khóa giao dịch lập tức
        $updated->load([
            'user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
            'apartment:id,apartment_number,block_id',
        ]);

        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', $updated->id, [
            'apartment_id' => $updated->apartment_id,
            'user_id' => $updated->user_id,
            'is_head_of_household' => (bool) $updated->is_head_of_household,
            'updated_at' => $updated->updated_at?->toIso8601String(),
        ]);

        return $updated;
    }

    /**
     * Xóa mềm nhân khẩu an toàn đồng thời (Safe Concurrent Soft Delete)
     *
     * @throws ResidentConflictException|ResidentNotFoundException
     */
    public function delete(string $id): bool
    {
        QuocTinRealtimeService::assertNotInCooldown('residents');

        $deletedData = DB::transaction(function () use ($id) {
            $lockedResident = Resident::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $lockedResident) {
                $softDeleted = Resident::withTrashed()->find($id);
                if ($softDeleted && $softDeleted->trashed()) {
                    throw new ResidentConflictException('Cư dân đã được Admin khác xóa hoặc không còn tồn tại.', 409);
                }

                throw new ResidentNotFoundException('Cư dân không tồn tại trong hệ thống.', 404);
            }

            $apartmentId = $lockedResident->apartment_id;

            // Tối ưu: Gộp vô hiệu hóa và xóa mềm vào DUY NHẤT 1 câu lệnh UPDATE thay vì 2 lần update riêng
            $lockedResident->forceFill([
                'is_active' => false,
                'is_head_of_household' => false,
                'deleted_at' => now(),
                'updated_at' => now(),
            ])->save();

            Cache::forget('apartments_resident_filter');

            return ['deleted' => true, 'apartment_id' => $apartmentId];
        });

        if ($deletedData['deleted']) {
            QuocTinRealtimeService::emit('residents', 'resident', 'DELETED', $id, [
                'apartment_id' => $deletedData['apartment_id'],
            ]);
        }

        return $deletedData['deleted'];
    }

    /**
     * Danh sách tóm tắt các căn hộ phục vụ bộ lọc tìm kiếm trên giao diện
     *
     * @return Collection<int, Apartment>
     */
    public function getApartmentsForFilter(): Collection
    {
        return Cache::remember('apartments_resident_filter', 300, function () {
            return Apartment::query()
                ->select('id', 'apartment_number', 'block_id', 'status')
                ->withCount(['residents' => function ($q) {
                    $q->where('is_active', 1);
                }])
                ->with(['headOfHousehold.user:id,full_name,phone_number'])
                ->orderBy('apartment_number', 'asc')
                ->get();
        });
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
