<?php

namespace App\Http\Controllers;

use App\Http\Requests\ZoneRequest;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller xử lý nghiệp vụ Quản lý Khối Tòa nhà / Khu vực (Block/Zone).
 * Đảm bảo các chuẩn RESTful, FormRequest Validation và Optimistic Locking (HTTP 409).
 */
class ZoneController extends Controller
{
    /**
     * Lấy danh sách các khối tòa nhà (có hỗ trợ tìm kiếm, lọc trạng thái & thống kê).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Zone::query()
                ->searchKeyword($request->input('search'))
                ->filterStatus($request->input('status'))
                ->orderBy('created_at', 'desc');

            $zones = $query->get();

            // Tính toán số liệu thống kê nhanh
            $stats = [
                'total_zones' => Zone::count(),
                'active_zones' => Zone::where('status', 'ACTIVE')->count(),
                'maintenance_zones' => Zone::where('status', 'MAINTENANCE')->count(),
                'total_floors' => (int) Zone::sum('floor_count'),
                'total_apartments' => (int) Zone::sum('total_apartments'),
            ];

            return response()->json([
                'success' => true,
                'data' => $zones,
                'stats' => $stats,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@index] Lỗi lấy danh sách khối nhà: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi tải danh sách khối tòa nhà: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Thêm mới một khối tòa nhà.
     */
    public function store(ZoneRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Chuẩn hóa mã khối (in hoa, bỏ khoảng trắng thừa)
            $validated['zone_code'] = strtoupper(trim($validated['zone_code']));
            $validated['status'] = $validated['status'] ?? 'ACTIVE';

            $zone = Zone::create($validated);

            return response()->json([
                'success' => true,
                'message' => "Thêm mới khối tòa nhà '{$zone->zone_name}' thành công.",
                'data' => $zone,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@store] Lỗi tạo khối nhà: '.$e->getMessage(), [
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tạo khối tòa nhà. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Lấy thông tin chi tiết một khối tòa nhà.
     *
     * @param  int|string  $id
     */
    public function show($id): JsonResponse
    {
        $zone = Zone::find($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa khỏi hệ thống.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $zone,
        ], 200);
    }

    /**
     * Cập nhật thông tin khối tòa nhà kèm cơ chế OPTIMISTIC LOCKING (Khóa lạc quan).
     *
     * Luồng hoạt động:
     * 1. Tìm bản ghi theo ID. Nếu không có -> trả về HTTP 404 Not Found.
     * 2. Nhận trường last_updated_at từ Request, parse và so sánh với updated_at trong Database.
     * 3. Nếu lệch (khác timestamp) -> trả về HTTP 409 Conflict kèm dữ liệu hiện tại trong DB.
     * 4. Nếu khớp -> cập nhật an toàn và trả về HTTP 200 OK.
     *
     * @param  int|string  $id
     */
    public function update(ZoneRequest $request, $id): JsonResponse
    {
        try {
            // 1. Kiểm tra tồn tại bản ghi
            $zone = Zone::find($id);

            if (! $zone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa.',
                ], 404);
            }

            // 2. Kiểm tra OPTIMISTIC LOCKING
            $clientLastUpdated = $request->input('last_updated_at');

            if ($clientLastUpdated) {
                try {
                    $clientTime = Carbon::parse($clientLastUpdated)->timestamp;
                    $serverTime = Carbon::parse($zone->updated_at)->timestamp;

                    // Nếu thời điểm client giữ khác với thời điểm mới nhất trong DB -> Xung đột (Conflict)
                    if ($clientTime !== $serverTime) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Dữ liệu đã bị thay đổi bởi người khác trong lúc bạn đang thao tác. Vui lòng tải lại dữ liệu mới nhất trước khi lưu.',
                            'conflict' => true,
                            'current_data' => $zone,
                        ], 409);
                    }
                } catch (\Exception $timeEx) {
                    Log::warning('[ZoneController@update] Lỗi phân tích last_updated_at: '.$timeEx->getMessage());
                }
            }

            // 3. Tiến hành cập nhật dữ liệu
            $validated = $request->validated();
            unset($validated['last_updated_at']); // Bỏ trường kiểm soát ra khỏi mảng update

            if (isset($validated['zone_code'])) {
                $validated['zone_code'] = strtoupper(trim($validated['zone_code']));
            }

            $zone->update($validated);

            return response()->json([
                'success' => true,
                'message' => "Cập nhật khối tòa nhà '{$zone->zone_name}' thành công.",
                'data' => $zone->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[ZoneController@update] Lỗi cập nhật khối nhà: '.$e->getMessage(), [
                'id' => $id,
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật khối tòa nhà. Vui lòng thử lại sau: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Xóa mềm một khối tòa nhà.
     *
     * @param  int|string  $id
     */
    public function destroy($id): JsonResponse
    {
        try {
            $zone = Zone::find($id);

            if (! $zone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khối tòa nhà không tồn tại hoặc đã bị xóa.',
                ], 404);
            }

            $zoneName = $zone->zone_name;
            $zone->delete();

            return response()->json([
                'success' => true,
                'message' => "Đã xóa khối tòa nhà '{$zoneName}' thành công.",
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
