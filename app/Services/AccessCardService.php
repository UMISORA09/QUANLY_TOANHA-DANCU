<?php

namespace App\Services;

use App\Models\AccessCard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccessCardService
{
    /**
     * Danh sách thẻ RFID có tìm kiếm, lọc và phân trang tối ưu
     *
     * @param  array<string, mixed>  $filters
     */
    public function listCards(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        if ($perPage < 1) {
            $perPage = 15;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $query = AccessCard::query()
            ->select([
                'id',
                'card_uid',
                'card_number',
                'card_type',
                'assigned_user_id',
                'assigned_apartment_id',
                'assigned_vehicle_id',
                'issued_date',
                'expiry_date',
                'status',
                'deposit_fee',
                'created_at',
                'updated_at',
            ])
            ->with([
                'assignedUser:id,full_name,email,phone_number',
                'assignedApartment:id,apartment_number,block_id,status',
                'assignedVehicle:id,license_plate,vehicle_category,model',
            ]);

        // 1. Tìm kiếm theo mã chip UID, số thẻ, tên/số điện thoại cư dân, số căn hộ
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('card_uid', 'like', "%{$search}%")
                    ->orWhere('card_number', 'like', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($uQuery) use ($search) {
                        $uQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('assignedApartment', function ($aQuery) use ($search) {
                        $aQuery->where('apartment_number', 'like', "%{$search}%");
                    });
            });
        }

        // 2. Lọc theo trạng thái thẻ (ACTIVE, LOCKED_TEMPORARY, LOST_REPORTED, REVOKED)
        if (! empty($filters['status'])) {
            $status = strtoupper(trim((string) $filters['status']));
            if ($status === 'LOCKED') {
                $query->whereIn('status', ['LOCKED', 'LOCKED_TEMPORARY']);
            } elseif ($status !== 'ALL') {
                $query->where('status', $status);
            }
        }

        // 3. Lọc theo loại thẻ
        if (! empty($filters['card_type'])) {
            $query->where('card_type', strtoupper(trim((string) $filters['card_type'])));
        }

        // 4. Lọc theo căn hộ
        if (! empty($filters['apartment_id'])) {
            $query->where('assigned_apartment_id', $filters['apartment_id']);
        }

        // 5. Lọc theo người dùng / cư dân
        if (! empty($filters['user_id'])) {
            $query->where('assigned_user_id', $filters['user_id']);
        }

        // 6. Sắp xếp an toàn
        $sortColumn = $filters['sort_by'] ?? 'created_at';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'updated_at', 'card_number', 'card_uid', 'issued_date', 'status'];

        if (in_array($sortColumn, $allowedSorts, true)) {
            $query->orderBy($sortColumn, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Lấy thông tin chi tiết thẻ kèm quan hệ và lịch sử quẹt thẻ gần nhất
     */
    public function getCardById(string $id): array
    {
        $card = AccessCard::with([
            'assignedUser:id,full_name,email,phone_number',
            'assignedApartment:id,apartment_number,block_id,status',
            'assignedVehicle:id,license_plate,vehicle_category,model',
        ])->find($id);

        if (! $card) {
            throw new AccessCardNotFoundException("Thẻ RFID có ID '{$id}' không tồn tại trong hệ thống.");
        }

        // Lấy 5 bản ghi quẹt thẻ gần nhất nếu có trong bảng parking_access_logs
        $recentLogs = DB::table('parking_access_logs')
            ->where('card_id', $id)
            ->select(['id', 'access_direction', 'log_timestamp'])
            ->orderBy('log_timestamp', 'desc')
            ->limit(5)
            ->get();

        return [
            'card' => $card,
            'recent_logs' => $recentLogs,
        ];
    }

    /**
     * Cấp mới thẻ RFID trong DB Transaction và kích hoạt Realtime + Cooldown 120s
     */
    public function createCard(array $data, ?string $actorId = null): AccessCard
    {
        QuocTinRealtimeService::assertNotInCooldown('rfid_cards');

        // Chuẩn hóa dữ liệu
        $data['card_uid'] = strtoupper(trim((string) ($data['card_uid'] ?? '')));
        $data['card_number'] = strtoupper(trim((string) ($data['card_number'] ?? '')));
        $data['card_type'] = strtoupper(trim((string) ($data['card_type'] ?? 'RESIDENT_ALL_ACCESS')));
        $data['status'] = strtoupper(trim((string) ($data['status'] ?? 'ACTIVE')));
        $data['issued_date'] = $data['issued_date'] ?? date('Y-m-d');
        $data['deposit_fee'] = isset($data['deposit_fee']) ? (float) $data['deposit_fee'] : 50000.00;

        $card = DB::transaction(function () use ($data) {
            try {
                return AccessCard::create($data);
            } catch (QueryException $e) {
                if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE')) {
                    throw new AccessCardConflictException("Mã UID hoặc số thẻ '{$data['card_uid']}' đã tồn tại trong hệ thống.");
                }
                throw $e;
            }
        });

        $card->load([
            'assignedUser:id,full_name,email,phone_number',
            'assignedApartment:id,apartment_number,block_id,status',
            'assignedVehicle:id,license_plate,vehicle_category,model',
        ]);

        // Phát realtime event với triggerCooldown = true
        QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'CREATED', $card->id, [
            'card_uid' => $card->card_uid,
            'card_number' => $card->card_number,
            'card_type' => $card->card_type,
            'status' => $card->status,
            'assigned_apartment_id' => $card->assigned_apartment_id,
            'assigned_user_id' => $card->assigned_user_id,
        ], $actorId ? (int) $actorId : null, true);

        return $card;
    }

    /**
     * Cập nhật thông tin thẻ trong DB Transaction và phát Realtime + Cooldown 120s
     */
    public function updateCard(string $id, array $data, ?string $actorId = null): AccessCard
    {
        QuocTinRealtimeService::assertNotInCooldown('rfid_cards');

        $card = AccessCard::find($id);
        if (! $card) {
            throw new AccessCardNotFoundException("Thẻ RFID có ID '{$id}' không tồn tại trong hệ thống.");
        }

        if (isset($data['card_uid'])) {
            $data['card_uid'] = strtoupper(trim((string) $data['card_uid']));
        }
        if (isset($data['card_number'])) {
            $data['card_number'] = strtoupper(trim((string) $data['card_number']));
        }
        if (isset($data['card_type'])) {
            $data['card_type'] = strtoupper(trim((string) $data['card_type']));
        }
        if (isset($data['status'])) {
            $data['status'] = strtoupper(trim((string) $data['status']));
        }

        DB::transaction(function () use ($card, $data) {
            try {
                $card->update($data);
            } catch (QueryException $e) {
                if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE')) {
                    throw new AccessCardConflictException('Mã UID hoặc số thẻ cập nhật đã trùng với thẻ khác trong hệ thống.');
                }
                throw $e;
            }
        });

        $fresh = $card->fresh([
            'assignedUser:id,full_name,email,phone_number',
            'assignedApartment:id,apartment_number,block_id,status',
            'assignedVehicle:id,license_plate,vehicle_category,model',
        ]);

        QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'UPDATED', $fresh->id, [
            'card_uid' => $fresh->card_uid,
            'card_number' => $fresh->card_number,
            'card_type' => $fresh->card_type,
            'status' => $fresh->status,
            'assigned_apartment_id' => $fresh->assigned_apartment_id,
            'assigned_user_id' => $fresh->assigned_user_id,
        ], $actorId ? (int) $actorId : null, true);

        return $fresh;
    }

    /**
     * Chuyển đổi trạng thái ACTIVE <-> LOCKED_TEMPORARY (Thao tác nhanh, phát Realtime tức thì)
     */
    public function toggleStatus(string $id, ?string $actorId = null): AccessCard
    {
        QuocTinRealtimeService::assertNotInCooldown('rfid_cards');

        $card = AccessCard::find($id);
        if (! $card) {
            throw new AccessCardNotFoundException("Thẻ RFID có ID '{$id}' không tồn tại trong hệ thống.");
        }

        $newStatus = ($card->status === 'ACTIVE') ? 'LOCKED_TEMPORARY' : 'ACTIVE';

        DB::transaction(function () use ($card, $newStatus) {
            $card->update(['status' => $newStatus]);
        });

        $fresh = $card->fresh([
            'assignedUser:id,full_name,email,phone_number',
            'assignedApartment:id,apartment_number,block_id,status',
            'assignedVehicle:id,license_plate,vehicle_category,model',
        ]);

        QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'STATUS_CHANGED', $fresh->id, [
            'card_uid' => $fresh->card_uid,
            'card_number' => $fresh->card_number,
            'card_type' => $fresh->card_type,
            'status' => $newStatus,
            'is_active' => ($newStatus === 'ACTIVE'),
        ], $actorId ? (int) $actorId : null, true);

        return $fresh;
    }

    /**
     * Xóa / vô hiệu hóa thẻ an toàn (bảo toàn lịch sử ra vào nếu đã từng quẹt)
     */
    public function deleteCard(string $id, ?string $actorId = null): array
    {
        QuocTinRealtimeService::assertNotInCooldown('rfid_cards');

        $card = AccessCard::find($id);
        if (! $card) {
            throw new AccessCardNotFoundException("Thẻ RFID có ID '{$id}' không tồn tại trong hệ thống.");
        }

        // Kiểm tra xem thẻ đã từng có nhật ký quẹt bãi xe hay khách thăm chưa
        $hasLogs = DB::table('parking_access_logs')->where('card_id', $id)->exists()
            || DB::table('visitor_checkin_logs')->where('temp_card_id', $id)->exists();

        if ($hasLogs) {
            // Không xóa vật lý để giữ toàn vẹn dữ liệu quan hệ, chuyển trạng thái sang REVOKED
            DB::transaction(function () use ($card) {
                $card->update(['status' => 'REVOKED']);
            });
            $action = 'REVOKED';
        } else {
            // Chưa từng quẹt thẻ, xóa vật lý an toàn
            DB::transaction(function () use ($card) {
                $card->delete();
            });
            $action = 'DELETED';
        }

        QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'DELETED', $id, [
            'card_uid' => $card->card_uid,
            'card_number' => $card->card_number,
            'status' => 'REVOKED',
            'is_revoked' => $hasLogs,
            'action' => $action,
        ], $actorId ? (int) $actorId : null, true);

        return [
            'success' => true,
            'action' => $action,
            'message' => $hasLogs
                ? 'Thẻ đã từng có lịch sử quét ra vào, hệ thống đã chuyển trạng thái sang THU HỒI (REVOKED) để bảo toàn dữ liệu.'
                : 'Thẻ RFID đã được xóa thành công khỏi hệ thống.',
        ];
    }

    /**
     * Lấy danh mục căn hộ cho dropdown (chỉ lấy id, apartment_number)
     */
    public function getApartmentsForDropdown(): array
    {
        return Cache::remember('rfid_dropdown_apartments', 300, function () {
            return DB::table('apartments')
                ->select(['id', 'apartment_number', 'block_id'])
                ->orderBy('apartment_number', 'asc')
                ->limit(500)
                ->get()
                ->toArray();
        });
    }

    /**
     * Lấy danh mục cư dân cho dropdown (chỉ lấy id, full_name, phone_number)
     */
    public function getResidentsForDropdown(?string $apartmentId = null): array
    {
        $cacheKey = $apartmentId ? "rfid_dropdown_residents_{$apartmentId}" : 'rfid_dropdown_residents_all';

        return Cache::remember($cacheKey, 300, function () use ($apartmentId) {
            $query = DB::table('residents')
                ->join('users', 'residents.user_id', '=', 'users.id')
                ->where('residents.is_active', 1)
                ->whereNull('residents.deleted_at')
                ->select([
                    'users.id as user_id',
                    'users.full_name',
                    'users.phone_number',
                    'residents.apartment_id',
                ]);

            if ($apartmentId) {
                $query->where('residents.apartment_id', $apartmentId);
            }

            return $query->orderBy('users.full_name', 'asc')
                ->limit(300)
                ->get()
                ->toArray();
        });
    }
}
