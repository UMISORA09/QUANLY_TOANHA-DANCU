<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRequest;
use App\Services\VehicleConflictException;
use App\Services\VehicleNotFoundException;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VehicleController extends Controller
{
    public function __construct(
        protected VehicleService $service
    ) {}

    /**
     * Kiểm tra quyền quản trị / nhân viên của người dùng hiện tại (Admin, Ban Quản Lý, Lễ Tân, An Ninh/Bảo Vệ)
     */
    protected function checkAdminAuthorization(Request $request, ?string $requiredPermission = null): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $isAuthorized = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']))
            || ($requiredPermission && method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission))
            || (method_exists($user, 'hasPermission') && ($user->hasPermission('VEHICLE:VIEW') || $user->hasPermission('VEHICLE:CREATE')));

        if (! $isAuthorized) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền quản trị hoặc nhân viên vận hành để thực hiện thao tác này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Danh sách phương tiện đăng ký (Admin/Manager)
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $vehicles = $this->service->listVehicles($request->all());

        return response()->json([
            'success' => true,
            'data' => $vehicles->items(),
            'meta' => [
                'current_page' => $vehicles->currentPage(),
                'last_page' => $vehicles->lastPage(),
                'per_page' => $vehicles->perPage(),
                'total' => $vehicles->total(),
            ],
        ]);
    }

    /**
     * Xem chi tiết thông tin phương tiện
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $vehicle = $this->service->getVehicleById($id);

            // Lấy kèm lịch sử mục phí hóa đơn liên quan đến phương tiện này
            $invoiceItems = DB::table('invoice_items')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->where('invoices.apartment_id', $vehicle->apartment_id)
                ->where('invoice_items.item_description', 'like', "%{$vehicle->license_plate}%")
                ->select([
                    'invoice_items.id as item_id',
                    'invoices.id as invoice_id',
                    'invoices.invoice_number',
                    'invoices.billing_period',
                    'invoices.status as invoice_status',
                    'invoice_items.service_code',
                    'invoice_items.item_description',
                    'invoice_items.amount_before_tax',
                    'invoice_items.vat_amount',
                    'invoice_items.total_line_amount',
                    'invoice_items.created_at',
                ])
                ->orderBy('invoices.billing_period', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'vehicle' => $vehicle,
                    'invoice_history' => $invoiceItems,
                ],
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Đăng ký phương tiện mới (Xe máy / Ô tô) và tự động đẩy phí sang Hóa Đơn
     */
    public function store(VehicleRequest $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $adminId = $request->user()?->id;

        try {
            $result = $this->service->createVehicle($request->validated(), $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký phương tiện và khởi tạo phí gửi xe vào hóa đơn thành công.',
                'data' => $result['vehicle'],
                'invoice_sync' => $result['invoice_sync'],
            ], Response::HTTP_CREATED);
        } catch (VehicleConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi đăng ký phương tiện: '.$e->getMessage(),
                'error' => 'SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cập nhật thông tin phương tiện
     */
    public function update(VehicleRequest $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $vehicle = $this->service->updateVehicle($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin phương tiện thành công.',
                'data' => $vehicle,
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (VehicleConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi cập nhật phương tiện: '.$e->getMessage(),
                'error' => 'SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Xóa mềm phương tiện (bảo toàn lịch sử hóa đơn tài chính)
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $this->service->deleteVehicle($id);

            return response()->json([
                'success' => true,
                'message' => 'Đã ngừng theo dõi và xóa phương tiện khỏi danh sách.',
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Bật / tắt trạng thái kích hoạt của phương tiện
     */
    public function toggleActive(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $vehicle = $this->service->toggleActive($id);

            $statusText = $vehicle->is_active ? 'Kích hoạt' : 'Tạm dừng';

            return response()->json([
                'success' => true,
                'message' => "{$statusText} phương tiện thành công.",
                'data' => $vehicle,
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Phê duyệt đăng ký phương tiện và đẩy phí sang hóa đơn
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $adminId = (string) ($request->user()?->id ?? '');

        try {
            $vehicle = $this->service->approveVehicle($id, $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Đã phê duyệt phương tiện và kích hoạt thu phí thành công.',
                'data' => $vehicle,
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi phê duyệt phương tiện: '.$e->getMessage(),
                'error' => 'SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Tra cứu cấu hình đơn giá gửi xe hiện hành (từ bảng service_pricing_configs)
     */
    public function pricingConfig(Request $request): JsonResponse
    {
        $category = $request->query('category');

        if (! empty($category)) {
            $pricing = $this->service->getPricingForCategory($category);

            return response()->json([
                'success' => true,
                'data' => $pricing,
            ]);
        }

        $allPricing = $this->service->getAllParkingPricingConfigs();

        return response()->json([
            'success' => true,
            'data' => $allPricing,
        ]);
    }

    /**
     * Đồng bộ phí gửi xe vào hóa đơn kỳ cụ thể (có kiểm tra chống trùng lặp)
     */
    public function syncInvoice(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $vehicle = $this->service->getVehicleById($id);
            $period = $request->input('billing_period');

            $result = $this->service->syncVehicleParkingFeeToInvoice($vehicle, $period);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Xử lý đồng bộ phí vào hóa đơn hoàn tất.',
                'data' => $result,
            ]);
        } catch (VehicleNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Danh sách căn hộ hỗ trợ bộ lọc và form đăng ký
     */
    public function apartments(Request $request): JsonResponse
    {
        $apartments = $this->service->getApartmentsForFilter();

        return response()->json([
            'success' => true,
            'data' => $apartments,
        ]);
    }

    /**
     * Danh sách cư dân theo căn hộ (phục vụ chọn chủ xe)
     */
    public function apartmentResidents(Request $request, string $apartmentId): JsonResponse
    {
        $residents = DB::table('residents')
            ->join('users', 'residents.user_id', '=', 'users.id')
            ->where('residents.apartment_id', $apartmentId)
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->select([
                'users.id as user_id',
                'users.full_name',
                'users.phone_number',
                'users.email',
                'residents.resident_type',
                'residents.is_head_of_household',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $residents,
        ]);
    }

    /**
     * Danh sách tất cả cư dân phục vụ tra cứu và chọn chủ xe linh hoạt
     */
    public function allResidents(Request $request): JsonResponse
    {
        $apartmentId = $request->query('apartment_id');

        $query = DB::table('residents')
            ->join('users', 'residents.user_id', '=', 'users.id')
            ->leftJoin('apartments', 'residents.apartment_id', '=', 'apartments.id')
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->select([
                'users.id as user_id',
                'users.full_name',
                'users.phone_number',
                'users.email',
                'residents.resident_type',
                'residents.is_head_of_household',
                'residents.apartment_id',
                'apartments.apartment_number',
            ])
            ->orderBy('apartments.apartment_number')
            ->orderBy('users.full_name');

        if (! empty($apartmentId)) {
            $query->where('residents.apartment_id', $apartmentId);
        }

        $residents = $query->get();

        return response()->json([
            'success' => true,
            'data' => $residents,
        ]);
    }
}
