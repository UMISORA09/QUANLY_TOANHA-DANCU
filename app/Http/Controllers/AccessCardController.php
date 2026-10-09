<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccessCardRequest;
use App\Services\AccessCardConflictException;
use App\Services\AccessCardNotFoundException;
use App\Services\AccessCardService;
use App\Services\ModuleCooldownException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccessCardController extends Controller
{
    public function __construct(
        protected AccessCardService $service
    ) {}

    /**
     * Xác thực quyền quản trị / nhân viên (Admin, BQL, Lễ Tân, Bảo Vệ)
     */
    protected function checkAuthorization(Request $request): ?JsonResponse
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
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD']));

        if (! $isAuthorized) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền quản lý thẻ RFID để thực hiện thao tác này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Danh sách thẻ RFID có phân trang và tìm kiếm
     */
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        $cards = $this->service->listCards($request->all());

        return response()->json([
            'success' => true,
            'data' => $cards->items(),
            'meta' => [
                'current_page' => $cards->currentPage(),
                'last_page' => $cards->lastPage(),
                'per_page' => $cards->perPage(),
                'total' => $cards->total(),
            ],
        ]);
    }

    /**
     * Chi tiết thẻ RFID kèm nhật ký quẹt thẻ gần nhất
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        try {
            $data = $this->service->getCardById($id);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (AccessCardNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Cấp mới thẻ RFID
     */
    public function store(AccessCardRequest $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        try {
            $actorId = $request->user()?->id;
            $card = $this->service->createCard($request->validated(), $actorId ? (string) $actorId : null);

            return response()->json([
                'success' => true,
                'message' => "Cấp thẻ RFID '{$card->card_number}' thành công.",
                'data' => $card,
            ], Response::HTTP_CREATED);
        } catch (AccessCardConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (ModuleCooldownException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'COOLDOWN_ACTIVE',
                'retry_after' => $e->cooldownData['retry_after'] ?? 60,
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Cập nhật thông tin thẻ RFID
     */
    public function update(AccessCardRequest $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        try {
            $actorId = $request->user()?->id;
            $card = $this->service->updateCard($id, $request->validated(), $actorId ? (string) $actorId : null);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin thẻ RFID thành công.',
                'data' => $card,
            ]);
        } catch (AccessCardNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (AccessCardConflictException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (ModuleCooldownException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'COOLDOWN_ACTIVE',
                'retry_after' => $e->cooldownData['retry_after'] ?? 60,
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Đổi trạng thái ACTIVE <-> LOCKED_TEMPORARY
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        try {
            $actorId = $request->user()?->id;
            $card = $this->service->toggleStatus($id, $actorId ? (string) $actorId : null);

            $statusText = ($card->status === 'ACTIVE') ? 'kích hoạt' : 'tạm khóa';

            return response()->json([
                'success' => true,
                'message' => "Đã {$statusText} thẻ RFID thành công.",
                'data' => $card,
            ]);
        } catch (AccessCardNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (ModuleCooldownException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'COOLDOWN_ACTIVE',
                'retry_after' => $e->cooldownData['retry_after'] ?? 60,
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Xóa / vô hiệu hóa thẻ
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        try {
            $actorId = $request->user()?->id;
            $result = $this->service->deleteCard($id, $actorId ? (string) $actorId : null);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'action' => $result['action'],
            ]);
        } catch (AccessCardNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        } catch (ModuleCooldownException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'COOLDOWN_ACTIVE',
                'retry_after' => $e->cooldownData['retry_after'] ?? 60,
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Lấy danh mục căn hộ cho dropdown
     */
    public function apartments(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->getApartmentsForDropdown(),
        ]);
    }

    /**
     * Lấy danh mục cư dân cho dropdown
     */
    public function residents(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request)) {
            return $authError;
        }

        $apartmentId = $request->input('apartment_id');

        return response()->json([
            'success' => true,
            'data' => $this->service->getResidentsForDropdown($apartmentId),
        ]);
    }
}
