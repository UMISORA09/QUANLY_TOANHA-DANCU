<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use App\Services\ResidentVisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResidentVisitorController extends Controller
{
    public function __construct(
        protected ResidentVisitorService $visitorService
    ) {}

    /**
     * Helper giải quyết người dùng đã xác thực (hỗ trợ Bearer Token, Session, X-User-Id header)
     */
    protected function resolveAuthUser(Request $request): object
    {
        $user = RbacService::resolveUser($request);
        if (! $user) {
            abort(401, 'Yêu cầu đăng nhập tài khoản cư dân để thực hiện thao tác.');
        }

        return $user;
    }

    /**
     * Trích xuất dữ liệu đầu vào linh hoạt từ Request (hỗ trợ cả JSON payload, form-data và raw body)
     *
     * @return array<string, mixed>
     */
    protected function getRequestData(Request $request): array
    {
        $data = $request->all();
        if (empty($data) || ! isset($data['visitor_name'])) {
            $jsonData = $request->json()->all();
            if (! empty($jsonData)) {
                $data = array_merge($data, $jsonData);
            } else {
                $raw = $request->getContent();
                if (! empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = array_merge($data, $decoded);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * GET /api/v1/resident/visitors
     * Lấy danh sách các lượt khai báo khách của chính cư dân
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
            'page' => $request->query('page', 1),
            'limit' => $request->query('limit', $request->query('per_page', 10)),
        ];

        $result = $this->visitorService->listVisitors($user, $filters);

        return response()->json($result);
    }

    /**
     * GET /api/v1/resident/visitors/{id}
     * Xem thông tin chi tiết một lượt khai báo khách
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->resolveAuthUser($request);
        $result = $this->visitorService->getVisitorDetail($user, $id);

        return response()->json($result);
    }

    /**
     * POST /api/v1/resident/visitors
     * Tạo mới lượt khai báo khách viếng thăm
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        $validated = Validator::make($this->getRequestData($request), [
            'visitor_name' => 'required|string|min:2|max:150',
            'visitor_phone' => 'required|string|min:8|max:25',
            'visitor_national_id' => 'nullable|string|max:30',
            'expected_arrival_time' => 'required|date',
            'expected_departure_time' => 'nullable|date|after_or_equal:expected_arrival_time',
            'visit_purpose' => 'nullable|string|max:200',
            'visitor_count' => 'nullable|integer|min:1|max:50',
            'vehicle_license_plate' => 'nullable|string|max:30',
            'apartment_id' => 'nullable|string',
        ])->validate();

        $result = $this->visitorService->createVisitor($user, $validated);

        return response()->json($result, 201);
    }

    /**
     * PUT/PATCH /api/v1/resident/visitors/{id}
     * Cập nhật thông tin lượt khai báo khách
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        $validated = Validator::make($this->getRequestData($request), [
            'visitor_name' => 'sometimes|required|string|min:2|max:150',
            'visitor_phone' => 'sometimes|required|string|min:8|max:25',
            'visitor_national_id' => 'nullable|string|max:30',
            'expected_arrival_time' => 'sometimes|required|date',
            'expected_departure_time' => 'nullable|date',
            'visit_purpose' => 'nullable|string|max:200',
            'visitor_count' => 'nullable|integer|min:1|max:50',
            'vehicle_license_plate' => 'nullable|string|max:30',
        ])->validate();

        $result = $this->visitorService->updateVisitor($user, $id, $validated);

        return response()->json($result);
    }

    /**
     * POST /api/v1/resident/visitors/{id}/cancel
     * Hủy lượt khai báo khách
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $this->resolveAuthUser($request);
        $data = $this->getRequestData($request);
        $reason = $data['reason'] ?? $request->input('reason');

        $result = $this->visitorService->cancelVisitor($user, $id, $reason);

        return response()->json($result);
    }
}
