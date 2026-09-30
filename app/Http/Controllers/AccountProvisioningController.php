<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountProvisioningRequest;
use App\Services\AccountProvisioningAlreadyActiveException;
use App\Services\AccountProvisioningDuplicateException;
use App\Services\AccountProvisioningInvalidTokenException;
use App\Services\AccountProvisioningNotFoundException;
use App\Services\AccountProvisioningRateLimitException;
use App\Services\AccountProvisioningService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountProvisioningController extends Controller
{
    public function __construct(
        protected AccountProvisioningService $service
    ) {}

    /**
     * Kiểm tra quyền quản trị của người dùng
     */
    protected function checkAdminAuthorization(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $isAdmin = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'ADMIN']))
            || (method_exists($user, 'hasPermission') && $user->hasPermission('USER:CREATE'));

        if (! $isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực hiện thao tác cấp phát tài khoản.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Danh sách tài khoản đã cấp phát tự động
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'auto_provisioned_only' => $request->boolean('auto_provisioned_only', false),
        ];

        $perPage = min(max((int) $request->input('limit', 15), 5), 100);
        $paginated = $this->service->getProvisionedAccounts($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Cấp phát tài khoản tự động (Tạo user ngẫu nhiên + Gửi email kích hoạt)
     */
    public function store(AccountProvisioningRequest $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $actor = $request->user();
            $result = $this->service->provisionAccount($request->validated(), $actor);

            return response()->json([
                'success' => true,
                'message' => 'Cấp phát tài khoản tự động thành công. Email kích hoạt đã được gửi tới người dùng.',
                'data' => $result['user'],
                'activation' => $result['activation'],
            ], Response::HTTP_CREATED);
        } catch (AccountProvisioningDuplicateException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'DUPLICATE_RESOURCE',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi trong quá trình cấp phát tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gửi lại email kích hoạt cho tài khoản đang chờ kích hoạt
     */
    public function resendActivation(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $actor = $request->user();
            $result = $this->service->resendActivation($id, $actor);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng tương ứng.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (AccountProvisioningAlreadyActiveException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'ALREADY_ACTIVE',
            ], Response::HTTP_BAD_REQUEST);
        } catch (AccountProvisioningRateLimitException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'TOO_MANY_REQUESTS',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi gửi lại email kích hoạt: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Kích hoạt tài khoản từ liên kết email và thiết lập mật khẩu cá nhân
     */
    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required' => 'Email là bắt buộc.',
            'email.email' => 'Email không đúng định dạng.',
            'token.required' => 'Mã kích hoạt là bắt buộc.',
            'password.required' => 'Mật khẩu mới là bắt buộc.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.confirmed' => 'Mật khẩu xác nhận không khớp.',
        ]);

        try {
            $result = $this->service->activateAccount(
                $validated['email'],
                $validated['token'],
                $validated['password']
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (AccountProvisioningNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (AccountProvisioningInvalidTokenException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'INVALID_TOKEN',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xử lý kích hoạt tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
