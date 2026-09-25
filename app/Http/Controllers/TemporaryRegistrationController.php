<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemporaryRegistrationRequest;
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
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));

        if (! $isAdmin) {
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
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $paginator = $this->service->list($request->all());

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
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $registration = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'data' => $registration,
            ]);
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
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
        try {
            $registration = $this->service->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật hồ sơ tạm trú / tạm vắng thành công.',
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
     * Xóa hồ sơ tạm trú / tạm vắng khi ở trạng thái cho phép
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
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
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
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
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $formData = $this->service->exportPoliceForm($id);

            return response()->json([
                'success' => true,
                'data' => $formData,
            ]);
        } catch (TemporaryRegistrationNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Tải trực tiếp file HTML Biểu mẫu gửi Công An để in ấn
     */
    public function downloadForm(Request $request, string $id): Response
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $formData = $this->service->exportPoliceForm($id);
        $filename = 'bieu_mau_ct01_'.$id.'.html';

        return response($formData['html_content'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
