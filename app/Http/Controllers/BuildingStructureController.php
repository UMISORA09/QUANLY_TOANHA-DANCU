<?php

namespace App\Http\Controllers;

use App\Services\BuildingStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BuildingStructureController extends Controller
{
    public function __construct(
        protected BuildingStructureService $service
    ) {}

    /**
     * Lấy danh sách Khối tòa nhà kèm thống kê số tầng và căn hộ
     * GET /api/v1/blocks
     */
    public function indexBlocks(Request $request): JsonResponse
    {
        try {
            $blocks = $this->service->getBlocks();
            $stats = $this->service->getOverviewStats();

            return response()->json([
                'success' => true,
                'data' => $blocks,
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tải danh sách tòa nhà: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy thống kê tổng quan căn hộ (Đã bán, Đang thuê, Trống, Đang sửa chữa)
     * GET /api/v1/apartments/stats
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $blockId = $request->query('block_id');
            $stats = $this->service->getOverviewStats($blockId ?: null);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tải thống kê: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy danh sách Tầng theo Khối tòa nhà
     * GET /api/v1/blocks/{blockId}/floors
     */
    public function indexFloors(Request $request, string $blockId): JsonResponse
    {
        try {
            $floors = $this->service->getFloorsByBlock($blockId);

            return response()->json([
                'success' => true,
                'data' => $floors,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tải danh sách tầng: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Thêm tầng mới cho khối tòa nhà
     * POST /api/v1/blocks/{blockId}/floors
     */
    public function storeFloor(Request $request, string $blockId): JsonResponse
    {
        $validated = $request->validate([
            'floor_number' => 'required|integer',
            'floor_code' => 'nullable|string|max:30',
            'floor_name' => 'required|string|max:80',
            'floor_type' => 'nullable|string|in:RESIDENTIAL,COMMERCIAL,BASEMENT,TECHNICAL',
            'total_units' => 'nullable|integer|min:0',
            'floor_plan_image_url' => 'nullable|string|max:500',
        ]);

        try {
            $floor = $this->service->createFloor($blockId, $validated);

            return response()->json([
                'success' => true,
                'message' => "Tạo {$floor->floor_name} thành công.",
                'data' => $floor,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tạo tầng: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cập nhật thông tin tầng
     * PUT /api/v1/floors/{floorId}
     */
    public function updateFloor(Request $request, string $floorId): JsonResponse
    {
        $validated = $request->validate([
            'floor_name' => 'nullable|string|max:80',
            'floor_type' => 'nullable|string|in:RESIDENTIAL,COMMERCIAL,BASEMENT,TECHNICAL',
            'floor_plan_image_url' => 'nullable|string|max:500',
        ]);

        try {
            $floor = $this->service->updateFloor($floorId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật tầng thành công.',
                'data' => $floor,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi cập nhật tầng: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Xóa tầng
     * DELETE /api/v1/floors/{floorId}
     */
    public function destroyFloor(Request $request, string $floorId): JsonResponse
    {
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $this->service->deleteFloor($floorId, $force);

            return response()->json([
                'success' => true,
                'message' => 'Xóa tầng thành công.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi xóa tầng: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy danh sách căn hộ (phân trang, tìm kiếm, lọc theo block/tầng/trạng thái)
     * GET /api/v1/apartments
     */
    public function indexApartments(Request $request): JsonResponse
    {
        try {
            $perPage = max(1, min((int) $request->query('per_page', 15), 100));
            $filters = [
                'block_id' => $request->query('block_id'),
                'floor_id' => $request->query('floor_id'),
                'status' => $request->query('status'),
                'room_type' => $request->query('room_type'),
                'search' => $request->query('search'),
                'sort_by' => $request->query('sort_by'),
                'sort_dir' => $request->query('sort_dir'),
            ];

            $paginator = $this->service->getApartments($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tải danh sách căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tạo căn hộ mới đơn lẻ
     * POST /api/v1/apartments
     */
    public function storeApartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'block_id' => 'nullable|string',
            'floor_id' => 'nullable|string',
            'apartment_number' => 'required|string|max:30',
            'room_type' => 'nullable|string|in:STUDIO,1_BEDROOM,2_BEDROOM,3_BEDROOM,PENTHOUSE',
            'gross_floor_area_sqm' => 'nullable|numeric|min:15|max:1000',
            'net_usable_area_sqm' => 'nullable|numeric|min:10|max:1000',
            'bedroom_count' => 'nullable|integer|min:0|max:10',
            'bathroom_count' => 'nullable|integer|min:0|max:10',
            'water_quota_registered' => 'nullable|integer|min:0|max:20',
            'status' => 'nullable|string|in:VACANT,OCCUPIED,RENTED,MAINTENANCE,RESERVED,SOLD,DA_BAN,DANG_THUE,TRONG',
            'monthly_management_fee_fixed' => 'nullable|numeric|min:0',
            'has_balcony' => 'nullable|boolean',
            'furnished_status' => 'nullable|string|in:EMPTY,BASIC,FULLY_FURNISHED,HIGH_END',
            'current_resident_user_id' => 'nullable|string',
        ]);

        try {
            $apartment = $this->service->createApartment($validated);

            return response()->json([
                'success' => true,
                'message' => "Tạo căn hộ {$apartment->apartment_number} thành công.",
                'data' => $apartment,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tạo căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Khởi tạo danh sách căn hộ theo tầng hàng loạt (Batch Initialize / Generator)
     * POST /api/v1/apartments/batch-generate
     */
    public function batchGenerateApartments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'block_id' => 'nullable|string',
            'floor_id' => 'required|string',
            'count' => 'required|integer|min:1|max:50',
            'prefix' => 'nullable|string|max:20',
            'start_number' => 'nullable|integer|min:1|max:999',
            'room_type' => 'nullable|string|in:STUDIO,1_BEDROOM,2_BEDROOM,3_BEDROOM,PENTHOUSE',
            'gross_floor_area_sqm' => 'nullable|numeric|min:15|max:1000',
            'net_usable_area_sqm' => 'nullable|numeric|min:10|max:1000',
            'status' => 'nullable|string|in:VACANT,OCCUPIED,RENTED,MAINTENANCE,SOLD',
        ]);

        try {
            $result = $this->service->batchGenerateApartments($validated);

            return response()->json([
                'success' => true,
                'message' => "Đã tạo thành công {$result['created_count']} căn hộ theo tầng.".($result['skipped_count'] > 0 ? " (Bỏ qua {$result['skipped_count']} căn do trùng mã)" : ''),
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi khởi tạo danh sách căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cập nhật thông tin chi tiết căn hộ
     * PUT /api/v1/apartments/{id}
     */
    public function updateApartment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'apartment_number' => 'nullable|string|max:30',
            'floor_id' => 'nullable|string',
            'room_type' => 'nullable|string|in:STUDIO,1_BEDROOM,2_BEDROOM,3_BEDROOM,PENTHOUSE',
            'gross_floor_area_sqm' => 'nullable|numeric|min:15|max:1000',
            'net_usable_area_sqm' => 'nullable|numeric|min:10|max:1000',
            'bedroom_count' => 'nullable|integer|min:0|max:10',
            'bathroom_count' => 'nullable|integer|min:0|max:10',
            'water_quota_registered' => 'nullable|integer|min:0|max:20',
            'status' => 'nullable|string|in:VACANT,OCCUPIED,RENTED,MAINTENANCE,RESERVED,SOLD,DA_BAN,DANG_THUE,TRONG',
            'monthly_management_fee_fixed' => 'nullable|numeric|min:0',
            'has_balcony' => 'nullable|boolean',
            'furnished_status' => 'nullable|string|in:EMPTY,BASIC,FULLY_FURNISHED,HIGH_END',
            'current_resident_user_id' => 'nullable|string',
        ]);

        try {
            $apartment = $this->service->updateApartment($id, $validated);

            return response()->json([
                'success' => true,
                'message' => "Cập nhật căn hộ {$apartment->apartment_number} thành công.",
                'data' => $apartment,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi cập nhật căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Gắn trạng thái nhanh: Đã bán, Đang thuê, Trống, Đang sửa chữa
     * PATCH /api/v1/apartments/{id}/status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        try {
            $apartment = $this->service->updateStatus($id, $validated['status']);

            return response()->json([
                'success' => true,
                'message' => "Cập nhật trạng thái căn hộ {$apartment->apartment_number} thành '{$apartment->status}' thành công.",
                'data' => $apartment,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi đổi trạng thái căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Xóa mềm căn hộ
     * DELETE /api/v1/apartments/{id}
     */
    public function destroyApartment(string $id): JsonResponse
    {
        try {
            $this->service->deleteApartment($id);

            return response()->json([
                'success' => true,
                'message' => 'Xóa căn hộ thành công.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi xóa căn hộ: '.$e->getMessage(),
            ], 500);
        }
    }
}
