<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use App\Services\StaffScheduleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StaffScheduleController extends Controller
{
    public function __construct(
        protected StaffScheduleService $service
    ) {}

    /**
     * Xác thực người dùng và kiểm tra quyền quản lý ca trực
     */
    protected function authorizeManager(Request $request): ?object
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        if (! $user) {
            abort(401, 'Vui lòng đăng nhập để thực hiện chức năng này.');
        }

        if (
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ||
            (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'BUILDING_MANAGER', 'MANAGER']))
        ) {
            return $user;
        }

        abort(403, 'Bạn không có quyền quản lý xếp ca trực.');
    }

    /**
     * Xác thực người dùng có quyền xem lịch trực (Quản lý, Lễ tân, Bảo vệ)
     */
    protected function authorizeViewer(Request $request): ?object
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        if (! $user) {
            abort(401, 'Vui lòng đăng nhập để xem lịch trực.');
        }

        if (
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ||
            (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'BUILDING_MANAGER', 'MANAGER', 'RECEPTIONIST', 'SECURITY_GUARD', 'STAFF']))
        ) {
            return $user;
        }

        abort(403, 'Bạn không có quyền truy cập lịch trực.');
    }

    /**
     * Lấy danh sách lịch trực theo bộ lọc (Tuần / Tháng)
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        $filters = [
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
            'role' => $request->query('role'),
            'staff_profile_id' => $request->query('staff_profile_id'),
            'shift_type_id' => $request->query('shift_type_id'),
            'status' => $request->query('status'),
            'search' => $request->query('search'),
        ];

        try {
            $data = $this->service->getSchedules($filters);

            return response()->json([
                'success' => true,
                'data' => $data['schedules'],
                'summary' => $data['summary'],
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi khi tải danh sách ca trực.',
            ], 500);
        }
    }

    /**
     * Lấy danh sách các loại ca trực (Shift Types)
     */
    public function shiftTypes(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        try {
            $shifts = $this->service->getShiftTypes();

            return response()->json([
                'success' => true,
                'data' => $shifts,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi khi tải loại ca trực.',
            ], 500);
        }
    }

    /**
     * Lấy danh sách nhân viên ứng viên (Lễ tân & Bảo vệ) để phân ca
     */
    public function staffCandidates(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        $role = $request->query('role');

        try {
            $candidates = $this->service->getStaffCandidates($role);

            return response()->json([
                'success' => true,
                'data' => $candidates,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi khi tải danh sách nhân viên.',
            ], 500);
        }
    }

    /**
     * Tạo lịch trực mới
     */
    public function store(Request $request): JsonResponse
    {
        $manager = $this->authorizeManager($request);

        $validated = $request->validate([
            'staff_profile_id' => 'required|string',
            'shift_type_id' => 'required|string',
            'work_date' => 'required|date',
            'assigned_location' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:SCHEDULED,COMPLETED,CANCELLED,ABSENT,SWAPPED',
        ], [
            'staff_profile_id.required' => 'Vui lòng chọn nhân viên trực.',
            'shift_type_id.required' => 'Vui lòng chọn loại ca trực.',
            'work_date.required' => 'Vui lòng chọn ngày trực.',
            'work_date.date' => 'Định dạng ngày trực không hợp lệ.',
        ]);

        try {
            $schedule = $this->service->createSchedule($validated, $manager);

            return response()->json([
                'success' => true,
                'message' => 'Phân công ca trực thành công.',
                'data' => $schedule,
            ], 201);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Dữ liệu không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi trong quá trình tạo ca trực.',
            ], 500);
        }
    }

    /**
     * Cập nhật thông tin ca trực
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $manager = $this->authorizeManager($request);

        $validated = $request->validate([
            'staff_profile_id' => 'sometimes|required|string',
            'shift_type_id' => 'sometimes|required|string',
            'work_date' => 'sometimes|required|date',
            'assigned_location' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:SCHEDULED,COMPLETED,CANCELLED,ABSENT,SWAPPED',
        ]);

        try {
            $schedule = $this->service->updateSchedule($id, $validated, $manager);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật ca trực thành công.',
                'data' => $schedule,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ca trực không tồn tại hoặc đã được xóa trước đó.',
            ], 404);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Dữ liệu không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi khi cập nhật ca trực.',
            ], 500);
        }
    }

    /**
     * Xóa hoặc hủy ca trực
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $manager = $this->authorizeManager($request);

        $mode = $request->input('mode', $request->query('mode', 'delete'));

        try {
            $result = $this->service->deleteOrCancelSchedule($id, $mode, $manager);

            $msg = $result['action'] === 'CANCELLED'
                ? 'Đã chuyển trạng thái hủy ca trực.'
                : 'Đã xóa ca trực thành công.';

            return response()->json([
                'success' => true,
                'message' => $msg,
                'data' => $result,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ca trực không tồn tại hoặc đã được xóa trước đó.',
            ], 404);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Đã xảy ra lỗi khi xóa/hủy ca trực.',
            ], 500);
        }
    }
}
