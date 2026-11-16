<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRequest;
use App\Models\User;
use App\Services\VehicleConflictException;
use App\Services\VehicleNotFoundException;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VehicleController extends Controller
{
    public function __construct(
        protected VehicleService $service
    ) {}

    /**
     * Kiểm tra xác thực, trạng thái hoạt động và quyền thao tác theo hành động đối với phân hệ Phương tiện.
     */
    protected function checkAdminAuthorization(Request $request, ?string $requiredPermission = null): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (strtoupper((string) ($user->status ?? '')) !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản không ở trạng thái hoạt động hoặc đã bị khóa.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        if (empty($requiredPermission) || ! $user->hasPermission($requiredPermission)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực hiện thao tác này.',
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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:VIEW')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:VIEW')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:CREATE')) {
            return $authError;
        }

        $adminId = $request->user()?->id;
        $payload = Arr::except($request->validated(), ['monthly_parking_fee']);

        try {
            $result = $this->service->createVehicle($payload, $adminId, false);

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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:UPDATE')) {
            return $authError;
        }

        $payload = Arr::except($request->validated(), ['monthly_parking_fee']);

        try {
            $vehicle = $this->service->updateVehicle($id, $payload, false);

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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:DELETE')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:UPDATE')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:APPROVE')) {
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
        } catch (VehicleConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi phê duyệt phương tiện: '.$e->getMessage(),
                'error' => 'SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Từ chối đăng ký phương tiện kèm lý do
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:APPROVE')) {
            return $authError;
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ], [
            'reason.required' => 'Lý do từ chối đăng ký là bắt buộc.',
            'reason.string' => 'Lý do từ chối phải là chuỗi văn bản.',
            'reason.min' => 'Lý do từ chối phải có ít nhất 3 ký tự.',
            'reason.max' => 'Lý do từ chối không được vượt quá 1000 ký tự.',
        ]);

        $reason = trim((string) $validated['reason']);
        if ($reason === '') {
            return response()->json([
                'success' => false,
                'message' => 'Lý do từ chối không được để trống hoặc chỉ chứa khoảng trắng.',
                'error' => 'VALIDATION_ERROR',
                'errors' => [
                    'reason' => ['Lý do từ chối không được để trống hoặc chỉ chứa khoảng trắng.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $adminId = (string) ($request->user()?->id ?? '');

        try {
            $vehicle = $this->service->rejectVehicle($id, $reason, $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối đăng ký phương tiện thành công.',
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
                'message' => 'Có lỗi xảy ra khi từ chối phương tiện: '.$e->getMessage(),
                'error' => 'SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Tra cứu cấu hình đơn giá gửi xe hiện hành (từ bảng service_pricing_configs)
     */
    public function pricingConfig(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:VIEW')) {
            return $authError;
        }

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
        if ($authError = $this->checkAdminAuthorization($request, 'VEHICLE:APPROVE')) {
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
     * Trả về phản hồi JSON lỗi 403 Forbidden chuẩn hóa cho endpoint metadata (không rò rỉ dữ liệu).
     */
    protected function metadataForbiddenResponse(string $message = 'Bạn không có quyền truy cập phạm vi dữ liệu phương tiện này.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'FORBIDDEN',
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Kiểm tra quyền xem metadata phương tiện ở backend trước khi truy vấn dữ liệu căn hộ/cư dân.
     */
    protected function authorizeMetadataAccess(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (strtoupper((string) ($user->status ?? '')) !== 'ACTIVE') {
            return $this->metadataForbiddenResponse('Tài khoản không ở trạng thái hoạt động hoặc đã bị khóa.');
        }

        if (! $user->isSuperAdmin() && ! $user->hasPermission('VEHICLE:VIEW')) {
            return $this->metadataForbiddenResponse('Bạn không có quyền xem dữ liệu phương tiện.');
        }

        return null;
    }

    /**
     * Xác định danh sách block_id được phép truy cập của người dùng hiện tại (null = toàn khu).
     *
     * @return array<int, string>|null
     */
    protected function resolveAuthorizedMetadataBlockIds(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $profile = DB::table('staff_profiles')
            ->where('user_id', $user->id)
            ->select(['assigned_block_id'])
            ->first();

        if ($profile !== null) {
            return $profile->assigned_block_id !== null
                ? [(string) $profile->assigned_block_id]
                : null;
        }

        $managedBlockIds = DB::table('blocks')
            ->whereNull('deleted_at')
            ->where('building_manager_user_id', $user->id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $managedBlockIds !== [] ? $managedBlockIds : null;
    }

    /**
     * Kiểm tra các tham số truy vấn metadata phải là giá trị vô hướng để tránh lỗi 500 hoặc bypass filter.
     *
     * @param  array<int, string>  $keys
     */
    protected function validateScalarMetadataParams(Request $request, array $keys): ?JsonResponse
    {
        foreach ($keys as $key) {
            $value = $request->query($key);
            if ($value !== null && ! is_scalar($value)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tham số lọc dữ liệu không hợp lệ.',
                    'error' => 'VALIDATION_ERROR',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return null;
    }

    /**
     * Kiểm tra tham số building_id / block_id từ client và đối chiếu với phạm vi được cấp quyền.
     *
     * @param  array<int, string>|null  $allowedBlockIds
     * @return array{0: ?string, 1: ?JsonResponse}
     */
    protected function resolveScopedBlockFilter(Request $request, ?array $allowedBlockIds): array
    {
        $buildingId = trim((string) ($request->query('building_id') ?? ''));
        $blockId = trim((string) ($request->query('block_id') ?? ''));

        if ($buildingId !== '' && $blockId !== '' && $buildingId !== $blockId) {
            if ($allowedBlockIds !== null) {
                return [null, $this->metadataForbiddenResponse()];
            }

            return [null, response()->json([
                'success' => false,
                'message' => 'Tham số lọc tòa nhà không hợp lệ.',
                'error' => 'VALIDATION_ERROR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }

        $requestedBlockId = $buildingId !== '' ? $buildingId : $blockId;

        if ($requestedBlockId === '') {
            return [null, null];
        }

        if (! Str::isUuid($requestedBlockId)) {
            if ($allowedBlockIds !== null) {
                return [null, $this->metadataForbiddenResponse()];
            }

            return [null, response()->json([
                'success' => false,
                'message' => 'Mã tòa nhà không hợp lệ.',
                'error' => 'VALIDATION_ERROR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }

        if ($allowedBlockIds !== null && ! in_array($requestedBlockId, $allowedBlockIds, true)) {
            return [null, $this->metadataForbiddenResponse('Bạn không có quyền truy cập dữ liệu của tòa nhà này.')];
        }

        return [$requestedBlockId, null];
    }

    /**
     * Kiểm tra căn hộ tồn tại và nằm trong phạm vi block được phép truy cập trước khi truy vấn cư dân.
     *
     * @param  array<int, string>|null  $allowedBlockIds
     */
    protected function authorizeApartmentScope(string $apartmentId, ?array $allowedBlockIds, ?string $requestedBlockId = null): ?JsonResponse
    {
        if (! Str::isUuid($apartmentId)) {
            if ($allowedBlockIds !== null) {
                return $this->metadataForbiddenResponse('Bạn không có quyền truy cập căn hộ này.');
            }

            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy căn hộ.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        $apartment = DB::table('apartments')
            ->join('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('apartments.id', $apartmentId)
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at')
            ->select(['apartments.id', 'apartments.block_id'])
            ->first();

        if ($apartment === null) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy căn hộ.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        $apartmentBlockId = (string) $apartment->block_id;

        if ($allowedBlockIds !== null && ! in_array($apartmentBlockId, $allowedBlockIds, true)) {
            return $this->metadataForbiddenResponse('Bạn không có quyền truy cập cư dân của căn hộ ngoài phạm vi.');
        }

        if ($requestedBlockId !== null && $apartmentBlockId !== $requestedBlockId) {
            return $this->metadataForbiddenResponse('Căn hộ không thuộc phạm vi tòa nhà được chọn.');
        }

        return null;
    }

    /**
     * Danh sách căn hộ hỗ trợ bộ lọc và form đăng ký
     */
    public function apartments(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeMetadataAccess($request)) {
            return $authError;
        }

        /** @var User $user */
        $user = $request->user();
        $allowedBlockIds = $this->resolveAuthorizedMetadataBlockIds($user);

        if (($paramError = $this->validateScalarMetadataParams($request, ['building_id', 'block_id', 'apartment_id'])) !== null) {
            return $paramError;
        }

        [$requestedBlockId, $blockError] = $this->resolveScopedBlockFilter($request, $allowedBlockIds);
        if ($blockError !== null) {
            return $blockError;
        }

        $requestedApartmentId = trim((string) ($request->query('apartment_id') ?? ''));
        if ($requestedApartmentId !== '') {
            if ($aptError = $this->authorizeApartmentScope($requestedApartmentId, $allowedBlockIds, $requestedBlockId)) {
                return $aptError;
            }
        }

        $query = DB::table('apartments')
            ->join('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at')
            ->select([
                'apartments.id',
                'apartments.apartment_number',
            ]);

        if ($allowedBlockIds !== null) {
            $query->whereIn('apartments.block_id', $allowedBlockIds);
        }

        if ($requestedBlockId !== null) {
            $query->where('apartments.block_id', $requestedBlockId);
        }

        if ($requestedApartmentId !== '') {
            $query->where('apartments.id', $requestedApartmentId);
        }

        $apartments = $query
            ->orderBy('apartments.apartment_number')
            ->get();

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
        if ($authError = $this->authorizeMetadataAccess($request)) {
            return $authError;
        }

        /** @var User $user */
        $user = $request->user();
        $allowedBlockIds = $this->resolveAuthorizedMetadataBlockIds($user);

        if (($paramError = $this->validateScalarMetadataParams($request, ['building_id', 'block_id', 'apartment_id'])) !== null) {
            return $paramError;
        }

        [$requestedBlockId, $blockError] = $this->resolveScopedBlockFilter($request, $allowedBlockIds);
        if ($blockError !== null) {
            return $blockError;
        }

        $queryApartmentId = trim((string) ($request->query('apartment_id') ?? ''));
        if ($queryApartmentId !== '' && $queryApartmentId !== $apartmentId) {
            return $this->metadataForbiddenResponse('Không được phép ghi đè phạm vi căn hộ bằng tham số truy vấn.');
        }

        if ($aptError = $this->authorizeApartmentScope($apartmentId, $allowedBlockIds, $requestedBlockId)) {
            return $aptError;
        }

        $query = DB::table('residents')
            ->join('users', 'residents.user_id', '=', 'users.id')
            ->join('apartments', 'residents.apartment_id', '=', 'apartments.id')
            ->join('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('residents.apartment_id', $apartmentId)
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->where('users.status', 'ACTIVE')
            ->whereNull('users.deleted_at')
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at');

        if ($allowedBlockIds !== null) {
            $query->whereIn('apartments.block_id', $allowedBlockIds);
        }

        $residents = $query
            ->select([
                'users.id as user_id',
                'users.full_name',
                'users.phone_number',
                'residents.resident_type',
                'residents.is_head_of_household',
            ])
            ->orderBy('residents.is_head_of_household', 'desc')
            ->orderBy('users.full_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $residents,
        ]);
    }

    /**
     * Danh sách cư dân trong phạm vi được cấp quyền phục vụ tra cứu và chọn chủ xe
     */
    public function allResidents(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeMetadataAccess($request)) {
            return $authError;
        }

        /** @var User $user */
        $user = $request->user();
        $allowedBlockIds = $this->resolveAuthorizedMetadataBlockIds($user);

        if (($paramError = $this->validateScalarMetadataParams($request, ['apartment_id', 'building_id', 'block_id', 'search', 'limit'])) !== null) {
            return $paramError;
        }

        [$requestedBlockId, $blockError] = $this->resolveScopedBlockFilter($request, $allowedBlockIds);
        if ($blockError !== null) {
            return $blockError;
        }

        $apartmentId = trim((string) ($request->query('apartment_id') ?? ''));
        if ($apartmentId !== '') {
            if ($aptError = $this->authorizeApartmentScope($apartmentId, $allowedBlockIds, $requestedBlockId)) {
                return $aptError;
            }
        }

        $rawSearch = trim((string) $request->query('search', ''));
        $search = $rawSearch !== '' ? mb_substr($rawSearch, 0, 100) : '';
        $rawLimit = $request->query('limit', 20);
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), 50) : 20;

        $query = DB::table('residents')
            ->join('users', 'residents.user_id', '=', 'users.id')
            ->join('apartments', 'residents.apartment_id', '=', 'apartments.id')
            ->join('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->where('users.status', 'ACTIVE')
            ->whereNull('users.deleted_at')
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at')
            ->select([
                'users.id as user_id',
                'users.full_name',
                'users.phone_number',
                'residents.resident_type',
                'residents.is_head_of_household',
                'residents.apartment_id',
                'apartments.apartment_number',
            ]);

        if ($allowedBlockIds !== null) {
            $query->whereIn('apartments.block_id', $allowedBlockIds);
        }

        if ($requestedBlockId !== null) {
            $query->where('apartments.block_id', $requestedBlockId);
        }

        if ($apartmentId !== '') {
            $query->where('residents.apartment_id', $apartmentId);
        }

        if ($search !== '') {
            $escapedSearch = addcslashes($search, '\\%_');
            $likePattern = "%{$escapedSearch}%";
            $query->where(function ($q) use ($likePattern): void {
                $q->where('users.full_name', 'like', $likePattern)
                    ->orWhere('users.phone_number', 'like', $likePattern)
                    ->orWhere('apartments.apartment_number', 'like', $likePattern);
            });
        }

        $residents = $query
            ->orderBy('apartments.apartment_number')
            ->orderBy('users.full_name')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $residents,
        ]);
    }
}
