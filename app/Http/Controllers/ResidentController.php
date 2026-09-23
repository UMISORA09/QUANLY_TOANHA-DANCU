<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResidentRequest;
use App\Services\ResidentConflictException;
use App\Services\ResidentNotFoundException;
use App\Services\ResidentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResidentController extends Controller
{
    public function __construct(
        protected ResidentService $residentService
    ) {}

    /**
     * Kiểm tra quyền quản trị của người dùng hiện tại
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
     * Danh sách nhân khẩu / cư dân căn hộ (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $residents = $this->residentService->list($request->all());

        return response()->json([
            'success' => true,
            'data' => $residents->items(),
            'meta' => [
                'current_page' => $residents->currentPage(),
                'last_page' => $residents->lastPage(),
                'per_page' => $residents->perPage(),
                'total' => $residents->total(),
            ],
        ]);
    }

    /**
     * Xem chi tiết thông tin nhân khẩu và danh sách thành viên cùng căn hộ
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $detail = $this->residentService->getById($id);

            return response()->json([
                'success' => true,
                'data' => $detail,
            ]);
        } catch (ResidentNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cư dân không tồn tại trong hệ thống.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Thêm cư dân / nhân khẩu mới vào căn hộ
     */
    public function store(ResidentRequest $request): JsonResponse
    {
        $resident = $this->residentService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Thêm cư dân vào căn hộ thành công.',
            'data' => $resident,
        ], Response::HTTP_CREATED);
    }

    /**
     * Cập nhật thông tin nhân khẩu căn hộ
     */
    public function update(ResidentRequest $request, string $id): JsonResponse
    {
        try {
            $resident = $this->residentService->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin cư dân thành công.',
                'data' => $resident,
            ]);
        } catch (ResidentConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (ResidentNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Xóa mềm nhân khẩu khỏi căn hộ
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        try {
            $this->residentService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa cư dân khỏi căn hộ thành công.',
            ]);
        } catch (ResidentConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], $e->getStatusCode());
        } catch (ResidentNotFoundException|ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cư dân đã được Admin khác xóa hoặc không còn tồn tại.',
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Lấy danh sách căn hộ phục vụ bộ lọc tìm kiếm
     */
    public function apartments(Request $request): JsonResponse
    {
        if ($authError = $this->checkAdminAuthorization($request)) {
            return $authError;
        }

        $apartments = $this->residentService->getApartmentsForFilter();

        return response()->json([
            'success' => true,
            'data' => $apartments,
        ]);
    }
}
