<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemporaryRegistrationRequest;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Services\RbacService;
use App\Services\TemporaryRegistrationConflictException;
use App\Services\TemporaryRegistrationNotFoundException;
use App\Services\TemporaryRegistrationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class TemporaryRegistrationController extends Controller
{
    public function __construct(
        protected TemporaryRegistrationService $service
    ) {}

    /**
     * Kiểm tra người dùng có phải chủ hộ hoặc chủ sở hữu của căn hộ cụ thể hay không
     */
    protected function isUserHeadOrOwnerOfApartment(?User $user, ?string $apartmentId): bool
    {
        if (! $user || ! $apartmentId) {
            return false;
        }

        // 1. Kiểm tra bản ghi cư dân tại đúng căn hộ này
        $userResident = Resident::where('user_id', $user->id)
            ->where('apartment_id', $apartmentId)
            ->where('is_active', true)
            ->first();

        if ($userResident && ((bool) $userResident->is_head_of_household || $userResident->resident_type === 'OWNER')) {
            return true;
        }

        // 2. Kiểm tra bản ghi sở hữu trong bảng apartment_owners
        try {
            if (Schema::hasTable('apartment_owners')) {
                $isOwner = DB::table('apartment_owners')
                    ->where('apartment_id', $apartmentId)
                    ->where('owner_user_id', $user->id)
                    ->where('is_current_owner', 1)
                    ->exists();

                if ($isOwner) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * Kiểm tra người dùng có quyền Quản trị viên theo quyền hạn RBAC hoặc vai trò quản trị
     */
    protected function isUserAdmin(?User $user, ?string $requiredPermission = null): bool
    {
        if (! $user) {
            return false;
        }

        if (strtoupper((string) ($user->status ?? '')) !== 'ACTIVE') {
            return false;
        }

        // SUPER_ADMIN luôn có toàn quyền
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // Kiểm tra quyền hạn RBAC cụ thể nếu được yêu cầu (chính sách fail-closed, không fallback theo Role)
        if ($requiredPermission !== null) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($requiredPermission)) {
                return true;
            }

            if (class_exists(RbacService::class) && RbacService::hasPermission($user, $requiredPermission)) {
                return true;
            }

            return false;
        }

        // Khi không chỉ định requiredPermission cụ thể:
        // Người dùng được xem là Admin/Manager phân hệ nếu có bất kỳ quyền quản trị tạm trú nào
        return method_exists($user, 'hasPermission') && (
            $user->hasPermission('TEMP_REG:VIEW')
            || $user->hasPermission('TEMP_REG:CREATE')
            || $user->hasPermission('TEMP_REG:UPDATE')
            || $user->hasPermission('TEMP_REG:DELETE')
            || $user->hasPermission('TEMP_REG:APPROVE')
        );
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
        if ($this->isUserAdmin($user, 'TEMP_REG:VIEW')) {
            return true;
        }

        $registration->loadMissing('resident');

        // Hồ sơ của chính user
        if ($registration->resident && (string) $registration->resident->user_id === (string) $user->id) {
            return true;
        }

        // User có quyền chủ hộ / chủ sở hữu trong căn hộ của hồ sơ này
        if ($this->isUserHeadOrOwnerOfApartment($user, (string) $registration->apartment_id)) {
            return true;
        }

        // Cư dân có quan hệ cư trú trùng khớp với resident_id của hồ sơ
        $userResident = Resident::where('user_id', $user->id)
            ->where('apartment_id', $registration->apartment_id)
            ->where('is_active', true)
            ->first();

        if ($userResident && (string) $registration->resident_id === (string) $userResident->id) {
            return true;
        }

        return false;
    }

    /**
     * Kiểm tra quyền chỉnh sửa hoặc xóa hồ sơ
     */
    protected function canModifyRegistration(User $user, TemporaryRegistration $registration): bool
    {
        if ($this->isUserAdmin($user, 'TEMP_REG:UPDATE')) {
            return true;
        }

        $registration->loadMissing('resident');

        // Hồ sơ của chính user
        if ($registration->resident && (string) $registration->resident->user_id === (string) $user->id) {
            return true;
        }

        // Chủ hộ hoặc chủ sở hữu tại chính căn hộ này
        if ($this->isUserHeadOrOwnerOfApartment($user, (string) $registration->apartment_id)) {
            return true;
        }

        $userResident = Resident::where('user_id', $user->id)
            ->where('apartment_id', $registration->apartment_id)
            ->where('is_active', true)
            ->first();

        if ($userResident && (string) $registration->resident_id === (string) $userResident->id) {
            return true;
        }

        return false;
    }

    /**
     * Kiểm tra quyền quản trị của người dùng theo quyền hạn RBAC
     */
    protected function checkAdminAuthorization(Request $request, ?string $requiredPermission = null): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
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

        if (! $this->isUserAdmin($user, $requiredPermission)) {
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

        $isAdmin = $this->isUserAdmin($user, 'TEMP_REG:VIEW');
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

        $isAdmin = $this->isUserAdmin($user, 'TEMP_REG:UPDATE');

        // Nếu là cư dân, kiểm tra quyền sở hữu đối với hồ sơ
        if (! $isAdmin) {
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
            $data = $request->validated();
            if (! $isAdmin) {
                unset($data['police_reference_code']);
            }

            $updated = $this->service->update($id, $data, $isAdmin);

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
        if (! $this->isUserAdmin($user, 'TEMP_REG:DELETE')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'TEMP_REG:APPROVE')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'TEMP_REG:APPROVE')) {
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
        if ($authError = $this->checkAdminAuthorization($request, 'TEMP_REG:APPROVE')) {
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
            $request->input('side', 'front'),
            $user
        );

        return response()->json([
            'success' => true,
            'message' => 'Tải lên ảnh CCCD thành công.',
            'url' => $url,
        ]);
    }

    /**
     * Tải hoặc xem ảnh CCCD an toàn (chỉ cho phép Chủ sở hữu hồ sơ hoặc Quản trị viên)
     */
    public function showCccd(Request $request, string $filename): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $safeFilename = basename($filename);
        // Kiểm tra định dạng tên tệp nghiêm ngặt chống Path Traversal và ký tự đặc biệt
        if ($safeFilename !== $filename
            || str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || ! preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|webp|pdf)$/i', $safeFilename)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Tên tệp không hợp lệ hoặc chứa ký tự bị cấm.',
                'error' => 'INVALID_FILENAME',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 1. Kiểm tra quyền truy cập: Admin có toàn quyền xem
        if (! $this->isUserAdmin($user)) {
            // Cư dân: Kiểm tra xem tệp có gắn với hồ sơ nào mà cư dân có quyền truy cập
            $registration = TemporaryRegistration::where(function ($q) use ($safeFilename) {
                $q->where('identity_card_front_url', 'like', "%{$safeFilename}%")
                    ->orWhere('identity_card_back_url', 'like', "%{$safeFilename}%");
            })->first();

            if ($registration) {
                if (! $this->canAccessRegistration($user, $registration)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn không có quyền truy cập ảnh CCCD này.',
                        'error' => 'FORBIDDEN',
                    ], Response::HTTP_FORBIDDEN);
                }
            } else {
                // Tệp vừa được upload trong form tạo mới, chưa lưu vào CSDL
                // Phân tích uploader ID bằng regex chuẩn định dạng cccd_{side}_u{user_id}_{random16}.{ext}
                if (preg_match('/^cccd_(?:front|back)_u([0-9a-fA-F\-]{36})_[a-zA-Z0-9]{16}\.[a-zA-Z0-9]+$/', $safeFilename, $matches)) {
                    $fileUploaderId = $matches[1];
                    if ((string) $fileUploaderId !== (string) $user->id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Bạn không có quyền truy cập ảnh CCCD này.',
                            'error' => 'FORBIDDEN',
                        ], Response::HTTP_FORBIDDEN);
                    }
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn không có quyền truy cập ảnh CCCD này.',
                        'error' => 'FORBIDDEN',
                    ], Response::HTTP_FORBIDDEN);
                }
            }
        }

        // 2. Tìm tệp trên disk: ưu tiên local (private)
        $storagePath = 'temporary_registrations/cccd/'.$safeFilename;
        $disk = 'local';

        if (! Storage::disk('local')->exists($storagePath)) {
            // Kiểm tra tệp cũ trên public disk
            if (Storage::disk('public')->exists($storagePath)) {
                // Di chuyển an toàn tệp từ public sang local để xóa bỏ hoàn toàn nguy cơ truy cập static URL
                $content = Storage::disk('public')->get($storagePath);
                if ($content !== null) {
                    $stored = Storage::disk('local')->put($storagePath, $content);
                    if ($stored && Storage::disk('local')->exists($storagePath)
                        && Storage::disk('local')->size($storagePath) === Storage::disk('public')->size($storagePath)
                    ) {
                        // Xác minh lưu thành công trên local -> xóa bản sao trên public
                        Storage::disk('public')->delete($storagePath);
                        $disk = 'local';
                    } else {
                        $disk = 'public';
                    }
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Tệp ảnh CCCD không tồn tại trên hệ thống lưu trữ.',
                    'error' => 'NOT_FOUND',
                ], Response::HTTP_NOT_FOUND);
            }
        }

        // 3. Xác minh an toàn đường dẫn tệp nằm đúng trong thư mục cho phép (Directory Containment Check)
        try {
            $baseDir = realpath(Storage::disk($disk)->path('temporary_registrations/cccd'));
            $filePath = realpath(Storage::disk($disk)->path($storagePath));
            if ($baseDir && $filePath && ! str_starts_with($filePath, $baseDir.DIRECTORY_SEPARATOR)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Đường dẫn tệp nằm ngoài phạm vi cho phép.',
                    'error' => 'PATH_TRAVERSAL_DETECTED',
                ], Response::HTTP_FORBIDDEN);
            }
        } catch (\Throwable $e) {
            // Bỏ qua ngoại lệ nếu chạy trong testing fake storage adapter
        }

        return Storage::disk($disk)->response($storagePath, $safeFilename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Tải hoặc xem ảnh CCCD theo ID hồ sơ và mặt (front/back)
     */
    public function downloadCccd(Request $request, string $id, string $side): Response
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
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $this->canAccessRegistration($user, $registration)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập ảnh CCCD của hồ sơ này.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $field = strtolower($side) === 'back' ? 'identity_card_back_url' : 'identity_card_front_url';
        $fileUrl = $registration->$field;

        if (! $fileUrl) {
            return response()->json([
                'success' => false,
                'message' => 'Hồ sơ chưa có ảnh CCCD cho mặt này.',
                'error' => 'NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        $filename = basename(parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);

        return $this->showCccd($request, $filename);
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
