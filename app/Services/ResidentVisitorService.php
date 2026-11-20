<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResidentVisitorService
{
    /**
     * Các vai trò quản trị / vận hành được phép thao tác liên căn hộ.
     *
     * @var array<int, string>
     */
    private const ADMIN_STAFF_ROLES = [
        'SUPER_ADMIN',
        'SUPER_ADMI',
        'ADMIN',
        'BUILDING_MANAGER',
        'MANAGER',
        'STAFF',
        'RECEPTIONIST',
    ];

    /**
     * Kiểm tra người dùng có phải là Quản trị viên cấp cao (SUPER_ADMIN / ADMIN).
     */
    protected function isSystemAdmin(object $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN'])) {
            return true;
        }

        if (! isset($user->id)) {
            return false;
        }

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->whereIn('roles.role_code', ['SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN'])
            ->exists();
    }

    /**
     * Kiểm tra người dùng có vai trò quản trị / ban quản lý / lễ tân / nhân viên vận hành.
     */
    protected function isAdminOrStaff(object $user): bool
    {
        if (method_exists($user, 'hasRole') && $user->hasRole(self::ADMIN_STAFF_ROLES)) {
            return true;
        }

        if (! isset($user->id)) {
            return false;
        }

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->whereIn('roles.role_code', self::ADMIN_STAFF_ROLES)
            ->exists();
    }

    /**
     * Kiểm tra người dùng có quyền quản trị/nhân viên với permission cụ thể.
     * SUPER_ADMIN / ADMIN được bypass toàn quyền.
     * Các vai trò nhân viên vận hành (STAFF, RECEPTIONIST, ...) bắt buộc phải có permission $permissionCode trong RBAC.
     */
    protected function isAuthorizedAdminOrStaff(object $user, string $permissionCode): bool
    {
        if ($this->isSystemAdmin($user)) {
            return true;
        }

        if (! $this->isAdminOrStaff($user)) {
            return false;
        }

        return RbacService::hasPermission($user, $permissionCode);
    }

    /**
     * Xác thực và xác định căn hộ tiếp đón hợp lệ cho thao tác tạo lượt khách.
     */
    protected function resolveAuthorizedApartmentId(object $user, mixed $requestedApartmentId): string
    {
        $requested = is_string($requestedApartmentId)
            ? trim($requestedApartmentId)
            : ($requestedApartmentId !== null ? (string) $requestedApartmentId : '');

        if ($requested !== '') {
            if ($this->isAdminOrStaff($user)) {
                $apartmentExists = DB::table('apartments')
                    ->where('id', $requested)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $apartmentExists) {
                    abort(422, 'Căn hộ được chọn không tồn tại hoặc đã ngừng hoạt động.');
                }

                return $requested;
            }

            $authorizedApartmentIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
            if (! in_array($requested, $authorizedApartmentIds, true)) {
                abort(403, 'Bạn không có quyền đăng ký khách cho căn hộ này.');
            }

            return $requested;
        }

        $authorizedApartmentIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        if (! empty($authorizedApartmentIds)) {
            return (string) $authorizedApartmentIds[0];
        }

        abort(422, 'Không thể xác định căn hộ tiếp đón cho tài khoản cư dân này.');
    }

    /**
     * Lấy danh sách lượt khai báo khách của chính cư dân (kèm tìm kiếm, bộ lọc, phân trang và thống kê)
     *
     * @param  object  $user  Tài khoản cư dân đã xác thực
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listVisitors(object $user, array $filters = []): array
    {
        $userId = $user->id;

        // 1. Kiểm tra quyền của người dùng (Admin hoặc Nhân viên có quyền VISITOR:VIEW)
        $isAdminOrStaff = $this->isAuthorizedAdminOrStaff($user, 'VISITOR:VIEW');

        // Scope lọc dữ liệu:
        // - Quản trị viên / Ban quản lý / Lễ tân: Lấy toàn bộ dữ liệu lượt khách từ database
        // - Cư dân: Thống nhất chỉ xem danh sách lượt khai báo do chính mình tạo (BUG-M6-03: loại bỏ $hasOwn mâu thuẫn rò rỉ dữ liệu cùng căn hộ)
        $applyScope = function ($q) use ($userId, $isAdminOrStaff) {
            if ($isAdminOrStaff) {
                return;
            }

            $q->where('visitor_registrations.host_resident_user_id', $userId);
        };

        // 1. Tính toán KPIs tổng quan trực tiếp bằng 1 câu truy vấn gom nhóm tối ưu
        $statsQuery = DB::table('visitor_registrations');
        if (! $isAdminOrStaff) {
            $applyScope($statsQuery);
        }

        $stats = $statsQuery
            ->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN qr_pass_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN qr_pass_status = 'USED' THEN 1 ELSE 0 END) as used_count,
                SUM(CASE WHEN qr_pass_status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN qr_pass_status = 'EXPIRED' THEN 1 ELSE 0 END) as expired_count
            ")
            ->first();

        $kpis = [
            'total' => (int) ($stats->total_count ?? 0),
            'active' => (int) ($stats->active_count ?? 0),
            'used' => (int) ($stats->used_count ?? 0),
            'cancelled' => (int) ($stats->cancelled_count ?? 0),
            'expired' => (int) ($stats->expired_count ?? 0),
        ];

        // 2. Áp dụng các bộ lọc tìm kiếm
        $query = DB::table('visitor_registrations')
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id');

        if (! $isAdminOrStaff) {
            $applyScope($query);
        }

        if (! empty($filters['search'])) {
            $keyword = trim($filters['search']);
            $query->where(function ($q) use ($keyword) {
                $q->where('visitor_registrations.visitor_name', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.visitor_phone', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.registration_code', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.vehicle_license_plate', 'like', "%{$keyword}%");
            });
        }

        if (! empty($filters['status']) && strtoupper($filters['status']) !== 'ALL') {
            $query->where('visitor_registrations.qr_pass_status', strtoupper($filters['status']));
        }

        if (! empty($filters['from_date'])) {
            $query->where('visitor_registrations.expected_arrival_time', '>=', Carbon::parse($filters['from_date'])->startOfDay());
        }

        if (! empty($filters['to_date'])) {
            $query->where('visitor_registrations.expected_arrival_time', '<=', Carbon::parse($filters['to_date'])->endOfDay());
        }

        // 3. Phân trang phía Server
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['limit'] ?? $filters['per_page'] ?? 10)));
        $totalItems = (clone $query)->count();

        // 4. Chỉ SELECT những trường cần thiết (tránh N+1 và giảm dung lượng payload)
        $items = $query
            ->select([
                'visitor_registrations.id',
                'visitor_registrations.registration_code',
                'visitor_registrations.host_resident_user_id',
                'visitor_registrations.apartment_id',
                'visitor_registrations.visitor_name',
                'visitor_registrations.visitor_phone',
                'visitor_registrations.visitor_national_id',
                'visitor_registrations.expected_arrival_time',
                'visitor_registrations.expected_departure_time',
                'visitor_registrations.visit_purpose',
                'visitor_registrations.visitor_count',
                'visitor_registrations.vehicle_license_plate',
                'visitor_registrations.qr_access_pass_code',
                'visitor_registrations.qr_pass_status',
                'visitor_registrations.is_pre_approved_by_resident',
                'visitor_registrations.created_at',
                'visitor_registrations.updated_at',
                'apartments.apartment_number',
                'blocks.block_name',
                'blocks.block_code',
            ])
            ->orderBy('visitor_registrations.expected_arrival_time', 'desc')
            ->orderBy('visitor_registrations.created_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return [
            'success' => true,
            'data' => $items,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalItems,
                'last_page' => (int) ceil($totalItems / $perPage),
            ],
        ];
    }

    /**
     * Xem chi tiết một lượt khai báo (kiểm tra quyền sở hữu)
     *
     * @param  object  $user  Tài khoản cư dân
     * @param  string  $id  ID lượt khai báo
     * @return array<string, mixed>
     */
    public function getVisitorDetail(object $user, string $id): array
    {
        $item = DB::table('visitor_registrations')
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('visitor_registrations.id', $id)
            ->select([
                'visitor_registrations.*',
                'apartments.apartment_number',
                'blocks.block_name',
                'blocks.block_code',
            ])
            ->first();

        if (! $item) {
            abort(404, 'Lượt khai báo khách không tồn tại.');
        }

        // Kiểm tra quyền sở hữu hoặc quản trị viên / nhân viên có quyền VISITOR:VIEW
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAuthorized = $isOwner || $this->isAuthorizedAdminOrStaff($user, 'VISITOR:VIEW');

        if (! $isAuthorized) {
            abort(403, 'Bạn không có quyền truy cập thông tin lượt khai báo khách này.');
        }

        // Lấy lịch sử check-in nếu có
        $checkinLog = DB::table('visitor_checkin_logs')
            ->where('registration_id', $id)
            ->first();

        // Kiểm tra cooldown còn lại của bản ghi
        $cooldown = QuocTinRealtimeService::getVisitorRecordCooldown($id);

        return [
            'success' => true,
            'data' => $item,
            'checkin_log' => $checkinLog,
            'cooldown' => $cooldown,
        ];
    }

    /**
     * Tạo lượt khai báo khách mới
     *
     * @param  object  $user  Tài khoản cư dân
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createVisitor(object $user, array $data): array
    {
        $userId = $user->id;

        // 1. Xác định và xác thực quyền truy cập căn hộ đón tiếp
        $apartmentId = $this->resolveAuthorizedApartmentId($user, $data['apartment_id'] ?? null);

        // 2. Chống tạo trùng lặp với cùng số điện thoại và thời gian dự kiến đến
        $expectedArrival = Carbon::parse($data['expected_arrival_time']);
        if ($expectedArrival->lt(Carbon::now()->subMinutes(15))) {
            throw ValidationException::withMessages([
                'expected_arrival_time' => ['Thời gian dự kiến đến không thể ở trong quá khứ.'],
            ]);
        }
        $visitorPhone = trim($data['visitor_phone'] ?? '');

        if ($visitorPhone !== '') {
            $duplicateExists = DB::table('visitor_registrations')
                ->where('host_resident_user_id', $userId)
                ->where('visitor_phone', $visitorPhone)
                ->where('qr_pass_status', 'ACTIVE')
                ->whereBetween('expected_arrival_time', [
                    $expectedArrival->copy()->subHours(2),
                    $expectedArrival->copy()->addHours(2),
                ])
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'visitor_phone' => ['Đã tồn tại lượt khai báo cho khách này trong khung giờ dự kiến đến.'],
                ]);
            }
        }

        // 3. Khóa chống double-click (Atomic Lock)
        $lock = Cache::lock("visitor:create_lock:{$userId}", 3);
        if (! $lock->get()) {
            abort(429, 'Hệ thống đang xử lý yêu cầu trước đó, vui lòng không bấm liên tục.');
        }

        try {
            $visitorId = (string) Str::uuid();

            // Sinh mã đăng ký duy nhất: VIS-YYYYMMDD-XXXX
            do {
                $regCode = 'VIS-'.date('Ymd').'-'.strtoupper(Str::random(4));
            } while (DB::table('visitor_registrations')->where('registration_code', $regCode)->exists());

            // Sinh mã QR Pass thông hành duy nhất
            do {
                $qrPass = 'PASS_'.strtoupper(Str::random(18));
            } while (DB::table('visitor_registrations')->where('qr_access_pass_code', $qrPass)->exists());

            $expectedDeparture = ! empty($data['expected_departure_time'])
                ? Carbon::parse($data['expected_departure_time'])
                : null;

            if ($expectedDeparture && $expectedDeparture->lt($expectedArrival)) {
                throw ValidationException::withMessages([
                    'expected_departure_time' => ['Thời gian dự kiến rời đi phải sau thời gian dự kiến đến.'],
                ]);
            }

            $now = Carbon::now();

            DB::transaction(function () use ($visitorId, $regCode, $userId, $apartmentId, $data, $expectedArrival, $expectedDeparture, $qrPass, $now) {
                DB::table('visitor_registrations')->insert([
                    'id' => $visitorId,
                    'registration_code' => $regCode,
                    'host_resident_user_id' => $userId,
                    'apartment_id' => $apartmentId,
                    'visitor_name' => trim($data['visitor_name']),
                    'visitor_phone' => trim($data['visitor_phone'] ?? ''),
                    'visitor_national_id' => ! empty($data['visitor_national_id']) ? trim($data['visitor_national_id']) : null,
                    'expected_arrival_time' => $expectedArrival,
                    'expected_departure_time' => $expectedDeparture,
                    'visit_purpose' => ! empty($data['visit_purpose']) ? trim($data['visit_purpose']) : 'Thăm cư dân',
                    'visitor_count' => max(1, (int) ($data['visitor_count'] ?? 1)),
                    'vehicle_license_plate' => ! empty($data['vehicle_license_plate']) ? trim(strtoupper($data['vehicle_license_plate'])) : null,
                    'qr_access_pass_code' => $qrPass,
                    'qr_pass_status' => 'ACTIVE',
                    'is_pre_approved_by_resident' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            // 4. Phát sự kiện Realtime cập nhật tức thì (chỉ chứa metadata tối thiểu, không lộ thông tin nhạy cảm)
            QuocTinRealtimeService::emit(
                'visitors',
                'visitor_registration',
                'CREATED',
                $visitorId,
                [
                    'host_resident_user_id' => $userId,
                    'apartment_id' => $apartmentId,
                    'registration_code' => $regCode,
                ],
                $userId,
                false // Không khóa toàn bộ cư dân
            );

            $created = DB::table('visitor_registrations')
                ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
                ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
                ->where('visitor_registrations.id', $visitorId)
                ->select([
                    'visitor_registrations.*',
                    'apartments.apartment_number',
                    'blocks.block_name',
                ])
                ->first();

            return [
                'success' => true,
                'message' => 'Khai báo khách viếng thăm thành công! Mã QR Pass đã được cấp.',
                'data' => $created,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Chỉnh sửa thông tin lượt khai báo
     *
     * @param  object  $user  Tài khoản cư dân
     * @param  string  $id  ID lượt khai báo
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateVisitor(object $user, string $id, array $data): array
    {
        $item = DB::table('visitor_registrations')->where('id', $id)->first();
        if (! $item) {
            abort(404, 'Lượt khai báo khách không tồn tại.');
        }

        // Kiểm tra quyền sở hữu hoặc quản trị viên / nhân viên có quyền VISITOR:UPDATE
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAuthorized = $isOwner || $this->isAuthorizedAdminOrStaff($user, 'VISITOR:UPDATE');
        if (! $isAuthorized) {
            abort(403, 'Bạn không có quyền chỉnh sửa lượt khai báo này.');
        }

        // Quy tắc nghiệp vụ: Chỉ cho phép sửa khi trạng thái còn ACTIVE và chưa check-in
        if ($item->qr_pass_status !== 'ACTIVE') {
            abort(422, "Lượt khai báo ở trạng thái '{$item->qr_pass_status}' không được phép chỉnh sửa.");
        }

        $alreadyCheckedIn = DB::table('visitor_checkin_logs')
            ->where('registration_id', $id)
            ->exists();

        if ($alreadyCheckedIn) {
            abort(422, 'Khách đã được tiếp nhận tại quầy lễ tân / bảo vệ, không thể chỉnh sửa thông tin hành chính. Vui lòng sử dụng tính năng gia hạn giờ rời đi.');
        }

        // Kiểm tra Concurrency / Cooldown theo phạm vi BẢN GHI (Record-level Cooldown)
        $recordCooldown = QuocTinRealtimeService::getVisitorRecordCooldown($id);
        if ($recordCooldown !== null && ($recordCooldown['actor_id'] ?? null) !== $user->id) {
            abort(409, 'Lượt khai báo này vừa được chỉnh sửa bởi một phiên khác. Vui lòng chờ trước khi thao tác tiếp.');
        }

        if (! empty($data['expected_arrival_time'])) {
            $newArrival = Carbon::parse($data['expected_arrival_time']);
            if ($newArrival->lt(Carbon::now()->subMinutes(15))) {
                throw ValidationException::withMessages([
                    'expected_arrival_time' => ['Thời gian dự kiến đến không thể ở trong quá khứ.'],
                ]);
            }
            $expectedArrival = $newArrival;
        } else {
            $expectedArrival = Carbon::parse($item->expected_arrival_time);
        }

        $expectedDeparture = isset($data['expected_departure_time'])
            ? ($data['expected_departure_time'] ? Carbon::parse($data['expected_departure_time']) : null)
            : ($item->expected_departure_time ? Carbon::parse($item->expected_departure_time) : null);

        if ($expectedDeparture && $expectedDeparture->lt($expectedArrival)) {
            throw ValidationException::withMessages([
                'expected_departure_time' => ['Thời gian dự kiến rời đi phải sau thời gian dự kiến đến.'],
            ]);
        }

        $now = Carbon::now();

        $updateData = [
            'visitor_name' => trim($data['visitor_name'] ?? $item->visitor_name),
            'visitor_phone' => trim($data['visitor_phone'] ?? $item->visitor_phone),
            'expected_arrival_time' => $expectedArrival,
            'expected_departure_time' => $expectedDeparture,
            'updated_at' => $now,
        ];

        if (array_key_exists('visitor_national_id', $data)) {
            $updateData['visitor_national_id'] = $data['visitor_national_id'] ? trim($data['visitor_national_id']) : null;
        }

        if (array_key_exists('visit_purpose', $data)) {
            $updateData['visit_purpose'] = trim($data['visit_purpose']);
        }

        if (array_key_exists('visitor_count', $data)) {
            $updateData['visitor_count'] = max(1, (int) $data['visitor_count']);
        }

        if (array_key_exists('vehicle_license_plate', $data)) {
            $updateData['vehicle_license_plate'] = $data['vehicle_license_plate'] ? trim(strtoupper($data['vehicle_license_plate'])) : null;
        }

        DB::transaction(function () use ($id, $updateData) {
            DB::table('visitor_registrations')->where('id', $id)->update($updateData);
        });

        // Kích hoạt cooldown bảo vệ bản ghi tránh race-condition giữa 2 tab
        QuocTinRealtimeService::setVisitorRecordCooldown($id, $user->id, 30);

        // Phát sự kiện Realtime cập nhật tức thì
        QuocTinRealtimeService::emit(
            'visitors',
            'visitor_registration',
            'UPDATED',
            $id,
            [
                'host_resident_user_id' => $item->host_resident_user_id,
                'apartment_id' => $item->apartment_id,
            ],
            $user->id,
            false
        );

        $updated = DB::table('visitor_registrations')
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('visitor_registrations.id', $id)
            ->select([
                'visitor_registrations.*',
                'apartments.apartment_number',
                'blocks.block_name',
            ])
            ->first();

        return [
            'success' => true,
            'message' => 'Cập nhật thông tin khách viếng thăm thành công.',
            'data' => $updated,
        ];
    }

    /**
     * Hủy lượt khai báo khách
     *
     * @param  object  $user  Tài khoản cư dân
     * @param  string  $id  ID lượt khai báo
     * @param  string|null  $reason  Lý do hủy
     * @return array<string, mixed>
     */
    public function cancelVisitor(object $user, string $id, ?string $reason = null): array
    {
        $item = DB::table('visitor_registrations')->where('id', $id)->first();
        if (! $item) {
            abort(404, 'Lượt khai báo khách không tồn tại.');
        }

        // Kiểm tra quyền sở hữu hoặc quản trị viên / nhân viên có quyền VISITOR:DELETE
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAuthorized = $isOwner || $this->isAuthorizedAdminOrStaff($user, 'VISITOR:DELETE');
        if (! $isAuthorized) {
            abort(403, 'Bạn không có quyền hủy lượt khai báo này.');
        }

        if ($item->qr_pass_status === 'CANCELLED') {
            abort(422, 'Lượt khai báo này đã được hủy trước đó.');
        }

        if ($item->qr_pass_status === 'USED') {
            abort(422, 'Khách đã sử dụng mã QR để vào tòa nhà, không thể hủy lượt khai báo.');
        }

        $alreadyCheckedIn = DB::table('visitor_checkin_logs')
            ->where('registration_id', $id)
            ->exists();

        if ($alreadyCheckedIn) {
            abort(422, 'Khách đã check-in vào tòa nhà, không thể hủy lượt khai báo.');
        }

        DB::transaction(function () use ($id) {
            DB::table('visitor_registrations')->where('id', $id)->update([
                'qr_pass_status' => 'CANCELLED',
                'updated_at' => Carbon::now(),
            ]);
        });

        // Xóa cooldown bản ghi sau khi hủy thành công
        QuocTinRealtimeService::clearVisitorRecordCooldown($id);

        // Phát sự kiện Realtime cập nhật
        QuocTinRealtimeService::emit(
            'visitors',
            'visitor_registration',
            'CANCELLED',
            $id,
            [
                'host_resident_user_id' => $item->host_resident_user_id,
                'apartment_id' => $item->apartment_id,
            ],
            $user->id,
            false
        );

        $cancelled = DB::table('visitor_registrations')->where('id', $id)->first();

        return [
            'success' => true,
            'message' => 'Đã hủy lượt khai báo khách thành công.',
            'data' => $cancelled,
        ];
    }

    /**
     * Gia hạn giờ rời dự kiến sau khi đã check-in hoặc trong quá trình lưu trú.
     *
     * @param  object  $user  Tài khoản cư dân hoặc nhân viên
     * @param  string  $id  ID lượt khai báo
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function extendDeparture(object $user, string $id, array $data): array
    {
        $item = DB::table('visitor_registrations')->where('id', $id)->first();
        if (! $item) {
            abort(404, 'Lượt khai báo khách không tồn tại.');
        }

        $isOwner = $item->host_resident_user_id === $user->id;
        $isAuthorized = $isOwner || $this->isAuthorizedAdminOrStaff($user, 'VISITOR:UPDATE');
        if (! $isAuthorized) {
            abort(403, 'Bạn không có quyền gia hạn thời gian cho lượt khai báo này.');
        }

        if (in_array($item->qr_pass_status, ['CANCELLED', 'EXPIRED'], true)) {
            abort(422, "Lượt khai báo ở trạng thái '{$item->qr_pass_status}' không thể gia hạn thời gian rời đi.");
        }

        if (empty($data['expected_departure_time'])) {
            throw ValidationException::withMessages([
                'expected_departure_time' => ['Vui lòng cung cấp thời gian dự kiến rời đi mới.'],
            ]);
        }

        $newDeparture = Carbon::parse($data['expected_departure_time']);
        $arrival = Carbon::parse($item->expected_arrival_time);

        if ($newDeparture->lte(Carbon::now()->subMinutes(5))) {
            throw ValidationException::withMessages([
                'expected_departure_time' => ['Thời gian dự kiến rời đi mới phải ở trong tương lai.'],
            ]);
        }

        if ($newDeparture->lte($arrival)) {
            throw ValidationException::withMessages([
                'expected_departure_time' => ['Thời gian dự kiến rời đi mới phải sau thời gian dự kiến đến.'],
            ]);
        }

        if ($item->expected_departure_time && $newDeparture->lte(Carbon::parse($item->expected_departure_time))) {
            throw ValidationException::withMessages([
                'expected_departure_time' => ['Thời gian gia hạn mới phải sau thời gian dự kiến rời đi đã đăng ký trước đó.'],
            ]);
        }

        $now = Carbon::now();

        DB::transaction(function () use ($id, $newDeparture, $now) {
            DB::table('visitor_registrations')->where('id', $id)->update([
                'expected_departure_time' => $newDeparture,
                'updated_at' => $now,
            ]);
        });

        QuocTinRealtimeService::emit(
            'visitors',
            'visitor_registration',
            'EXTENDED',
            $id,
            [
                'host_resident_user_id' => $item->host_resident_user_id,
                'apartment_id' => $item->apartment_id,
                'new_departure_time' => $newDeparture->toIso8601String(),
            ],
            $user->id,
            false
        );

        $updated = DB::table('visitor_registrations')
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('visitor_registrations.id', $id)
            ->select([
                'visitor_registrations.*',
                'apartments.apartment_number',
                'blocks.block_name',
            ])
            ->first();

        return [
            'success' => true,
            'message' => 'Gia hạn thời gian dự kiến rời đi thành công.',
            'data' => $updated,
        ];
    }
}
