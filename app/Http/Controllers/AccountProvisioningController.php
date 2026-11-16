<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountProvisioningRequest;
use App\Models\User;
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
            'id' => ['nullable', 'string'],
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
                $validated['password'],
                $validated['id'] ?? null
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
        } catch (AccountProvisioningAlreadyActiveException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'ALREADY_ACTIVE',
            ], Response::HTTP_BAD_REQUEST);
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

    /**
     * Kiểm tra trạng thái liên kết kích hoạt (phục vụ hiển thị UI xác nhận lần 2)
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        $userId = $request->query('id');

        if (! $email && ! $userId) {
            return response()->json([
                'success' => false,
                'status' => 'INVALID_PARAMS',
                'message' => 'Thiếu thông tin nhận diện tài khoản.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $query = User::query();
        if ($userId) {
            $query->where('id', $userId);
        }
        if ($email) {
            $query->where('email', $email);
        }
        $user = $query->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'status' => 'NOT_FOUND',
                'message' => 'Không tìm thấy tài khoản người dùng tương ứng.',
            ], Response::HTTP_NOT_FOUND);
        }

        $isActivated = ($user->status === 'ACTIVE');

        return response()->json([
            'success' => true,
            'status' => $isActivated ? 'ALREADY_ACTIVE' : 'PENDING_ACTIVATION',
            'is_activated' => $isActivated,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'message' => $isActivated
                ? 'Đã kích hoạt tài khoản bạn vui lòng đăng nhập.'
                : 'Tài khoản đang chờ thiết lập mật khẩu.',
        ], Response::HTTP_OK);
    }

    /**
     * Xem chi tiết tài khoản
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $user = User::with('roles:id,role_code,role_name')->find($id);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $user,
        ], Response::HTTP_OK);
    }

    /**
     * Cập nhật thông tin tài khoản
     */
    public function update(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'national_id_number' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'in:MALE,FEMALE,OTHER'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
        ]);

        try {
            $user = $this->service->updateAccount($id, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin tài khoản thành công.',
                'data' => $user,
            ], Response::HTTP_OK);
        } catch (AccountProvisioningDuplicateException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'DUPLICATE_RESOURCE',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi cập nhật tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Xóa tài khoản
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $this->service->deleteAccount($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa tài khoản thành công.',
            ], Response::HTTP_OK);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi xóa tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Khóa hoặc mở khóa tài khoản
     */
    public function toggleLock(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $user = $this->service->toggleLockAccount($id, $request->user());

            $isLocked = ($user->status === 'LOCKED');

            return response()->json([
                'success' => true,
                'message' => $isLocked ? 'Đã khóa tài khoản thành công.' : 'Đã mở khóa tài khoản thành công.',
                'data' => $user,
                'is_locked' => $isLocked,
            ], Response::HTTP_OK);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài khoản người dùng.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi thay đổi trạng thái tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gửi lại email kích hoạt hàng loạt
     */
    public function batchResend(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $result = $this->service->batchResendActivation($request->user());

            return response()->json([
                'success' => true,
                'message' => "Đã gửi lại thành công {$result['sent_count']} email kích hoạt (bỏ qua {$result['skipped_count']} tài khoản đang trong thời gian chờ).",
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi gửi lại email hàng loạt: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Nhập danh sách tài khoản hàng loạt (Import)
     */
    public function import(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.full_name' => ['required', 'string'],
            'records.*.email' => ['required', 'string', 'email'],
            'records.*.phone_number' => ['required', 'string'],
            'records.*.national_id_number' => ['nullable', 'string'],
            'records.*.gender' => ['nullable', 'string'],
            'records.*.roles' => ['nullable'],
        ]);

        try {
            $result = $this->service->importAccounts($validated['records'], $request->user());

            return response()->json([
                'success' => true,
                'message' => "Nhập danh sách thành công {$result['success_count']}/{$result['total']} tài khoản.",
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi nhập dữ liệu tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
