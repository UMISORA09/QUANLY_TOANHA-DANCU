<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InvoiceManagementController extends Controller
{
    public function __construct(
        protected InvoiceManagementService $invoiceService
    ) {}

    /**
     * Kiểm tra xác thực và quyền hạn đối với phân hệ Hóa đơn
     */
    protected function checkAuthorization(Request $request, string $requiredPermission): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 1. Quản trị viên cấp cao có toàn quyền tối cao
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return null;
        }

        // 2. Kiểm tra quyền cụ thể hoặc quyền người đặc biệt phân hệ hóa đơn
        if (
            method_exists($user, 'hasPermission') &&
            ($user->hasPermission($requiredPermission) || $user->hasPermission('INVOICE:SPECIAL'))
        ) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => "Bạn không có quyền thực hiện thao tác này (yêu cầu quyền: {$requiredPermission}).",
            'error' => 'FORBIDDEN',
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Xác định danh sách căn hộ cư dân đang lưu trú / sở hữu
     *
     * @return array<int, string>
     */
    protected function resolveResidentApartmentIds(User $user): array
    {
        $residentAptIds = DB::table('residents')
            ->where('user_id', $user->id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->pluck('apartment_id')
            ->toArray();

        $curResAptIds = DB::table('apartments')
            ->where('current_resident_user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        return array_values(array_unique(array_filter(array_merge($residentAptIds, $curResAptIds))));
    }

    /**
     * Kiểm tra người dùng có phải nhân viên vận hành / ban quản lý không
     */
    protected function isStaffUser(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER', 'ACCOUNTANT', 'MANAGER', 'ADMIN']);
        }

        return false;
    }

    /**
     * GET /api/v1/invoices
     * Danh sách hóa đơn phân trang kèm bộ lọc đa chiều
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        $filters = $request->only([
            'status',
            'billing_period',
            'block_id',
            'issue_date_from',
            'issue_date_to',
            'search',
            'sort_by',
            'sort_order',
            'per_page',
        ]);

        // Data Scoping cho Cư dân (không phải ban quản lý / kế toán)
        if (! $this->isStaffUser($user)) {
            $userAptIds = $this->resolveResidentApartmentIds($user);
            $requestedAptId = $request->query('apartment_id');

            if ($requestedAptId && ! in_array($requestedAptId, $userAptIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }

            $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
            $query = Invoice::with([
                'apartment.block',
                'apartment.floor',
                'residentUser',
                'items',
                'batch',
            ])->where(function ($q) use ($userAptIds, $user) {
                $q->whereIn('apartment_id', $userAptIds)
                    ->orWhere('resident_user_id', $user->id);
            });

            if ($requestedAptId) {
                $query->where('apartment_id', $requestedAptId);
            }
            if (! empty($filters['billing_period'])) {
                $query->where('billing_period', $filters['billing_period']);
            }
            if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
                $query->where('status', strtoupper($filters['status']));
            }

            $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices,
            ]);
        }

        $invoices = $this->invoiceService->listInvoices($filters);

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    /**
     * GET /api/v1/invoices/summary
     * Tổng hợp tài chính KPI hóa đơn theo điều kiện lọc
     */
    public function summary(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem thống kê tài chính toàn tòa nhà.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->only([
            'status',
            'billing_period',
            'block_id',
            'issue_date_from',
            'issue_date_to',
            'search',
        ]);

        $summary = $this->invoiceService->getSummary($filters);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * GET /api/v1/invoices/current-month
     * Lấy hóa đơn tháng hiện tại của căn hộ hoặc cư dân (Chức năng 9)
     */
    public function currentMonth(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        $apartmentId = $request->query('apartment_id');
        $billingPeriod = $request->query('billing_period');

        // Data Scoping cho Cư dân: Không được phép xem căn hộ khác
        if (! $this->isStaffUser($user)) {
            $userAptIds = $this->resolveResidentApartmentIds($user);
            if ($apartmentId && ! in_array($apartmentId, $userAptIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
            if (! $apartmentId) {
                $apartmentId = $userAptIds[0] ?? null;
            }
        }

        $userId = $user?->id ? (string) $user->id : null;
        $invoice = $this->invoiceService->getCurrentMonthInvoice($userId, $apartmentId, $billingPeriod);

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn cho kỳ phí hiện tại.',
                'data' => null,
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lấy chi tiết hóa đơn tháng hiện tại thành công.',
            'data' => $invoice,
        ]);
    }

    /**
     * GET /api/v1/invoices/{id}
     * Chi tiết một hóa đơn và các dòng tính phí dịch vụ
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $invoice = $this->invoiceService->getInvoiceDetail($id);
        $user = $request->user();

        // Data Scoping: Cư dân chỉ được xem hóa đơn căn hộ của mình
        if (! $this->isStaffUser($user)) {
            $userAptIds = $this->resolveResidentApartmentIds($user);
            if (! in_array($invoice->apartment_id, $userAptIds, true) && (string) $invoice->resident_user_id !== (string) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * GET /api/v1/invoices/{id}/statement
     * Xuất sao kê hóa đơn chi tiết phục vụ in ấn & hiển thị
     */
    public function statement(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $invoice = $this->invoiceService->getInvoiceDetail($id);
        $user = $request->user();

        // Data Scoping: Cư dân chỉ được xem sao kê căn hộ của mình
        if (! $this->isStaffUser($user)) {
            $userAptIds = $this->resolveResidentApartmentIds($user);
            if (! in_array($invoice->apartment_id, $userAptIds, true) && (string) $invoice->resident_user_id !== (string) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền truy cập hóa đơn của căn hộ khác.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $statement = $this->invoiceService->getDetailedStatement($id);

        return response()->json([
            'success' => true,
            'data' => $statement,
        ]);
    }

    /**
     * POST /api/v1/invoices/{id}/cancel
     * Hủy một hóa đơn chưa thanh toán
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:DELETE')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền hủy hóa đơn.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $invoice = $this->invoiceService->cancelInvoice($id, $validated['reason'] ?? null);

            return response()->json([
                'success' => true,
                'message' => "Đã hủy thành công hóa đơn {$invoice->invoice_number}.",
                'data' => $invoice,
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * POST /api/v1/invoices/bulk-cancel
     * Hủy hàng loạt hóa đơn chưa thanh toán
     */
    public function bulkCancel(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:DELETE')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền hủy hóa đơn hàng loạt.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'invoice_ids.required' => 'Vui lòng chọn ít nhất một hóa đơn cần hủy.',
            'invoice_ids.min' => 'Vui lòng chọn ít nhất một hóa đơn cần hủy.',
        ]);

        $result = $this->invoiceService->bulkCancelInvoices(
            $validated['invoice_ids'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Đã hủy thành công {$result['cancelled_count']} hóa đơn.",
            'data' => $result,
        ]);
    }
}
