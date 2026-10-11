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
     * Lấy danh sách lượt khai báo khách của chính cư dân (kèm tìm kiếm, bộ lọc, phân trang và thống kê)
     *
     * @param  object  $user  Tài khoản cư dân đã xác thực
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listVisitors(object $user, array $filters = []): array
    {
        $userId = $user->id;

        // Query cơ sở được lọc nghiêm ngặt theo host_resident_user_id của cư dân
        $baseQuery = DB::table('visitor_registrations')
            ->where('visitor_registrations.host_resident_user_id', $userId);

        // 1. Tính toán KPIs tổng quan trực tiếp bằng 1 câu truy vấn gom nhóm tối ưu
        $stats = DB::table('visitor_registrations')
            ->where('host_resident_user_id', $userId)
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
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('visitor_registrations.host_resident_user_id', $userId);

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

        // Kiểm tra quyền sở hữu
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAdmin = method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'));

        if (! $isOwner && ! $isAdmin) {
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

        // 1. Xác định căn hộ đón tiếp hợp lệ của cư dân
        $apartmentId = $data['apartment_id'] ?? null;
        if (! $apartmentId) {
            $resident = DB::table('residents')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->first();

            $apartmentId = $resident ? $resident->apartment_id : null;

            if (! $apartmentId) {
                // Thử tìm trong bảng apartment_owners thông qua owner_user_id
                $ownedApt = DB::table('apartment_owners')
                    ->where('owner_user_id', $userId)
                    ->where('is_current_owner', 1)
                    ->first();
                $apartmentId = $ownedApt ? $ownedApt->apartment_id : null;
            }

            if (! $apartmentId) {
                // Thử tìm trong bảng apartments thông qua current_resident_user_id
                $curResApt = DB::table('apartments')
                    ->where('current_resident_user_id', $userId)
                    ->whereNull('deleted_at')
                    ->first();
                $apartmentId = $curResApt ? $curResApt->id : null;
            }

            if (! $apartmentId) {
                // Fallback căn hộ mặc định đầu tiên nếu chưa gán
                $firstApt = DB::table('apartments')->first();
                $apartmentId = $firstApt ? $firstApt->id : null;
            }
        } else {
            // Xác minh cư dân có quyền đối với căn hộ này
            $hasAccess = DB::table('residents')
                ->where('user_id', $userId)
                ->where('apartment_id', $apartmentId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->exists() ||
                DB::table('apartment_owners')
                    ->where('owner_user_id', $userId)
                    ->where('apartment_id', $apartmentId)
                    ->where('is_current_owner', 1)
                    ->exists() ||
                DB::table('apartments')
                    ->where('id', $apartmentId)
                    ->where('current_resident_user_id', $userId)
                    ->whereNull('deleted_at')
                    ->exists() ||
                (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN')));

            if (! $hasAccess) {
                // Tự động gán về căn hộ hợp lệ của cư dân
                $resident = DB::table('residents')
                    ->where('user_id', $userId)
                    ->where('is_active', 1)
                    ->whereNull('deleted_at')
                    ->first();
                if ($resident) {
                    $apartmentId = $resident->apartment_id;
                } else {
                    $ownedApt = DB::table('apartment_owners')
                        ->where('owner_user_id', $userId)
                        ->where('is_current_owner', 1)
                        ->first();
                    if ($ownedApt) {
                        $apartmentId = $ownedApt->apartment_id;
                    } else {
                        $curResApt = DB::table('apartments')
                            ->where('current_resident_user_id', $userId)
                            ->whereNull('deleted_at')
                            ->first();
                        if ($curResApt) {
                            $apartmentId = $curResApt->id;
                        }
                    }
                }
            }
        }

        if (! $apartmentId) {
            abort(422, 'Không thể xác định căn hộ tiếp đón cho tài khoản cư dân này.');
        }

        // 2. Chống tạo trùng lặp với cùng số điện thoại và thời gian dự kiến đến
        $expectedArrival = Carbon::parse($data['expected_arrival_time']);
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

        // Kiểm tra quyền sở hữu
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAdmin = method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'));
        if (! $isOwner && ! $isAdmin) {
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
            abort(422, 'Khách đã được tiếp nhận tại quầy lễ tân / bảo vệ, không thể chỉnh sửa thông tin.');
        }

        // Kiểm tra Concurrency / Cooldown theo phạm vi BẢN GHI (Record-level Cooldown)
        $recordCooldown = QuocTinRealtimeService::getVisitorRecordCooldown($id);
        if ($recordCooldown !== null && ($recordCooldown['actor_id'] ?? null) !== $user->id) {
            abort(409, 'Lượt khai báo này vừa được chỉnh sửa bởi một phiên khác. Vui lòng chờ trước khi thao tác tiếp.');
        }

        $expectedArrival = ! empty($data['expected_arrival_time'])
            ? Carbon::parse($data['expected_arrival_time'])
            : Carbon::parse($item->expected_arrival_time);

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

        // Kiểm tra quyền sở hữu
        $isOwner = $item->host_resident_user_id === $user->id;
        $isAdmin = method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'));
        if (! $isOwner && ! $isAdmin) {
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
}
