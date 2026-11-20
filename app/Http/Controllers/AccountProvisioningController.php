<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountProvisioningRequest;
use App\Models\User;
use App\Services\AccountProvisioningAlreadyActiveException;
use App\Services\AccountProvisioningDuplicateException;
use App\Services\AccountProvisioningForbiddenException;
use App\Services\AccountProvisioningInvalidTokenException;
use App\Services\AccountProvisioningNotFoundException;
use App\Services\AccountProvisioningRateLimitException;
use App\Services\AccountProvisioningService;
use App\Services\AccountProvisioningValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AccountProvisioningController extends Controller
{
    public function __construct(
        protected AccountProvisioningService $service
    ) {}

    /**
     * Trả về phản hồi JSON lỗi 403 Forbidden chuẩn hóa.
     */
    protected function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'FORBIDDEN',
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Trả về phản hồi JSON lỗi 422 Validation Error chuẩn hóa.
     */
    protected function validationErrorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'VALIDATION_ERROR',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Kiểm tra quyền quản trị và quyền thao tác theo hành động của người dùng.
     *
     * @param  string|array<int, string>  $requiredPermissions
     */
    protected function checkAdminAuthorization(Request $request, array|string $requiredPermissions = ['USER:CREATE']): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->service->isUserRecordActive($user)) {
            return $this->forbiddenResponse('Tài khoản người dùng đã bị khóa hoặc tạm ngưng hoạt động.');
        }

        if (! $this->service->canAccessProvisioningAction($user, $requiredPermissions)) {
            return $this->forbiddenResponse('Bạn không có quyền thực hiện thao tác cấp phát tài khoản.');
        }

        return null;
    }

    /**
     * Danh sách tài khoản đã cấp phát tự động
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request, ['USER:VIEW'])) {
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:CREATE'])) {
            return $authError;
        }

        try {
            $actor = $request->user();
            $this->service->assertAuthorizedForPayload($actor, $request->all(), null);
            $result = $this->service->provisionAccount($request->validated(), $actor);

            return response()->json([
                'success' => true,
                'message' => 'Cấp phát tài khoản tự động thành công. Email kích hoạt đã được gửi tới người dùng.',
                'data' => $result['user'],
                'activation' => $result['activation'],
            ], Response::HTTP_CREATED);
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (AccountProvisioningValidationException $e) {
            return $this->validationErrorResponse($e->getMessage());
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:CREATE', 'USER:UPDATE'])) {
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
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
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
        try {
            $this->service->assertNoSpoofedPrivilegeFields($request->all(), true);
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        }

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
     * Kiểm tra trạng thái liên kết kích hoạt (phục vụ hiển thị UI xác nhận lần 2, không làm lộ PII/account enumeration).
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $rateLimitKey = 'account-provisioning-status:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            $retryAfter = max(1, RateLimiter::availableIn($rateLimitKey));

            return response()->json([
                'success' => false,
                'status' => 'TOO_MANY_REQUESTS',
                'message' => 'Quá nhiều yêu cầu kiểm tra trạng thái. Vui lòng thử lại sau.',
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $email = strtolower(trim((string) $request->query('email', '')));
        $userId = trim((string) $request->query('id', ''));
        $token = trim((string) $request->query('token', ''));

        if ($email === '' && $userId === '') {
            return response()->json([
                'success' => false,
                'status' => 'INVALID_PARAMS',
                'message' => 'Thiếu thông tin nhận diện tài khoản.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $neutralPayload = [
            'success' => true,
            'status' => 'PENDING_ACTIVATION',
            'is_activated' => false,
            'message' => 'Tài khoản đang chờ thiết lập mật khẩu.',
        ];

        if ($email === '') {
            return response()->json($neutralPayload, Response::HTTP_OK);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json($neutralPayload, Response::HTTP_OK);
        }

        $hasMatchingId = $userId !== '' && hash_equals((string) $user->id, $userId);
        $hasValidToken = $token !== '' && $this->service->hasValidActivationToken($email, $token);

        if (! $hasMatchingId && ! $hasValidToken) {
            return response()->json($neutralPayload, Response::HTTP_OK);
        }

        $isActivated = ($user->status === 'ACTIVE');

        return response()->json([
            'success' => true,
            'status' => $isActivated ? 'ALREADY_ACTIVE' : 'PENDING_ACTIVATION',
            'is_activated' => $isActivated,
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:VIEW'])) {
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:UPDATE'])) {
            return $authError;
        }

        try {
            $actor = $request->user();
            $targetUser = User::find($id);

            if ($targetUser && $this->service->userIsSuperAdmin($targetUser) && ! $this->service->isActiveSuperAdmin($actor)) {
                return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền chỉnh sửa tài khoản SUPER_ADMIN.');
            }

            $this->service->assertAuthorizedForPayload($actor, $request->all(), $targetUser);
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
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
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (AccountProvisioningValidationException $e) {
            return $this->validationErrorResponse($e->getMessage());
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:DELETE'])) {
            return $authError;
        }

        try {
            $this->service->deleteAccount($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa tài khoản thành công.',
            ], Response::HTTP_OK);
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (AccountProvisioningValidationException $e) {
            return $this->validationErrorResponse($e->getMessage());
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:UPDATE'])) {
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
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (AccountProvisioningValidationException $e) {
            return $this->validationErrorResponse($e->getMessage());
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:CREATE', 'USER:UPDATE'])) {
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
        if ($authError = $this->checkAdminAuthorization($request, ['USER:CREATE'])) {
            return $authError;
        }

        try {
            $actor = $request->user();
            $this->service->assertNoSpoofedPrivilegeFields($request->all());

            $rawRecords = $request->input('records');
            if (is_array($rawRecords)) {
                foreach ($rawRecords as $row) {
                    if (is_array($row)) {
                        $this->service->assertAuthorizedForPayload($actor, $row, null);
                    }
                }
            }
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
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
        } catch (AccountProvisioningForbiddenException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (AccountProvisioningValidationException $e) {
            return $this->validationErrorResponse($e->getMessage());
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi nhập dữ liệu tài khoản: '.$e->getMessage(),
                'error' => 'INTERNAL_SERVER_ERROR',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
