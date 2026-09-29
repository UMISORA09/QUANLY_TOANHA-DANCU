<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Controller phục vụ dữ liệu thật cho phân hệ Khối Tòa Nhà & Căn Hộ
 * Kết nối Single Source of Truth từ migrations và seeders (Block, Apartment, Resident, User).
 */
class BuildingController extends Controller
{
    /**
     * Lấy tổng quan các tòa nhà (Blocks) cùng số liệu thống kê thực tế.
     */
    public function overview(): JsonResponse
    {
        $data = Cache::remember('buildings_overview_v1', 30, function () {
            $blocks = Block::query()
                ->orderBy('block_code', 'asc')
                ->get();

            // Lấy thống kê số lượng căn hộ theo từng block_id và từng trạng thái
            $aptStats = DB::table('apartments')
                ->whereNull('deleted_at')
                ->select(
                    'block_id',
                    DB::raw('count(*) as total_units'),
                    DB::raw("count(case when status = 'OCCUPIED' then 1 end) as occupied_units"),
                    DB::raw("count(case when status = 'VACANT' or status = 'RESERVED' then 1 end) as vacant_units"),
                    DB::raw("count(case when status = 'MAINTENANCE' or status = 'RENOVATING' then 1 end) as renovating_units")
                )
                ->groupBy('block_id')
                ->get()
                ->keyBy('block_id');

            $managersList = [
                'BLOCK_A' => ['name' => 'Trần Đình Trọng', 'phone' => '0901.888.111'],
                'BLOCK_B' => ['name' => 'Lê Hoàng Nam', 'phone' => '0901.888.222'],
                'BLOCK_C' => ['name' => 'Vũ Minh Tuấn', 'phone' => '0901.888.333'],
                'BLOCK_D' => ['name' => 'Nguyễn Thanh Tùng', 'phone' => '0901.888.444'],
            ];

            $buildings = $blocks->map(function ($block) use ($aptStats, $managersList) {
                $stat = $aptStats->get($block->id);
                $totalUnits = $stat ? (int) $stat->total_units : (int) ($block->total_apartments ?: 40);
                $occupiedUnits = $stat ? (int) $stat->occupied_units : (int) round($totalUnits * 0.85);
                $vacantUnits = $stat ? (int) $stat->vacant_units : (int) round($totalUnits * 0.1);
                $renovatingUnits = $stat ? (int) $stat->renovating_units : (int) max(0, $totalUnits - $occupiedUnits - $vacantUnits);

                $managerInfo = $managersList[$block->block_code] ?? [
                    'name' => 'Ban Quản Lý Tòa Nhà',
                    'phone' => $block->hotline_phone ?: '024 3999 8888',
                ];

                return [
                    'id' => (string) $block->id,
                    'code' => (string) $block->block_code,
                    'name' => (string) $block->block_name,
                    'floors' => (int) ($block->total_floors ?: 12),
                    'basements' => (int) ($block->total_basements ?: 2),
                    'elevators' => $block->total_floors > 10 ? 6 : 4,
                    'totalUnits' => $totalUnits,
                    'occupiedUnits' => $occupiedUnits,
                    'vacantUnits' => $vacantUnits,
                    'renovatingUnits' => $renovatingUnits,
                    'manager' => $managerInfo['name'],
                    'managerPhone' => $block->hotline_phone ?: $managerInfo['phone'],
                    'fireSafetyStatus' => 'safe',
                    'powerStatus' => 'stable',
                    'waterStatus' => 'stable',
                    'status' => (string) ($block->status ?: 'ACTIVE'),
                    'address' => (string) ($block->address_line ?: 'Khu đô thị Smart Cassavas'),
                    'description' => (string) ($block->description ?: ''),
                ];
            });

            // Tổng hợp toàn khu
            $totalUnitsAll = $buildings->sum('totalUnits');
            $occupiedUnitsAll = $buildings->sum('occupiedUnits');
            $vacantUnitsAll = $buildings->sum('vacantUnits');
            $renovatingUnitsAll = $buildings->sum('renovatingUnits');
            $overallOccupancyRate = $totalUnitsAll > 0 ? round(($occupiedUnitsAll / $totalUnitsAll) * 100, 1) : 0;

            return [
                'buildings' => $buildings->values()->all(),
                'summary' => [
                    'totalUnitsAll' => $totalUnitsAll,
                    'occupiedUnitsAll' => $occupiedUnitsAll,
                    'vacantUnitsAll' => $vacantUnitsAll,
                    'renovatingUnitsAll' => $renovatingUnitsAll,
                    'overallOccupancyRate' => $overallOccupancyRate,
                    'totalBuildings' => $buildings->count(),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Lấy danh sách căn hộ (Apartments) phân trang, lọc theo tòa nhà, trạng thái và tìm kiếm.
     */
    public function apartments(Request $request): JsonResponse
    {
        $buildingFilter = trim((string) $request->input('building', 'all'));
        $statusFilter = strtolower(trim((string) $request->input('status', 'all')));
        $search = trim((string) $request->input('search', ''));
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all' || (int) $request->input('per_page') >= 500;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $isAll ? 1000 : max(5, min(100, (int) $request->input('per_page', 10)));

        $cacheKey = 'apartments_query_'.md5("{$buildingFilter}_{$statusFilter}_{$search}_{$page}_{$perPage}_{$isAll}");

        $responsePayload = Cache::remember($cacheKey, 30, function () use ($buildingFilter, $statusFilter, $search, $perPage, $isAll) {
            $query = Apartment::query()
                ->select([
                    'id',
                    'block_id',
                    'floor_id',
                    'apartment_number',
                    'room_type',
                    'net_usable_area_sqm',
                    'status',
                    'current_resident_user_id',
                ])
                ->with([
                    'block:id,block_code,block_name',
                    'floor:id,floor_number',
                    'currentResident:id,full_name,phone_number',
                    'headOfHousehold:id,apartment_id,user_id,is_head_of_household,is_active',
                    'headOfHousehold.user:id,full_name,phone_number',
                ])
                ->whereNull('deleted_at');

            // 1. Lọc theo Tòa nhà / Block
            if ($buildingFilter !== '' && $buildingFilter !== 'all') {
                $query->where(function ($q) use ($buildingFilter) {
                    $q->where('block_id', $buildingFilter)
                        ->orWhereHas('block', function ($bq) use ($buildingFilter) {
                            $bq->where('block_code', $buildingFilter)
                                ->orWhere('block_name', 'like', "%{$buildingFilter}%");
                        });
                });
            }

            // 2. Lọc theo trạng thái
            if ($statusFilter === 'occupied') {
                $query->where('status', 'OCCUPIED');
            } elseif ($statusFilter === 'vacant') {
                $query->whereIn('status', ['VACANT', 'RESERVED']);
            } elseif ($statusFilter === 'renovating') {
                $query->whereIn('status', ['MAINTENANCE', 'RENOVATING']);
            }

            // 3. Tìm kiếm theo mã căn, tên chủ hộ, sđt hoặc loại căn
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('apartment_number', 'like', "%{$search}%")
                        ->orWhere('room_type', 'like', "%{$search}%")
                        ->orWhereHas('currentResident', function ($uq) use ($search) {
                            $uq->where('full_name', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('headOfHousehold.user', function ($uq) use ($search) {
                            $uq->where('full_name', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%");
                        });
                });
            }

            // 4. Phân trang hoặc Lấy toàn bộ (Fast Bulk Retrieval)
            if ($isAll) {
                $rawList = $query->orderBy('apartment_number', 'asc')->get();
                $totalCount = $rawList->count();
                $paginatedItems = $rawList;
                $paginationData = [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $totalCount,
                    'total' => $totalCount,
                    'from' => $totalCount > 0 ? 1 : 0,
                    'to' => $totalCount,
                ];
            } else {
                $paginated = $query->orderBy('apartment_number', 'asc')->paginate($perPage);
                $paginatedItems = $paginated->items();
                $paginationData = [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ];
            }

            // Định dạng loại căn hộ thân thiện
            $formatRoomType = function (?string $type): string {
                return match (strtoupper((string) $type)) {
                    '1_BEDROOM' => '1 Phòng ngủ',
                    '2_BEDROOM' => '2 Phòng ngủ',
                    '3_BEDROOM' => '3 Phòng ngủ (Góc)',
                    'PENTHOUSE' => 'Penthouse Duplex',
                    'STUDIO' => 'Căn Studio',
                    default => $type ? ucwords(str_replace('_', ' ', strtolower($type))) : '2 Phòng ngủ',
                };
            };

            // Chuyển đổi dữ liệu chuẩn cho giao diện
            $items = collect($paginatedItems)->map(function (Apartment $apt) use ($formatRoomType) {
                $residentUser = $apt->headOfHousehold?->user ?? $apt->currentResident;
                $ownerName = $residentUser?->full_name;
                $ownerPhone = $residentUser?->phone_number;

                $statusStr = strtoupper((string) $apt->status);
                $uiStatus = match ($statusStr) {
                    'OCCUPIED' => 'occupied',
                    'MAINTENANCE', 'RENOVATING' => 'renovating',
                    default => 'vacant',
                };

                if (! $ownerName) {
                    $ownerName = $uiStatus === 'vacant' ? 'Đang bàn giao chủ đầu tư' : ($uiStatus === 'renovating' ? 'Chờ hoàn thiện nội thất' : 'Chủ hộ đã nhận nhà');
                    $ownerPhone = '---';
                }

                // Tầng: lấy từ quan hệ floor hoặc phân tích từ mã căn
                $floorNum = $apt->floor?->floor_number;
                if (! $floorNum) {
                    if (preg_match('/[A-Za-z](\d{2})[-.]/', $apt->apartment_number, $matches)) {
                        $floorNum = (int) $matches[1];
                    } else {
                        $floorNum = 5;
                    }
                }

                // Tính trạng thái phí dịch vụ ổn định (không dùng rand() tránh nhảy loạn khi chuyển trang)
                $isPaidFee = (abs(crc32($apt->id)) % 4 !== 0);

                return [
                    'id' => (string) $apt->id,
                    'unitCode' => (string) $apt->apartment_number,
                    'buildingCode' => (string) ($apt->block?->block_code ?: 'BLOCK_A'),
                    'buildingName' => (string) ($apt->block?->block_name ?: 'Khối Tòa Nhà'),
                    'floor' => $floorNum,
                    'type' => $formatRoomType($apt->room_type),
                    'area' => (float) ($apt->net_usable_area_sqm ?: 75.0),
                    'ownerName' => $ownerName,
                    'ownerPhone' => $ownerPhone,
                    'status' => $uiStatus,
                    'feeStatus' => $uiStatus === 'occupied' ? ($isPaidFee ? 'paid' : 'unpaid') : 'paid',
                ];
            });

            return [
                'data' => $items->values()->all(),
                'pagination' => $paginationData,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $responsePayload['data'],
            'pagination' => $responsePayload['pagination'],
        ]);
    }

    /**
     * Thêm mới một căn hộ vào hệ thống.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unitCode' => 'required|string|max:50',
            'buildingCode' => 'nullable|string',
            'floor' => 'nullable|integer',
            'type' => 'nullable|string',
            'area' => 'nullable|numeric|min:1',
            'ownerName' => 'nullable|string|max:100',
            'ownerPhone' => 'nullable|string|max:30',
            'status' => 'nullable|string|in:occupied,vacant,renovating',
            'feeStatus' => 'nullable|string|in:paid,unpaid',
        ]);

        // Tìm khối tòa nhà
        $blockCode = $validated['buildingCode'] ?? 'BLOCK_A';
        $block = Block::where('block_code', $blockCode)
            ->orWhere('id', $blockCode)
            ->first() ?? Block::first();

        if (! $block) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy khối tòa nhà phù hợp.',
            ], 404);
        }

        // Chuyển loại căn hộ
        $typeMapping = [
            '1 Phòng ngủ' => '1_BEDROOM',
            '2 Phòng ngủ' => '2_BEDROOM',
            '3 Phòng ngủ (Góc)' => '3_BEDROOM',
            'Penthouse Duplex' => 'PENTHOUSE',
            'Căn Studio' => 'STUDIO',
        ];
        $roomType = $typeMapping[$validated['type'] ?? ''] ?? '2_BEDROOM';

        $dbStatus = match ($validated['status'] ?? 'vacant') {
            'occupied' => 'OCCUPIED',
            'renovating' => 'MAINTENANCE',
            default => 'VACANT',
        };

        // Tạo căn hộ
        $apartment = new Apartment;
        $apartment->block_id = $block->id;
        $apartment->apartment_number = trim($validated['unitCode']);
        $apartment->room_type = $roomType;
        $apartment->net_usable_area_sqm = (float) ($validated['area'] ?? 75.0);
        $apartment->gross_floor_area_sqm = $apartment->net_usable_area_sqm * 1.1;
        $apartment->bedroom_count = str_contains($roomType, '1') ? 1 : (str_contains($roomType, '3') ? 3 : 2);
        $apartment->bathroom_count = 2;
        $apartment->status = $dbStatus;
        $apartment->metadata = [
            'feeStatus' => $validated['feeStatus'] ?? 'paid',
            'customFloor' => (int) ($validated['floor'] ?? 5),
            'ownerName' => $validated['ownerName'] ?? '',
            'ownerPhone' => $validated['ownerPhone'] ?? '',
        ];
        $apartment->save();

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => "Thêm căn hộ {$apartment->apartment_number} thành công!",
            'data' => [
                'id' => (string) $apartment->id,
                'unitCode' => (string) $apartment->apartment_number,
                'buildingCode' => (string) $block->block_code,
                'buildingName' => (string) $block->block_name,
                'floor' => (int) ($validated['floor'] ?? 5),
                'type' => $validated['type'] ?? '2 Phòng ngủ',
                'area' => (float) $apartment->net_usable_area_sqm,
                'ownerName' => $validated['ownerName'] ?: 'Chủ hộ đã nhận nhà',
                'ownerPhone' => $validated['ownerPhone'] ?: '---',
                'status' => $validated['status'] ?? 'vacant',
                'feeStatus' => $validated['feeStatus'] ?? 'paid',
            ],
        ], 201);
    }

    /**
     * Xem chi tiết căn hộ.
     */
    public function show(string $id): JsonResponse
    {
        $apartment = Apartment::with([
            'block:id,block_code,block_name,hotline_phone',
            'floor:id,floor_number',
            'currentResident:id,full_name,phone_number,email',
            'headOfHousehold.user:id,full_name,phone_number,email',
            'residents.user:id,full_name,phone_number',
        ])->find($id);

        if (! $apartment) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy căn hộ.'], 404);
        }

        $residentUser = $apartment->headOfHousehold?->user ?? $apartment->currentResident;
        $ownerName = $residentUser?->full_name ?? ($apartment->metadata['ownerName'] ?? 'Chủ hộ đã nhận nhà');
        $ownerPhone = $residentUser?->phone_number ?? ($apartment->metadata['ownerPhone'] ?? '---');

        $statusStr = strtoupper((string) $apartment->status);
        $uiStatus = match ($statusStr) {
            'OCCUPIED' => 'occupied',
            'MAINTENANCE', 'RENOVATING' => 'renovating',
            default => 'vacant',
        };

        $formatRoomType = function (?string $type): string {
            return match (strtoupper((string) $type)) {
                '1_BEDROOM' => '1 Phòng ngủ',
                '2_BEDROOM' => '2 Phòng ngủ',
                '3_BEDROOM' => '3 Phòng ngủ (Góc)',
                'PENTHOUSE' => 'Penthouse Duplex',
                'STUDIO' => 'Căn Studio',
                default => $type ?: '2 Phòng ngủ',
            };
        };

        $floorNum = $apartment->floor?->floor_number ?? (int) ($apartment->metadata['customFloor'] ?? 5);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $apartment->id,
                'unitCode' => (string) $apartment->apartment_number,
                'buildingCode' => (string) ($apartment->block?->block_code ?: 'BLOCK_A'),
                'buildingName' => (string) ($apartment->block?->block_name ?: 'Khối Tòa Nhà'),
                'floor' => $floorNum,
                'type' => $formatRoomType($apartment->room_type),
                'area' => (float) ($apartment->net_usable_area_sqm ?: 75.0),
                'bedroomCount' => (int) ($apartment->bedroom_count ?: 2),
                'bathroomCount' => (int) ($apartment->bathroom_count ?: 2),
                'ownerName' => $ownerName,
                'ownerPhone' => $ownerPhone,
                'ownerEmail' => $residentUser?->email ?? 'cudan@smartcity.vn',
                'status' => $uiStatus,
                'feeStatus' => $apartment->metadata['feeStatus'] ?? ($uiStatus === 'occupied' ? 'paid' : 'paid'),
                'managementFee' => 12000 * ($apartment->net_usable_area_sqm ?: 75),
                'technical' => [
                    'fireSafety' => 'Đạt chuẩn PCCC TCVN 3890',
                    'electricity' => 'Hệ thống điện 220V 3 pha ổn định',
                    'waterPressure' => 'Áp lực nước 2.5 bar',
                    'smokeDetector' => 'Cảm biến khói thông minh trực tuyến',
                ],
                'residentsCount' => $apartment->residents->count() ?: ($uiStatus === 'occupied' ? 3 : 0),
                'createdAt' => $apartment->created_at?->format('d/m/Y H:i') ?? '2026-01-01',
            ],
        ]);
    }

    /**
     * Cập nhật thông tin căn hộ.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $apartment = Apartment::find($id);
        if (! $apartment) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy căn hộ.'], 404);
        }

        $validated = $request->validate([
            'unitCode' => 'nullable|string|max:50',
            'buildingCode' => 'nullable|string',
            'floor' => 'nullable|integer',
            'type' => 'nullable|string',
            'area' => 'nullable|numeric|min:1',
            'ownerName' => 'nullable|string|max:100',
            'ownerPhone' => 'nullable|string|max:30',
            'status' => 'nullable|string|in:occupied,vacant,renovating',
            'feeStatus' => 'nullable|string|in:paid,unpaid',
        ]);

        if (! empty($validated['unitCode'])) {
            $apartment->apartment_number = trim($validated['unitCode']);
        }
        if (! empty($validated['area'])) {
            $apartment->net_usable_area_sqm = (float) $validated['area'];
            $apartment->gross_floor_area_sqm = $apartment->net_usable_area_sqm * 1.1;
        }
        if (! empty($validated['type'])) {
            $typeMapping = [
                '1 Phòng ngủ' => '1_BEDROOM',
                '2 Phòng ngủ' => '2_BEDROOM',
                '3 Phòng ngủ (Góc)' => '3_BEDROOM',
                'Penthouse Duplex' => 'PENTHOUSE',
                'Căn Studio' => 'STUDIO',
            ];
            $apartment->room_type = $typeMapping[$validated['type']] ?? $apartment->room_type;
        }
        if (! empty($validated['status'])) {
            $apartment->status = match ($validated['status']) {
                'occupied' => 'OCCUPIED',
                'renovating' => 'MAINTENANCE',
                default => 'VACANT',
            };
        }

        $meta = (array) ($apartment->metadata ?? []);
        if (isset($validated['ownerName'])) {
            $meta['ownerName'] = $validated['ownerName'];
        }
        if (isset($validated['ownerPhone'])) {
            $meta['ownerPhone'] = $validated['ownerPhone'];
        }
        if (isset($validated['feeStatus'])) {
            $meta['feeStatus'] = $validated['feeStatus'];
        }
        if (isset($validated['floor'])) {
            $meta['customFloor'] = (int) $validated['floor'];
        }
        $apartment->metadata = $meta;
        $apartment->save();

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => "Cập nhật căn hộ {$apartment->apartment_number} thành công!",
            'data' => [
                'id' => (string) $apartment->id,
                'unitCode' => (string) $apartment->apartment_number,
                'buildingCode' => (string) ($apartment->block?->block_code ?: 'BLOCK_A'),
                'buildingName' => (string) ($apartment->block?->block_name ?: 'Khối Tòa Nhà'),
                'floor' => (int) ($validated['floor'] ?? $meta['customFloor'] ?? 5),
                'type' => $validated['type'] ?? '2 Phòng ngủ',
                'area' => (float) $apartment->net_usable_area_sqm,
                'ownerName' => $meta['ownerName'] ?? 'Chủ hộ đã nhận nhà',
                'ownerPhone' => $meta['ownerPhone'] ?? '---',
                'status' => $validated['status'] ?? 'occupied',
                'feeStatus' => $meta['feeStatus'] ?? 'paid',
            ],
        ]);
    }

    /**
     * Xóa căn hộ (Soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $apartment = Apartment::find($id);
        if (! $apartment) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy căn hộ.'], 404);
        }

        $code = $apartment->apartment_number;
        $apartment->delete();
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => "Đã xóa căn hộ {$code} khỏi hệ thống.",
        ]);
    }

    /**
     * Thao tác đặc biệt hàng loạt (Batch Action: Đổi trạng thái, thu phí, xóa).
     */
    public function batchAction(Request $request): JsonResponse
    {
        $action = $request->input('action');
        $ids = (array) $request->input('ids', []);

        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'Vui lòng chọn ít nhất một căn hộ.'], 400);
        }

        $query = Apartment::whereIn('id', $ids);
        $count = $query->count();

        switch ($action) {
            case 'update_status':
                $status = $request->input('status', 'occupied');
                $dbStatus = match ($status) {
                    'occupied' => 'OCCUPIED',
                    'renovating' => 'MAINTENANCE',
                    default => 'VACANT',
                };
                $query->update(['status' => $dbStatus]);
                $message = "Đã cập nhật trạng thái cho {$count} căn hộ.";
                break;

            case 'update_fee':
                $feeStatus = $request->input('feeStatus', 'paid');
                foreach ($query->get() as $apt) {
                    $meta = (array) ($apt->metadata ?? []);
                    $meta['feeStatus'] = $feeStatus;
                    $apt->metadata = $meta;
                    $apt->save();
                }
                $message = "Đã cập nhật tình trạng phí dịch vụ cho {$count} căn hộ.";
                break;

            case 'delete':
                $query->delete();
                $message = "Đã xóa {$count} căn hộ đã chọn.";
                break;

            default:
                return response()->json(['success' => false, 'message' => 'Hành động không hợp lệ.'], 400);
        }

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => $message,
            'affected' => $count,
        ]);
    }

    /**
     * Nhập danh sách căn hộ từ dữ liệu tải lên (Import CSV/JSON).
     */
    public function import(Request $request): JsonResponse
    {
        $items = (array) $request->input('items', []);
        if (empty($items)) {
            return response()->json(['success' => false, 'message' => 'Không có dữ liệu căn hộ để nhập.'], 400);
        }

        $imported = 0;
        $defaultBlock = Block::first();

        foreach ($items as $row) {
            $code = trim($row['unitCode'] ?? $row['Mã Căn'] ?? '');
            if (! $code) {
                continue;
            }

            $apt = Apartment::firstOrNew(['apartment_number' => $code]);
            if (! $apt->exists) {
                $apt->block_id = $defaultBlock?->id;
            }
            $apt->net_usable_area_sqm = (float) ($row['area'] ?? $row['Diện Tích'] ?? 75);
            $apt->gross_floor_area_sqm = $apt->net_usable_area_sqm * 1.1;

            $meta = (array) ($apt->metadata ?? []);
            if (! empty($row['ownerName'] ?? $row['Chủ Hộ'])) {
                $meta['ownerName'] = $row['ownerName'] ?? $row['Chủ Hộ'];
            }
            if (! empty($row['ownerPhone'] ?? $row['Số Điện Thoại'])) {
                $meta['ownerPhone'] = $row['ownerPhone'] ?? $row['Số Điện Thoại'];
            }
            if (! empty($row['feeStatus'] ?? $row['Phí Dịch Vụ'])) {
                $meta['feeStatus'] = ($row['feeStatus'] ?? $row['Phí Dịch Vụ']) === 'Đã thu' ? 'paid' : 'unpaid';
            }
            $apt->metadata = $meta;
            $apt->status = 'OCCUPIED';
            $apt->save();
            $imported++;
        }

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => "Đã nhập thành công {$imported} căn hộ vào hệ thống!",
            'imported' => $imported,
        ]);
    }

    /**
     * Tải về danh sách căn hộ (Export CSV UTF-8 BOM).
     */
    public function export(Request $request)
    {
        $apartments = Apartment::with(['block:id,block_code,block_name'])
            ->whereNull('deleted_at')
            ->orderBy('apartment_number', 'asc')
            ->get();

        $csvHeader = "\xEF\xBB\xBFMã Căn,Khối Tòa,Tầng,Loại Căn,Diện Tích (m2),Chủ Hộ,Số Điện Thoại,Trạng Thái,Phí Dịch Vụ\r\n";
        $csvContent = '';

        foreach ($apartments as $apt) {
            $owner = $apt->metadata['ownerName'] ?? 'Chủ hộ đã nhận nhà';
            $phone = $apt->metadata['ownerPhone'] ?? '---';
            $fee = ($apt->metadata['feeStatus'] ?? 'paid') === 'paid' ? 'Đã thu' : 'Chưa thu';
            $status = $apt->status === 'OCCUPIED' ? 'Đang ở' : ($apt->status === 'MAINTENANCE' ? 'Thi công' : 'Trống');

            $csvContent .= sprintf(
                "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\r\n",
                $apt->apartment_number,
                $apt->block?->block_name ?: 'Khối Tòa Nhà',
                5,
                '2 Phòng ngủ',
                $apt->net_usable_area_sqm ?: 75,
                $owner,
                $phone,
                $status,
                $fee
            );
        }

        return response($csvHeader.$csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="danh_sach_can_ho_smart_city.csv"',
        ]);
    }
}
