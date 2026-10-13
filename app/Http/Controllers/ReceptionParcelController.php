<?php

namespace App\Http\Controllers;

use App\Models\Parcel;
use App\Services\ReceptionParcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReceptionParcelController extends Controller
{
    public function __construct(
        protected ReceptionParcelService $service
    ) {}

    /**
     * Danh sách kiện hàng bưu phẩm có phân trang, bộ lọc và KPIs
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'apartment_id' => $request->query('apartment_id'),
            'courier_company' => $request->query('courier_company'),
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
            'search' => $request->query('search'),
        ];

        $perPage = min(100, max(1, (int) ($request->query('per_page', $request->query('limit', 15)))));

        $result = $this->service->getParcels($filters, $perPage);
        $paginator = $result['data'];

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'kpis' => $result['kpis'],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ], 200);
    }

    /**
     * Tiếp nhận kiện hàng mới tại quầy Lễ tân
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => 'required|string|max:100',
            'courier_company' => 'required|string|max:80',
            'apartment_id' => 'required|string',
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'required|string|max:25',
            'stored_location_type' => 'nullable|string|in:RECEPTION_DESK,SMART_LOCKER,SECURITY_BOOTH',
            'notes' => 'nullable|string|max:1000',
            'recipient_user_id' => 'nullable|string',
            'parcel_photo_url' => 'nullable|string|max:500',
            'photo' => 'nullable|file',
        ]);

        $photoFile = $request->file('photo');

        try {
            $parcel = $this->service->receiveParcel(
                data: $validated,
                staffUser: $request->user(),
                photoFile: $photoFile
            );

            return response()->json([
                'success' => true,
                'message' => 'Tiếp nhận kiện hàng bưu phẩm thành công.',
                'data' => $parcel,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Dữ liệu không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi trong quá trình tiếp nhận kiện hàng.',
            ], 500);
        }
    }

    /**
     * Xem thông tin chi tiết một kiện hàng bưu phẩm
     */
    public function show(string $id): JsonResponse
    {
        $parcel = Parcel::with([
            'apartment:id,apartment_number,block_id',
            'apartment.block:id,block_name,block_code',
            'recipientUser:id,full_name,phone_number',
            'receivedByStaff:id,full_name',
            'checkoutStaff:id,full_name',
        ])->find($id);

        if (! $parcel) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy kiện hàng bưu phẩm với ID đã cung cấp.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $parcel,
        ], 200);
    }

    /**
     * Cập nhật thông tin phiếu tiếp nhận bưu phẩm
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => 'nullable|string|max:100',
            'courier_company' => 'nullable|string|max:80',
            'recipient_name' => 'nullable|string|max:150',
            'recipient_phone' => 'nullable|string|max:25',
            'stored_location_type' => 'nullable|string|in:RECEPTION_DESK,SMART_LOCKER,SECURITY_BOOTH',
            'notes' => 'nullable|string|max:1000',
            'photo' => 'nullable|file',
        ]);

        $newPhotoFile = $request->file('photo');

        try {
            $parcel = $this->service->updateParcel(
                id: $id,
                data: $validated,
                staffUser: $request->user(),
                newPhotoFile: $newPhotoFile
            );

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin kiện hàng thành công.',
                'data' => $parcel,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Dữ liệu không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Lỗi khi cập nhật kiện hàng.',
            ], 500);
        }
    }

    /**
     * Tìm kiếm nhanh người nhận/cư dân để chọn trong form tiếp nhận
     */
    public function searchRecipients(Request $request): JsonResponse
    {
        $keyword = (string) ($request->query('search', $request->query('q', '')));
        $apartmentId = $request->query('apartment_id');
        $limit = min(20, max(1, (int) $request->query('limit', 10)));

        $recipients = $this->service->searchRecipients($keyword, $apartmentId, $limit);

        return response()->json([
            'success' => true,
            'data' => $recipients,
        ], 200);
    }

    /**
     * Tìm kiếm nhanh danh sách căn hộ
     */
    public function searchApartments(Request $request): JsonResponse
    {
        $keyword = (string) ($request->query('search', $request->query('q', '')));
        $limit = min(50, max(1, (int) $request->query('limit', 15)));

        $apartments = $this->service->searchApartments($keyword, $limit);

        return response()->json([
            'success' => true,
            'data' => $apartments,
        ], 200);
    }
}
