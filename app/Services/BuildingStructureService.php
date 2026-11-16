<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BuildingStructureService
{
    /**
     * Lấy danh sách Khối tòa nhà kèm thống kê số tầng và căn hộ
     *
     * @return Collection<int, Block>
     */
    public function getBlocks(): Collection
    {
        $blocks = Block::query()
            ->withCount('floors')
            ->with(['buildingManager:id,full_name,email,phone_number'])
            ->orderBy('block_code', 'asc')
            ->get();

        // 1 truy vấn GROUP BY duy nhất gộp toàn bộ thống kê căn hộ của tất cả khối (O(1) database queries)
        $apartmentStats = DB::table('apartments')
            ->whereNull('deleted_at')
            ->selectRaw("
                block_id,
                COUNT(*) as total_count,
                COUNT(CASE WHEN status IN ('OCCUPIED', 'SOLD', 'DA_BAN') THEN 1 END) as occupied_count,
                COUNT(CASE WHEN status IN ('RENTED', 'DANG_THUE') THEN 1 END) as rented_count,
                COUNT(CASE WHEN status IN ('VACANT', 'TRONG') THEN 1 END) as vacant_count,
                COUNT(CASE WHEN status IN ('MAINTENANCE', 'REPAIRING', 'RESERVED') THEN 1 END) as maintenance_count
            ")
            ->groupBy('block_id')
            ->get()
            ->keyBy('block_id');

        foreach ($blocks as $block) {
            $stat = $apartmentStats->get($block->id);
            $total = (int) ($stat->total_count ?? 0);
            $occupied = (int) ($stat->occupied_count ?? 0);
            $rented = (int) ($stat->rented_count ?? 0);
            $vacant = (int) ($stat->vacant_count ?? 0);
            $maintenance = (int) ($stat->maintenance_count ?? 0);

            $block->apartments_count = $total;
            $block->total_apartments = $total;
            $block->occupied_apartments = $occupied;
            $block->rented_apartments = $rented;
            $block->vacant_apartments = $vacant;
            $block->maintenance_apartments = $maintenance;
        }

        return $blocks;
    }

    /**
     * Thống kê KPI tổng quan toàn bộ căn hộ và các trạng thái
     *
     * @return array<string, mixed>
     */
    public function getOverviewStats(?string $blockId = null): array
    {
        $query = DB::table('apartments')->whereNull('deleted_at');
        if ($blockId) {
            $query->where('block_id', $blockId);
        }

        $row = $query->selectRaw("
            COUNT(*) as total_apartments,
            COUNT(CASE WHEN status IN ('VACANT', 'TRONG') THEN 1 END) as vacant,
            COUNT(CASE WHEN status IN ('OCCUPIED', 'SOLD', 'DA_BAN') THEN 1 END) as occupied,
            COUNT(CASE WHEN status IN ('RENTED', 'DANG_THUE') THEN 1 END) as rented,
            COUNT(CASE WHEN status IN ('MAINTENANCE', 'REPAIRING', 'RESERVED') THEN 1 END) as maintenance
        ")->first();

        $total = (int) ($row->total_apartments ?? 0);
        $vacant = (int) ($row->vacant ?? 0);
        $occupied = (int) ($row->occupied ?? 0);
        $rented = (int) ($row->rented ?? 0);
        $maintenance = (int) ($row->maintenance ?? 0);

        $occupiedTotal = $occupied + $rented;
        $occupancyRate = $total > 0 ? round(($occupiedTotal / $total) * 100, 1) : 0.0;

        return [
            'total_apartments' => $total,
            'vacant' => $vacant,
            'occupied' => $occupied,
            'rented' => $rented,
            'maintenance' => $maintenance,
            'occupancy_rate' => $occupancyRate,
        ];
    }

    /**
     * Lấy danh sách Tầng thuộc Khối tòa nhà
     *
     * @return Collection<int, Floor>
     */
    public function getFloorsByBlock(string $blockId): Collection
    {
        return Floor::query()
            ->where('block_id', $blockId)
            ->withCount('apartments')
            ->orderBy('floor_number', 'asc')
            ->get();
    }

    /**
     * Thêm một tầng mới cho tòa nhà
     *
     * @param  array<string, mixed>  $data
     */
    public function createFloor(string $blockId, array $data): Floor
    {
        $block = Block::findOrFail($blockId);

        $floorNumber = (int) ($data['floor_number'] ?? 1);
        $floorCode = (string) ($data['floor_code'] ?? ($block->block_code.'_F'.sprintf('%02d', abs($floorNumber))));
        $floorName = (string) ($data['floor_name'] ?? ('Tầng '.sprintf('%02d', $floorNumber)));
        $floorType = (string) ($data['floor_type'] ?? 'RESIDENTIAL');

        // Kiểm tra trùng tầng trong cùng block
        $exists = Floor::where('block_id', $blockId)
            ->where(function ($q) use ($floorNumber, $floorCode) {
                $q->where('floor_number', $floorNumber)
                    ->orWhere('floor_code', $floorCode);
            })
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("Tầng số {$floorNumber} hoặc mã '{$floorCode}' đã tồn tại trong khối tòa nhà.");
        }

        return Floor::create([
            'block_id' => $blockId,
            'floor_number' => $floorNumber,
            'floor_code' => $floorCode,
            'floor_name' => $floorName,
            'floor_type' => $floorType,
            'total_units' => (int) ($data['total_units'] ?? 0),
            'floor_plan_image_url' => $data['floor_plan_image_url'] ?? null,
        ]);
    }

    /**
     * Cập nhật thông tin tầng
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFloor(string $floorId, array $data): Floor
    {
        $floor = Floor::findOrFail($floorId);

        if (isset($data['floor_name'])) {
            $floor->floor_name = (string) $data['floor_name'];
        }
        if (isset($data['floor_type'])) {
            $floor->floor_type = (string) $data['floor_type'];
        }
        if (isset($data['floor_plan_image_url'])) {
            $floor->floor_plan_image_url = $data['floor_plan_image_url'];
        }

        $floor->save();

        return $floor;
    }

    /**
     * Xóa tầng (kiểm tra an toàn)
     */
    public function deleteFloor(string $floorId, bool $force = false): bool
    {
        $floor = Floor::findOrFail($floorId);

        $apartmentsCount = $floor->apartments()->count();
        if ($apartmentsCount > 0 && ! $force) {
            throw new RuntimeException("Tầng '{$floor->floor_name}' đang có {$apartmentsCount} căn hộ. Vui lòng di dời hoặc xóa các căn hộ trước.");
        }

        if ($apartmentsCount > 0 && $force) {
            $floor->apartments()->delete();
        }

        return (bool) $floor->delete();
    }

    /**
     * Lấy danh sách căn hộ có phân trang và bộ lọc linh hoạt
     *
     * @param  array<string, mixed>  $filters
     */
    public function getApartments(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Apartment::query()
            ->with([
                'block:id,block_code,block_name',
                'floor:id,floor_number,floor_code,floor_name',
                'currentResident:id,full_name,email,phone_number',
                'headOfHousehold.user:id,full_name,email,phone_number',
                'firstResident.user:id,full_name,email,phone_number',
            ])
            ->withCount('residents');

        if (! empty($filters['block_id']) && $filters['block_id'] !== 'all') {
            $query->where('block_id', $filters['block_id']);
        }

        if (! empty($filters['floor_id']) && $filters['floor_id'] !== 'all') {
            $query->where('floor_id', $filters['floor_id']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $statusVal = strtoupper(trim((string) $filters['status']));
            if (in_array($statusVal, ['SOLD', 'OCCUPIED', 'DA_BAN'], true)) {
                $query->whereIn('status', ['SOLD', 'OCCUPIED', 'DA_BAN']);
            } elseif (in_array($statusVal, ['RENTED', 'DANG_THUE'], true)) {
                $query->whereIn('status', ['RENTED', 'DANG_THUE']);
            } elseif (in_array($statusVal, ['VACANT', 'TRONG'], true)) {
                $query->whereIn('status', ['VACANT', 'TRONG']);
            } elseif (in_array($statusVal, ['MAINTENANCE', 'REPAIRING', 'RESERVED'], true)) {
                $query->whereIn('status', ['MAINTENANCE', 'REPAIRING', 'RESERVED']);
            } else {
                $query->where('status', $statusVal);
            }
        }

        if (! empty($filters['room_type']) && $filters['room_type'] !== 'all') {
            $query->where('room_type', $filters['room_type']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('apartment_number', 'LIKE', "%{$term}%")
                    ->orWhereHas('block', fn ($b) => $b->where('block_name', 'LIKE', "%{$term}%")->orWhere('block_code', 'LIKE', "%{$term}%"))
                    ->orWhereHas('currentResident', fn ($u) => $u->where('full_name', 'LIKE', "%{$term}%")->orWhere('phone_number', 'LIKE', "%{$term}%"))
                    ->orWhereHas('residents.user', fn ($u) => $u->where('full_name', 'LIKE', "%{$term}%")->orWhere('phone_number', 'LIKE', "%{$term}%"));
            });
        }

        $page = max(1, (int) ($filters['page'] ?? request()->input('page', 1)));

        $sortField = $filters['sort_by'] ?? 'apartment_number';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? $filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortField, ['apartment_number', 'gross_floor_area_sqm', 'status', 'created_at'], true)) {
            $query->orderBy($sortField, $sortDir)->orderBy('id', 'asc');
        } else {
            $query->orderBy('apartment_number', 'asc')->orderBy('id', 'asc');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Tạo mới một căn hộ đơn lẻ
     *
     * @param  array<string, mixed>  $data
     */
    public function createApartment(array $data): Apartment
    {
        $blockId = (string) ($data['block_id'] ?? '');
        $floorId = (string) ($data['floor_id'] ?? '');
        $apartmentNumber = strtoupper(trim((string) ($data['apartment_number'] ?? '')));

        if ($apartmentNumber === '') {
            throw new InvalidArgumentException('Mã số căn hộ không được để trống.');
        }

        // Tự suy diễn blockId từ floorId nếu chỉ truyền floorId
        if (! $blockId && $floorId) {
            $floor = Floor::findOrFail($floorId);
            $blockId = $floor->block_id;
        }

        if (! $floorId && $blockId) {
            $firstFloor = Floor::where('block_id', $blockId)->orderBy('floor_number', 'asc')->first();
            if ($firstFloor) {
                $floorId = $firstFloor->id;
            }
        }

        // Kiểm tra trùng mã căn hộ trong cùng một khối tòa nhà
        $exists = Apartment::where('block_id', $blockId)
            ->where('apartment_number', $apartmentNumber)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("Mã căn hộ '{$apartmentNumber}' đã tồn tại trong khối tòa nhà này.");
        }

        $status = strtoupper(trim((string) ($data['status'] ?? 'VACANT')));
        if (! in_array($status, ['VACANT', 'OCCUPIED', 'RENTED', 'MAINTENANCE', 'RESERVED'], true)) {
            $status = 'VACANT';
        }

        $apartment = Apartment::create([
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => $apartmentNumber,
            'room_type' => (string) ($data['room_type'] ?? '2_BEDROOM'),
            'gross_floor_area_sqm' => (float) ($data['gross_floor_area_sqm'] ?? 75.0),
            'net_usable_area_sqm' => (float) ($data['net_usable_area_sqm'] ?? 68.0),
            'bedroom_count' => (int) ($data['bedroom_count'] ?? 2),
            'bathroom_count' => (int) ($data['bathroom_count'] ?? 1),
            'water_quota_registered' => (int) ($data['water_quota_registered'] ?? 4),
            'status' => $status,
            'monthly_management_fee_fixed' => (float) ($data['monthly_management_fee_fixed'] ?? 0),
            'has_balcony' => (bool) ($data['has_balcony'] ?? true),
            'furnished_status' => (string) ($data['furnished_status'] ?? 'BASIC'),
            'current_resident_user_id' => $data['current_resident_user_id'] ?? null,
            'metadata' => json_encode($data['metadata'] ?? []),
        ]);

        // Cập nhật lại số lượng căn hộ cho tầng và khối
        if ($floorId) {
            Floor::where('id', $floorId)->increment('total_units');
        }
        if ($blockId) {
            Block::where('id', $blockId)->increment('total_apartments');
        }

        return $apartment->load(['block', 'floor']);
    }

    /**
     * Khởi tạo danh sách căn hộ theo tầng hàng loạt (Batch Initialize / Generator)
     *
     * @param  array<string, mixed>  $params
     * @return array{created_count: int, skipped_count: int, apartments: array<int, Apartment>}
     */
    public function batchGenerateApartments(array $params): array
    {
        $blockId = (string) ($params['block_id'] ?? '');
        $floorId = (string) ($params['floor_id'] ?? '');
        $count = max(1, min((int) ($params['count'] ?? 8), 100));
        $prefix = trim((string) ($params['prefix'] ?? ''));
        $startNumber = max(1, (int) ($params['start_number'] ?? 1));
        $defaultRoomType = (string) ($params['room_type'] ?? '2_BEDROOM');
        $defaultArea = (float) ($params['gross_floor_area_sqm'] ?? 75.0);
        $netArea = (float) ($params['net_usable_area_sqm'] ?? ($defaultArea * 0.92));
        $status = strtoupper(trim((string) ($params['status'] ?? 'VACANT')));

        if (! in_array($status, ['VACANT', 'OCCUPIED', 'RENTED', 'MAINTENANCE'], true)) {
            $status = 'VACANT';
        }

        $floor = Floor::with('block')->findOrFail($floorId);
        $block = $floor->block ?: Block::findOrFail($blockId ?: $floor->block_id);
        $blockId = $block->id;

        // Nếu prefix không nhập, tự động tạo prefix theo quy tắc: [BlockCodeShort]-[FloorNumber]
        // Ví dụ: Block A, Tầng 8 -> A-8, các căn sẽ là A-801, A-802,...
        if ($prefix === '') {
            $shortBlock = preg_replace('/[^A-Za-z0-9]/', '', str_replace(['BLOCK_', 'TOWER_'], '', $block->block_code)) ?: 'A';
            $prefix = sprintf('%s-%d', $shortBlock, abs($floor->floor_number));
        }

        return DB::transaction(function () use (
            $blockId,
            $floorId,
            $count,
            $prefix,
            $startNumber,
            $defaultRoomType,
            $defaultArea,
            $netArea,
            $status,
            $floor
        ) {
            $created = [];
            $skipped = 0;

            for ($i = 0; $i < $count; $i++) {
                $unitNum = $startNumber + $i;
                $apartmentNumber = sprintf('%s%02d', $prefix, $unitNum);

                // Kiểm tra xem đã tồn tại chưa
                $exists = Apartment::where('block_id', $blockId)
                    ->where('apartment_number', $apartmentNumber)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $apt = Apartment::create([
                    'block_id' => $blockId,
                    'floor_id' => $floorId,
                    'apartment_number' => $apartmentNumber,
                    'room_type' => $defaultRoomType,
                    'gross_floor_area_sqm' => $defaultArea,
                    'net_usable_area_sqm' => $netArea,
                    'bedroom_count' => match ($defaultRoomType) {
                        'STUDIO' => 1,
                        '1_BEDROOM' => 1,
                        '2_BEDROOM' => 2,
                        '3_BEDROOM' => 3,
                        'PENTHOUSE' => 4,
                        default => 2,
                    },
                    'bathroom_count' => match ($defaultRoomType) {
                        'STUDIO', '1_BEDROOM' => 1,
                        '2_BEDROOM' => 2,
                        '3_BEDROOM', 'PENTHOUSE' => 3,
                        default => 2,
                    },
                    'water_quota_registered' => 4,
                    'status' => $status,
                    'monthly_management_fee_fixed' => 0,
                    'has_balcony' => true,
                    'furnished_status' => 'BASIC',
                    'metadata' => json_encode(['batch_generated' => true, 'batch_at' => now()->toIso8601String()]),
                ]);

                $created[] = $apt;
            }

            // Đồng bộ lại tổng số lượng căn
            $actualFloorUnits = Apartment::where('floor_id', $floorId)->count();
            $floor->update(['total_units' => $actualFloorUnits]);

            $actualBlockUnits = Apartment::where('block_id', $blockId)->count();
            Block::where('id', $blockId)->update(['total_apartments' => $actualBlockUnits]);

            // Gọi Stored Procedure tiền xử lý (Database Preprocessing) nếu chạy trên MySQL
            if (DB::getDriverName() === 'mysql') {
                try {
                    DB::statement('CALL sp_preprocess_building_occupancy(?)', [$blockId]);
                } catch (\Throwable) {
                    // Fallback đã hoàn tất ở trên
                }
            }

            return [
                'created_count' => count($created),
                'skipped_count' => $skipped,
                'apartments' => $created,
            ];
        });
    }

    /**
     * Cập nhật thông tin căn hộ
     *
     * @param  array<string, mixed>  $data
     */
    public function updateApartment(string $id, array $data): Apartment
    {
        $apartment = Apartment::findOrFail($id);

        if (isset($data['apartment_number'])) {
            $newNumber = strtoupper(trim((string) $data['apartment_number']));
            if ($newNumber !== '' && $newNumber !== $apartment->apartment_number) {
                $exists = Apartment::where('block_id', $apartment->block_id)
                    ->where('apartment_number', $newNumber)
                    ->where('id', '!=', $id)
                    ->exists();

                if ($exists) {
                    throw new InvalidArgumentException("Mã căn hộ '{$newNumber}' đã được sử dụng trong khối này.");
                }
                $apartment->apartment_number = $newNumber;
            }
        }

        if (isset($data['floor_id'])) {
            $apartment->floor_id = (string) $data['floor_id'];
        }
        if (isset($data['room_type'])) {
            $apartment->room_type = (string) $data['room_type'];
        }
        if (isset($data['gross_floor_area_sqm'])) {
            $apartment->gross_floor_area_sqm = (float) $data['gross_floor_area_sqm'];
        }
        if (isset($data['net_usable_area_sqm'])) {
            $apartment->net_usable_area_sqm = (float) $data['net_usable_area_sqm'];
        }
        if (isset($data['bedroom_count'])) {
            $apartment->bedroom_count = (int) $data['bedroom_count'];
        }
        if (isset($data['bathroom_count'])) {
            $apartment->bathroom_count = (int) $data['bathroom_count'];
        }
        if (isset($data['water_quota_registered'])) {
            $apartment->water_quota_registered = (int) $data['water_quota_registered'];
        }
        if (isset($data['monthly_management_fee_fixed'])) {
            $apartment->monthly_management_fee_fixed = (float) $data['monthly_management_fee_fixed'];
        }
        if (isset($data['has_balcony'])) {
            $apartment->has_balcony = (bool) $data['has_balcony'];
        }
        if (isset($data['furnished_status'])) {
            $apartment->furnished_status = (string) $data['furnished_status'];
        }
        if (array_key_exists('current_resident_user_id', $data)) {
            $apartment->current_resident_user_id = $data['current_resident_user_id'];
        }

        if (isset($data['status'])) {
            $st = strtoupper(trim((string) $data['status']));
            if (in_array($st, ['VACANT', 'OCCUPIED', 'RENTED', 'MAINTENANCE', 'RESERVED'], true)) {
                $apartment->status = $st;
            }
        }

        $apartment->save();

        return $apartment->load(['block', 'floor', 'currentResident']);
    }

    /**
     * Cập nhật trạng thái nhanh (Đã bán, Đang thuê, Trống, Đang sửa chữa)
     */
    public function updateStatus(string $id, string $status): Apartment
    {
        $apartment = Apartment::findOrFail($id);

        $normalized = strtoupper(trim($status));
        $validStatuses = [
            'VACANT' => 'VACANT',
            'TRONG' => 'VACANT',
            'OCCUPIED' => 'OCCUPIED',
            'DA_BAN' => 'OCCUPIED',
            'RENTED' => 'RENTED',
            'DANG_THUE' => 'RENTED',
            'MAINTENANCE' => 'MAINTENANCE',
            'DANG_SUA_CHUA' => 'MAINTENANCE',
        ];

        $targetStatus = $validStatuses[$normalized] ?? null;
        if (! $targetStatus) {
            throw new InvalidArgumentException("Trạng thái '{$status}' không hợp lệ. Cho phép: Đã bán (OCCUPIED), Đang thuê (RENTED), Trống (VACANT), Đang sửa chữa (MAINTENANCE).");
        }

        $apartment->status = $targetStatus;
        $apartment->save();

        return $apartment->load(['block', 'floor']);
    }

    /**
     * Xóa mềm căn hộ
     */
    public function deleteApartment(string $id): bool
    {
        $apartment = Apartment::findOrFail($id);
        $floorId = $apartment->floor_id;
        $blockId = $apartment->block_id;

        $res = (bool) $apartment->delete();

        if ($res) {
            if ($floorId) {
                Floor::where('id', $floorId)->where('total_units', '>', 0)->decrement('total_units');
            }
            if ($blockId) {
                Block::where('id', $blockId)->where('total_apartments', '>', 0)->decrement('total_apartments');
            }
        }

        return $res;
    }
}
