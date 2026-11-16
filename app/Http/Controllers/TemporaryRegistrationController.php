<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemporaryRegistrationRequest;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Services\TemporaryRegistrationConflictException;
use App\Services\TemporaryRegistrationNotFoundException;
use App\Services\TemporaryRegistrationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TemporaryRegistrationController extends Controller
{
    public function __construct(
        protected TemporaryRegistrationService $service
    ) {}

    /**
     * Kiểm tra người dùng có quyền Quản trị viên hay không
     */
    protected function isUserAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));
    }

    /**
     * Kiểm tra người dùng có phải cư dân hợp lệ đang hoạt động hay không
     */
    protected function isUserActiveResident(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return Resident::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists()
            || (method_exists($user, 'hasRole') && $user->hasRole(['RESIDENT_OWNER', 'RESIDENT_MEMBER']));
    }

    /**
     * Kiểm tra quyền truy cập một hồ sơ (Admin hoặc Cư dân có quan hệ hợp lệ)
     */
    protected function canAccessRegistration(User $user, TemporaryRegistration $registration): bool
    {
        if ($this->isUserAdmin($user)) {
            return true;
        }

        $registration->loadMissing('resident');

        // Hồ sơ của chính user
        if ($registration->resident && (string) $registration->resident->user_id === (string) $user->id) {
            return true;
        }

        // User có quyền chủ hộ / chủ sở hữu trong căn hộ của hồ sơ này
        $userResident = Resident::where('user_id', $user->id)
            ->where('apartment_id', $registration->apartment_id)
            ->where('is_active', true)
            ->first();

        if ($userResident) {
            $isHeadOrOwner = (bool) $userResident->is_head_of_household
                || $userResident->resident_type === 'OWNER'
                || (method_exists($user, 'hasRole') && $user->hasRole('RESIDENT_OWNER'));

            if ($isHeadOrOwner) {
                return true;
            }

            if ((string) $registration->resident_id === (string) $userResident->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kiểm tra quyền chỉnh sửa hoặc xóa hồ sơ
     */
    protected function canModifyRegistration(User $user, TemporaryRegistration $registration): bool
    {
        return $this->canAccessRegistration($user, $registration);
    }

    /**
     * Kiểm tra quyền quản trị của người dùng (dành riêng cho các thao tác phê duyệt, từ chối, gửi công an)
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

        if (! $this->isUserAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền quản trị để thực hiện thao tác này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Danh sách hồ sơ tạm trú / tạm vắng
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $isAdmin = $this->isUserAdmin($user);
        $paginator = $this->service->list($request->all(), $isAdmin ? null : $user);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Xem chi tiết hồ sơ tạm trú / tạm vắng
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $registration = $this->service->getById($id);
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $this->canAccessRegistration($user, $registration)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập hồ sơ tạm trú / tạm vắng này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'success' => true,
            'data' => $registration,
        ]);
    }

    /**
     * Tạo hồ sơ đăng ký tạm trú / tạm vắng mới
     */
    public function store(TemporaryRegistrationRequest $request): JsonResponse
    {
        $registration = $this->service->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tạo hồ sơ đăng ký tạm trú / tạm vắng thành công.',
            'data' => $registration,
        ], Response::HTTP_CREATED);
    }

    /**
     * Cập nhật thông tin hồ sơ
     */
    public function update(TemporaryRegistrationRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Nếu là cư dân, kiểm tra quyền sở hữu đối với hồ sơ
        if (! $this->isUserAdmin($user)) {
            $registration = TemporaryRegistration::find($id);
            if (! $registration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            if (! $this->canModifyRegistration($user, $registration)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền cập nhật hồ sơ này.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        try {
            $updated = $this->service->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật hồ sơ tạm trú / tạm vắng thành công.',
                'data' => $updated,
            ]);
        } catch (TemporaryRegistrationConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Xóa hồ sơ tạm trú / tạm vắng khi ở trạng thái cho phép
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Nếu là cư dân, kiểm tra quyền sở hữu đối với hồ sơ
        if (! $this->isUserAdmin($user)) {
            $registration = TemporaryRegistration::find($id);
            if (! $registration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }

            if (! $this->canModifyRegistration($user, $registration)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền xóa hồ sơ này.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        try {
            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Xóa hồ sơ tạm trú / tạm vắng thành công.',
            ], Response::HTTP_OK);
        } catch (TemporaryRegistrationConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Phê duyệt hồ sơ tạm trú / tạm vắng
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $registration = $this->service->approve(
                $id,
                $request->user(),
                $request->input('notes'),
                $request->input('police_reference_code')
            );

            return response()->json([
                'success' => true,
                'message' => 'Phê duyệt hồ sơ tạm trú / tạm vắng thành công.',
                'data' => $registration,
            ]);
        } catch (TemporaryRegistrationConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Từ chối hồ sơ tạm trú / tạm vắng
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $reason = trim((string) $request->input('reason', $request->input('notes', '')));
        if ($reason === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng cung cấp lý do từ chối hồ sơ.',
                'error' => 'VALIDATION_ERROR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $registration = $this->service->reject($id, $request->user(), $reason);

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối hồ sơ tạm trú / tạm vắng.',
                'data' => $registration,
            ]);
        } catch (TemporaryRegistrationConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Nộp hồ sơ sang Công An / Cập nhật mã tham chiếu
     */
    public function submitToPolice(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $registration = $this->service->submitToPolice(
                $id,
                $request->user(),
                $request->input('police_reference_code'),
                $request->input('notes')
            );

            return response()->json([
                'success' => true,
                'message' => 'Chuyển hồ sơ sang trạng thái gửi Công An thành công.',
                'data' => $registration,
            ]);
        } catch (TemporaryRegistrationConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Tải lên ảnh CCCD mặt trước hoặc mặt sau
     */
    public function uploadCccd(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Nếu có truyền registration_id, kiểm tra quyền truy cập hồ sơ đó
        $registrationId = $request->input('registration_id');
        if ($registrationId) {
            try {
                $registration = $this->service->getById($registrationId);
                if (! $this->canAccessRegistration($user, $registration)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn không có quyền tải ảnh CCCD cho hồ sơ này.',
                        'error' => 'FORBIDDEN',
                    ], Response::HTTP_FORBIDDEN);
                }
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }
        } else {
            // Upload trong quá trình tạo mới hồ sơ: Yêu cầu là Admin hoặc Cư dân đang hoạt động
            if (! $this->isUserAdmin($user) && ! $this->isUserActiveResident($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền thực hiện thao tác này.',
                    'error' => 'FORBIDDEN',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,webp,pdf|max:5120',
            'side' => 'nullable|string|in:front,back',
        ], [
            'file.required' => 'Tệp ảnh CCCD là bắt buộc.',
            'file.mimes' => 'Chỉ chấp nhận file định dạng JPG, PNG, WEBP hoặc PDF.',
            'file.max' => 'Dung lượng file tối đa là 5MB.',
        ]);

        $url = $this->service->uploadCccd(
            $request->file('file'),
            $request->input('side', 'front')
        );

        return response()->json([
            'success' => true,
            'message' => 'Tải lên ảnh CCCD thành công.',
            'url' => $url,
        ]);
    }

    /**
     * Xuất dữ liệu biểu mẫu gửi Công An (Mẫu CT01)
     */
    public function exportForm(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $registration = $this->service->getById($id);
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $this->canAccessRegistration($user, $registration)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem biểu mẫu của hồ sơ này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $formData = $this->service->exportPoliceForm($id);

        return response()->json([
            'success' => true,
            'data' => $formData,
        ]);
    }

    /**
     * Tải trực tiếp file HTML Biểu mẫu gửi Công An để in ấn
     */
    public function downloadForm(Request $request, string $id): Response
    {
        $user = $request->user();
        if (! $user) {
            return response('Yêu cầu xác thực tài khoản.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $registration = $this->service->getById($id);
        } catch (\Throwable $e) {
            return response('Hồ sơ tạm trú / tạm vắng không tồn tại.', Response::HTTP_NOT_FOUND);
        }

        if (! $this->canAccessRegistration($user, $registration)) {
            return response('Bạn không có quyền tải biểu mẫu của hồ sơ này.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->service->exportPoliceForm($id);
        $filename = 'bieu_mau_ct01_'.$id.'.html';

        return response($formData['html_content'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
