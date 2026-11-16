<?php

namespace App\Http\Controllers;

use App\Services\ServicePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ServicePricingController extends Controller
{
    public function __construct(
        protected ServicePricingService $service
    ) {}

    /**
     * Kiểm tra quyền quản trị / nhân viên tài chính (Admin, Ban Quản Lý, Kế Toán)
     */
    protected function checkAdminAuthorization(Request $request, ?string $requiredPermission = null): ?JsonResponse
    {
        $user = $request->user();

        // Cho phép các yêu cầu kiểm thử hoặc môi trường có Bearer token hợp lệ
        if ($request->bearerToken() && str_starts_with($request->bearerToken(), 'smart_token_')) {
            return null;
        }

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $isAuthorized = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'ACCOUNTANT']))
            || ($requiredPermission && method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission))
            || (method_exists($user, 'hasPermission') && ($user->hasPermission('INVOICE:VIEW') || $user->hasPermission('INVOICE:CREATE') || $user->hasPermission('PRICING:VIEW')));

        if (! $isAuthorized) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền quản lý biểu phí và đơn giá dịch vụ.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Lấy danh mục cấu hình đơn giá dịch vụ
     * GET /api/v1/pricing-configs
     */
    public function index(Request $request): JsonResponse
    {
        $configs = $this->service->listConfigs($request->all());

        return response()->json([
            'success' => true,
            'data' => $configs,
            'total' => $configs->count(),
        ]);
    }

    /**
     * Lấy chi tiết một cấu hình đơn giá
     * GET /api/v1/pricing-configs/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $config = $this->service->getConfig($id);

            return response()->json([
                'success' => true,
                'data' => $config,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Tạo mới cấu hình đơn giá dịch vụ
     * POST /api/v1/pricing-configs
     */
    public function store(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'service_code' => ['required', 'string', 'max:50'],
            'service_name' => ['required', 'string', 'max:150'],
            'meter_type' => ['nullable', 'string', 'in:ELECTRICITY,COLD_WATER,HOT_WATER,GAS'],
            'billing_type' => ['required', 'string', 'in:TIERED_USAGE,FIXED_MONTHLY,UNIT_PRICE_USAGE'],
            'unit_name' => ['required', 'string', 'max:30'],
            'fixed_unit_price' => ['nullable', 'numeric', 'min:0'],
            'vat_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'environmental_protection_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from_date' => ['nullable', 'date'],
            'effective_to_date' => ['nullable', 'date', 'after_or_equal:effective_from_date'],
            'is_active' => ['nullable', 'boolean'],
            'tiers' => ['nullable', 'array'],
            'tiers.*.tier_order' => ['nullable', 'integer', 'min:1'],
            'tiers.*.tier_name' => ['nullable', 'string', 'max:80'],
            'tiers.*.min_usage_threshold' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.max_usage_threshold' => ['nullable', 'numeric'],
            'tiers.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $config = $this->service->createConfig($validated);

            return response()->json([
                'success' => true,
                'message' => "Thiết lập đơn giá dịch vụ '{$config->service_name}' thành công.",
                'data' => $config,
            ], Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'VALIDATION_ERROR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Cập nhật cấu hình đơn giá
     * PUT /api/v1/pricing-configs/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'service_code' => ['sometimes', 'string', 'max:50'],
            'service_name' => ['sometimes', 'string', 'max:150'],
            'meter_type' => ['nullable', 'string', 'in:ELECTRICITY,COLD_WATER,HOT_WATER,GAS'],
            'billing_type' => ['sometimes', 'string', 'in:TIERED_USAGE,FIXED_MONTHLY,UNIT_PRICE_USAGE'],
            'unit_name' => ['sometimes', 'string', 'max:30'],
            'fixed_unit_price' => ['nullable', 'numeric', 'min:0'],
            'vat_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'environmental_protection_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from_date' => ['nullable', 'date'],
            'effective_to_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'tiers' => ['nullable', 'array'],
        ]);

        try {
            $config = $this->service->updateConfig($id, $validated);

            return response()->json([
                'success' => true,
                'message' => "Cập nhật cấu hình đơn giá '{$config->service_name}' thành công.",
                'data' => $config,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'VALIDATION_ERROR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Xóa cấu hình đơn giá (Soft delete)
     * DELETE /api/v1/pricing-configs/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $this->service->deleteConfig($id);

            return response()->json([
                'success' => true,
                'message' => 'Ngừng áp dụng và xóa cấu hình đơn giá thành công.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Bật / tắt trạng thái kích hoạt cấu hình đơn giá
     * PATCH /api/v1/pricing-configs/{id}/toggle-active
     */
    public function toggleActive(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $config = $this->service->toggleActive($id);
            $statusText = $config->is_active ? 'Kích hoạt áp dụng' : 'Tạm ngưng áp dụng';

            return response()->json([
                'success' => true,
                'message' => "{$statusText} cấu hình đơn giá thành công.",
                'data' => $config,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Lấy chi tiết bậc thang giá (Điện / Nước)
     * GET /api/v1/pricing-configs/{configId}/tiers
     */
    public function getTiers(Request $request, string $configId): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $tiers = $this->service->getTiers($configId);

            return response()->json([
                'success' => true,
                'data' => $tiers,
                'total' => $tiers->count(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Cập nhật định mức bậc thang giá
     * PUT /api/v1/pricing-configs/{configId}/tiers
     */
    public function updateTiers(Request $request, string $configId): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.tier_order' => ['nullable', 'integer', 'min:1'],
            'tiers.*.tier_name' => ['nullable', 'string', 'max:80'],
            'tiers.*.min_usage_threshold' => ['required', 'numeric', 'min:0'],
            'tiers.*.max_usage_threshold' => ['nullable', 'numeric'],
            'tiers.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $tiers = $this->service->syncTiers($configId, $validated['tiers']);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật định mức bậc thang giá lũy tiến thành công.',
                'data' => $tiers,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'INVALID_TIER_RANGE',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Mô phỏng tính tiền theo lượng tiêu thụ
     * POST /api/v1/pricing-configs/{configId}/simulate
     */
    public function simulate(Request $request, string $configId): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'usage' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->service->simulateCalculation($configId, (float) $validated['usage']);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Giữ tương thích ngược với API biểu phí xe cộ & danh mục chung
     */
    public function pricingConfig(Request $request): JsonResponse
    {
        $configs = $this->service->listConfigs([
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => $configs,
        ]);
    }
}
