<?php

namespace App\Http\Controllers;

use App\Http\Requests\ZoneRequest;
use App\Models\Block;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller quản lý Khối Tòa nhà (Block/Zone).
 * Tích hợp trực tiếp vào bảng `blocks` (Single Source of Truth).
 * Hỗ trợ phân quyền RBAC, Optimistic Locking (Atomic CAS), tìm kiếm, phân trang và thống kê.
 */
class ZoneController extends Controller
{
    /**
     * Lấy danh sách các khối tòa nhà (hỗ trợ tìm kiếm, lọc trạng thái, phân trang & thống kê).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Block::query()
                ->searchKeyword($request->input('search'))
                ->filterStatus($request->input('status'))
                ->orderBy('created_at', 'desc');

            if ($request->has('per_page')) {
                $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
                $zones = $query->paginate($perPage);
            } else {
                $zones = $query->get();
            }

            // Số liệu thống kê chính xác lấy từ cơ sở dữ liệu thật
            $stats = [
                'total_zones' => Block::count(),
                'active_zones' => Block::where('status', 'ACTIVE')->count(),
                'maintenance_zones' => Block::where('status', 'MAINTENANCE')->count(),
                'total_floors' => (int) Block::sum('total_floors'),
                'total_apartments' => (int) Block::sum('total_apartments'),
                'actual_apartments_count' => (int) DB::table('apartments')->whereNull('deleted_at')->count(),
                'actual_floors_count' => (int) DB::table('floors')->whereNull('deleted_at')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $zones instanceof LengthAwarePaginator ? $zones->items() : $zones,
                'pagination' => $zones instanceof LengthAwarePaginator ? [
                    'current_page' => $zones->currentPage(),
                    'last_page' => $zones->lastPage(),
                    'per_page' => $zones->perPage(),
                    'total' => $zones->total(),
                ] : null,
                'stats' => $stats,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@index] Lỗi lấy danh sách khối nhà: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi tải danh sách khối tòa nhà. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Thêm mới một khối tòa nhà vào bảng blocks.
     */
    public function store(ZoneRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $blockCode = strtoupper(trim((string) ($validated['zone_code'] ?? $validated['block_code'] ?? '')));
            $blockName = (string) ($validated['zone_name'] ?? $validated['block_name'] ?? '');
            $totalFloors = (int) ($validated['floor_count'] ?? $validated['total_floors'] ?? 1);
            $totalBasements = (int) ($validated['basement_count'] ?? $validated['total_basements'] ?? 1);
            $totalApartments = (int) ($validated['total_apartments'] ?? 0);
            $status = strtoupper(trim((string) ($validated['status'] ?? 'ACTIVE')));

            $block = Block::create([
                'block_code' => $blockCode,
                'block_name' => $blockName,
                'total_floors' => $totalFloors,
                'total_basements' => $totalBasements,
                'total_apartments' => $totalApartments,
                'status' => $status,
                'address_line' => $validated['address_line'] ?? null,
                'hotline_phone' => $validated['hotline_phone'] ?? null,
                'description' => $validated['description'] ?? null,
                'version' => 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Thêm mới khối tòa nhà '{$block->block_name}' thành công.",
                'data' => $block,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@store] Lỗi tạo khối nhà: '.$e->getMessage(), [
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tạo khối tòa nhà. Vui lòng kiểm tra lại thông tin.',
            ], 500);
        }
    }

    /**
     * Lấy thông tin chi tiết một khối tòa nhà.
     *
     * @param  string|int  $id
     */
    public function show($id): JsonResponse
    {
        $block = Block::withCount(['floors', 'apartments'])->find($id);

        if (! $block) {
            return response()->json([
                'success' => false,
                'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa khỏi hệ thống.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $block,
        ], 200);
    }

    /**
     * Cập nhật thông tin khối tòa nhà kèm cơ chế OPTIMISTIC LOCKING (Atomic Compare-And-Swap).
     *
     * @param  string|int  $id
     */
    public function update(ZoneRequest $request, $id): JsonResponse
    {
        try {
            // 1. Kiểm tra tồn tại bản ghi
            $block = Block::find($id);

            if (! $block) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa.',
                ], 404);
            }

            // 2. Kiểm tra xung đột Optimistic Locking
            $clientVersion = $request->input('version');
            $clientLastUpdated = $request->input('last_updated_at');

            $isConflict = false;

            if ($clientVersion !== null && $clientVersion !== '') {
                // Kiểm tra trực tiếp bằng số version (chính xác tuyệt đối)
                if ((int) $clientVersion !== (int) $block->version) {
                    $isConflict = true;
                }
            } elseif ($clientLastUpdated) {
                // Fallback nếu client cũ chỉ gửi timestamp last_updated_at
                try {
                    $clientTime = Carbon::parse($clientLastUpdated)->timestamp;
                    $serverTime = Carbon::parse($block->updated_at)->timestamp;
                    if ($clientTime !== $serverTime) {
                        $isConflict = true;
                    }
                } catch (\Throwable $timeEx) {
                    Log::warning('[ZoneController@update] Lỗi parse last_updated_at: '.$timeEx->getMessage());
                }
            }

            if ($isConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu đã bị thay đổi bởi người khác trong lúc bạn đang thao tác. Vui lòng tải lại dữ liệu mới nhất trước khi lưu.',
                    'conflict' => true,
                    'current_data' => $block->fresh(),
                ], 409);
            }

            // 3. Tiến hành Atomic Compare-And-Swap trong Database
            $validated = $request->validated();
            $targetVersion = (int) $block->version;

            $updatePayload = [
                'block_code' => strtoupper(trim((string) ($validated['zone_code'] ?? $validated['block_code'] ?? $block->block_code))),
                'block_name' => (string) ($validated['zone_name'] ?? $validated['block_name'] ?? $block->block_name),
                'total_floors' => (int) ($validated['floor_count'] ?? $validated['total_floors'] ?? $block->total_floors),
                'total_basements' => (int) ($validated['basement_count'] ?? $validated['total_basements'] ?? $block->total_basements),
                'total_apartments' => (int) ($validated['total_apartments'] ?? $block->total_apartments),
                'status' => strtoupper(trim((string) ($validated['status'] ?? $block->status))),
                'address_line' => $validated['address_line'] ?? $block->address_line,
                'hotline_phone' => $validated['hotline_phone'] ?? $block->hotline_phone,
                'description' => $validated['description'] ?? $block->description,
                'version' => $targetVersion + 1,
                'updated_at' => now(),
            ];

            $affected = DB::table('blocks')
                ->where('id', $block->id)
                ->whereNull('deleted_at')
                ->where('version', $targetVersion)
                ->update($updatePayload);

            // Nếu affected === 0 tức là đã có request khác đồng thời ghi thành công trước
            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu đã bị thay đổi bởi người khác trong lúc bạn đang thao tác. Vui lòng tải lại dữ liệu mới nhất trước khi lưu.',
                    'conflict' => true,
                    'current_data' => Block::find($block->id),
                ], 409);
            }

            $freshBlock = Block::find($block->id);

            return response()->json([
                'success' => true,
                'message' => "Cập nhật khối tòa nhà '{$freshBlock->block_name}' thành công.",
                'data' => $freshBlock,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@update] Lỗi cập nhật khối nhà: '.$e->getMessage(), [
                'id' => $id,
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật khối tòa nhà. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Xóa mềm một khối tòa nhà.
     *
     * @param  string|int  $id
     */
    public function destroy($id): JsonResponse
    {
        try {
            $block = Block::find($id);

            if (! $block) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa.',
                ], 404);
            }

            $blockName = $block->block_name;
            $block->delete();

            return response()->json([
                'success' => true,
                'message' => "Đã xóa khối tòa nhà '{$blockName}' thành công.",
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@destroy] Lỗi xóa khối nhà: '.$e->getMessage(), [
                'id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa khối tòa nhà. Vui lòng kiểm tra lại ràng buộc dữ liệu liên quan.',
            ], 500);
        }
    }
}
