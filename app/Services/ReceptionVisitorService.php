<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceptionVisitorService
{
    /**
     * Danh sách vai trò được phép thao tác nghiệp vụ Lễ tân & An ninh
     */
    protected const ALLOWED_STAFF_ROLES = [
        'RECEPTIONIST',
        'SECURITY_GUARD',
        'BUILDING_MANAGER',
        'ADMIN',
        'SUPER_ADMIN',
        'SUPER_ADMI',
    ];

    /**
     * Kiểm tra quyền nhân viên lễ tân/bảo vệ/quản lý
     */
    public function assertStaffAuthorized(object $user, string $action = 'view'): void
    {
        if (! $user) {
            abort(401, 'Yêu cầu đăng nhập tài khoản nhân viên để thực hiện thao tác.');
        }

        // 1. Kiểm tra qua method hasRole nếu có trên User model
        if (method_exists($user, 'hasRole')) {
            foreach (self::ALLOWED_STAFF_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return;
                }
            }
        }

        // 2. Kiểm tra trực tiếp bảng user_roles join roles
        $userRoles = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.role_code')
            ->toArray();

        foreach (self::ALLOWED_STAFF_ROLES as $role) {
            if (in_array($role, $userRoles, true)) {
                return;
            }
        }

        // 3. Kiểm tra permission cụ thể
        $permCode = $action === 'checkin' ? 'VISITOR:CHECKIN' : 'VISITOR:VIEW';
        if (RbacService::hasPermission($user, $permCode)) {
            return;
        }

        abort(403, 'Bạn không có quyền thực hiện nghiệp vụ lễ tân/bảo vệ tra cứu & check-in khách.');
    }

    /**
     * Tra cứu và lấy danh sách khách viếng thăm kèm bộ lọc, tìm kiếm, phân trang và thống kê KPIs
     *
     * @param  object  $user  Tài khoản nhân viên lễ tân / bảo vệ
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listVisitors(object $user, array $filters = []): array
    {
        $this->assertStaffAuthorized($user, 'view');

        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // 1. Tính toán KPIs thời gian thực bằng 1 câu SELECT tổng hợp siêu tốc (1 single roundtrip)
        $kpiResult = DB::selectOne('
            SELECT 
                (SELECT COUNT(*) FROM visitor_registrations WHERE qr_pass_status = \'ACTIVE\' AND expected_arrival_time >= ? AND expected_arrival_time < ?) AS expected_today,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkout_time IS NULL) AS currently_inside,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkout_time >= ? AND checkout_time < ?) AS checked_out_today,
                (SELECT COUNT(*) FROM visitor_registrations) AS total_registrations
        ', [$today, $tomorrow, $today, $tomorrow]);

        $kpis = [
            'expected_today' => (int) ($kpiResult->expected_today ?? 0),
            'currently_inside' => (int) ($kpiResult->currently_inside ?? 0),
            'checked_out_today' => (int) ($kpiResult->checked_out_today ?? 0),
            'total_registrations' => (int) ($kpiResult->total_registrations ?? 0),
        ];

        // 2. Xây dựng truy vấn cơ sở: JOIN với bản ghi check-in mới nhất của mỗi registration (tránh nhân bản 1-N)
        $query = DB::table('visitor_registrations')
            ->leftJoin('visitor_checkin_logs as vcl', function ($join) {
                $join->on('vcl.id', '=', DB::raw('(SELECT vcl_sub.id FROM visitor_checkin_logs vcl_sub WHERE vcl_sub.registration_id = visitor_registrations.id ORDER BY vcl_sub.checkin_time DESC, vcl_sub.created_at DESC, vcl_sub.id DESC LIMIT 1)'));
            })
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->leftJoin('users as host_users', 'visitor_registrations.host_resident_user_id', '=', 'host_users.id')
            ->select([
                'visitor_registrations.id as registration_id',
                'visitor_registrations.registration_code',
                'visitor_registrations.qr_access_pass_code',
                'visitor_registrations.visitor_name',
                'visitor_registrations.visitor_phone',
                'visitor_registrations.visitor_national_id',
                'visitor_registrations.expected_arrival_time',
                'visitor_registrations.expected_departure_time',
                'visitor_registrations.visit_purpose',
                'visitor_registrations.visitor_count',
                'visitor_registrations.vehicle_license_plate',
                'visitor_registrations.qr_pass_status',
                'visitor_registrations.is_pre_approved_by_resident',
                'visitor_registrations.created_at as registration_created_at',
                'apartments.id as apartment_id',
                'apartments.apartment_number',
                'blocks.block_code',
                'blocks.block_name',
                'host_users.id as host_user_id',
                'host_users.full_name as host_name',
                'host_users.phone_number as host_phone',
                'vcl.id as checkin_log_id',
                'vcl.checkin_time as checkin_time',
                'vcl.checkout_time as checkout_time',
                'vcl.temp_card_id as temp_card_id',
                'vcl.assigned_elevator_access as assigned_elevator_access',
                'vcl.notes as notes',
                'vcl.receptionist_user_id as receptionist_user_id',
                'vcl.security_guard_user_id as security_guard_user_id',
            ]);

        // 3. Tìm kiếm từ khóa (Họ tên, SĐT, Mã khai báo, Mã QR Pass, CCCD, Biển số xe, Căn hộ, Tên chủ hộ)
        if (! empty($filters['search'])) {
            $keyword = trim($filters['search']);
            $query->where(function ($q) use ($keyword) {
                $q->where('visitor_registrations.visitor_name', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.visitor_phone', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.registration_code', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.qr_access_pass_code', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.visitor_national_id', 'like', "%{$keyword}%")
                    ->orWhere('visitor_registrations.vehicle_license_plate', 'like', "%{$keyword}%")
                    ->orWhere('apartments.apartment_number', 'like', "%{$keyword}%")
                    ->orWhere('host_users.full_name', 'like', "%{$keyword}%");
            });
        }

        // 4. Lọc theo trạng thái nghiệp vụ
        $statusFilter = strtoupper(trim($filters['status'] ?? 'ALL'));
        if ($statusFilter !== 'ALL' && $statusFilter !== '') {
            match ($statusFilter) {
                // Chờ check-in: Trạng thái ACTIVE và chưa có bản ghi checkin
                'EXPECTED', 'ACTIVE' => $query->where('visitor_registrations.qr_pass_status', 'ACTIVE')
                    ->whereNull('vcl.checkin_time'),

                // Đang ở trong tòa nhà: Đã checkin và checkout_time IS NULL
                'INSIDE', 'CHECKED_IN' => $query->whereNotNull('vcl.checkin_time')
                    ->whereNull('vcl.checkout_time'),

                // Đã check-out: checkout_time IS NOT NULL
                'CHECKED_OUT' => $query->whereNotNull('vcl.checkout_time'),

                // Đã hủy: qr_pass_status = CANCELLED
                'CANCELLED' => $query->where('visitor_registrations.qr_pass_status', 'CANCELLED'),

                // Hết hạn: qr_pass_status = EXPIRED
                'EXPIRED' => $query->where('visitor_registrations.qr_pass_status', 'EXPIRED'),

                default => null,
            };
        }

        // 5. Lọc theo khoảng ngày (expected_arrival_time)
        if (! empty($filters['from_date'])) {
            $query->where('visitor_registrations.expected_arrival_time', '>=', Carbon::parse($filters['from_date'])->startOfDay());
        }
        if (! empty($filters['to_date'])) {
            $query->where('visitor_registrations.expected_arrival_time', '<=', Carbon::parse($filters['to_date'])->endOfDay());
        }

        // 6. Lọc theo căn hộ cụ thể
        if (! empty($filters['apartment_id'])) {
            $query->where('visitor_registrations.apartment_id', $filters['apartment_id']);
        }

        // 7. Phân trang phía Server (Tách COUNT trước khi thêm ORDER BY để tối ưu O(1))
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['limit'] ?? $filters['per_page'] ?? 15)));

        $countQuery = (clone $query);
        $totalItems = $countQuery->count('visitor_registrations.id');
        $offset = ($page - 1) * $perPage;

        // 8. Sắp xếp: Ưu tiên lượt đang ở trong tòa nhà hoặc dự kiến đến hôm nay
        $query->orderByRaw('
            CASE 
                WHEN vcl.checkin_time IS NOT NULL AND vcl.checkout_time IS NULL THEN 1
                WHEN visitor_registrations.qr_pass_status = \'ACTIVE\' THEN 2
                WHEN vcl.checkout_time IS NOT NULL THEN 3
                ELSE 4
            END ASC
        ')->orderBy('visitor_registrations.expected_arrival_time', 'desc')->orderBy('visitor_registrations.id');

        $rows = $query->offset($offset)->limit($perPage)->get();

        // 9. Chuẩn hóa dữ liệu trả về với computed_status rõ ràng
        $items = $rows->map(function ($row) {
            $computedStatus = $this->resolveComputedStatus($row);

            return [
                'id' => $row->registration_id,
                'registration_id' => $row->registration_id,
                'registration_code' => $row->registration_code,
                'qr_access_pass_code' => $row->qr_access_pass_code,
                'visitor_name' => $row->visitor_name,
                'visitor_phone' => $row->visitor_phone,
                'visitor_national_id' => $row->visitor_national_id,
                'expected_arrival_time' => $row->expected_arrival_time,
                'expected_departure_time' => $row->expected_departure_time,
                'visit_purpose' => $row->visit_purpose,
                'visitor_count' => (int) ($row->visitor_count ?? 1),
                'vehicle_license_plate' => $row->vehicle_license_plate,
                'qr_pass_status' => $row->qr_pass_status,
                'is_pre_approved_by_resident' => (bool) $row->is_pre_approved_by_resident,
                'status' => $computedStatus['code'],
                'status_label' => $computedStatus['label'],
                'status_color' => $computedStatus['color'],
                'apartment' => [
                    'id' => $row->apartment_id,
                    'number' => $row->apartment_number,
                    'block_code' => $row->block_code,
                    'block_name' => $row->block_name,
                ],
                'host' => [
                    'id' => $row->host_user_id,
                    'name' => $row->host_name,
                    'phone' => $row->host_phone,
                ],
                'checkin_log' => $row->checkin_log_id ? [
                    'id' => $row->checkin_log_id,
                    'checkin_time' => $row->checkin_time,
                    'checkout_time' => $row->checkout_time,
                    'temp_card_id' => $row->temp_card_id,
                    'assigned_elevator_access' => $row->assigned_elevator_access,
                    'notes' => $row->notes,
                    'receptionist_user_id' => $row->receptionist_user_id,
                    'security_guard_user_id' => $row->security_guard_user_id,
                ] : null,
                'created_at' => $row->registration_created_at,
            ];
        });

        $totalPages = (int) ceil($totalItems / $perPage);

        return [
            'success' => true,
            'data' => $items,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages,
            ],
        ];
    }

    /**
     * Xác định trạng thái nghiệp vụ và nhãn hiển thị cho từng lượt khách
     */
    protected function resolveComputedStatus(object $row): array
    {
        // 1. Nếu đã có check-in log và chưa checkout
        if (! empty($row->checkin_time) && empty($row->checkout_time)) {
            return [
                'code' => 'INSIDE',
                'label' => 'Đang ở trong tòa nhà',
                'color' => 'blue',
            ];
        }

        // 2. Nếu đã checkout
        if (! empty($row->checkout_time)) {
            return [
                'code' => 'CHECKED_OUT',
                'label' => 'Đã check-out',
                'color' => 'emerald',
            ];
        }

        // 3. Nếu bị hủy
        if ($row->qr_pass_status === 'CANCELLED') {
            return [
                'code' => 'CANCELLED',
                'label' => 'Đã hủy',
                'color' => 'rose',
            ];
        }

        // 4. Nếu đã hết hạn
        if ($row->qr_pass_status === 'EXPIRED') {
            return [
                'code' => 'EXPIRED',
                'label' => 'Hết hiệu lực',
                'color' => 'gray',
            ];
        }

        // 5. Nếu thời gian dự kiến rời đi đã qua quá 24h mà chưa check-in
        if (! empty($row->expected_departure_time) && Carbon::parse($row->expected_departure_time)->addDay()->isPast()) {
            return [
                'code' => 'EXPIRED',
                'label' => 'Quá hạn dự kiến',
                'color' => 'amber',
            ];
        }

        // 6. Mặc định: Đã khai báo trước, chờ check-in
        return [
            'code' => 'EXPECTED',
            'label' => 'Chờ check-in',
            'color' => 'indigo',
        ];
    }

    /**
     * Tra cứu nhanh lượt khách bằng Mã QR Pass hoặc Mã đăng ký (Barcode / QR Scanner O(1) seek)
     *
     * @param  object  $user  Tài khoản nhân viên
     * @param  string  $code  Mã QR access pass hoặc mã đăng ký VR-XXXX
     * @return array<string, mixed>
     */
    public function lookup(object $user, string $code): array
    {
        $this->assertStaffAuthorized($user, 'view');

        $cleanCode = trim($code);
        if (empty($cleanCode)) {
            abort(422, 'Vui lòng cung cấp mã QR Pass Code hoặc Mã đăng ký để tra cứu.');
        }

        // 1. Tìm kiếm O(1) theo QR Access Pass Code hoặc Registration Code
        $registration = DB::table('visitor_registrations')
            ->leftJoin('apartments', 'visitor_registrations.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->leftJoin('users as host_users', 'visitor_registrations.host_resident_user_id', '=', 'host_users.id')
            ->where(function ($q) use ($cleanCode) {
                $q->where('visitor_registrations.qr_access_pass_code', $cleanCode)
                    ->orWhere('visitor_registrations.registration_code', $cleanCode)
                    ->orWhere('visitor_registrations.id', $cleanCode)
                    ->orWhere('visitor_registrations.visitor_phone', $cleanCode);
            })
            ->select([
                'visitor_registrations.*',
                'apartments.apartment_number',
                'blocks.block_code',
                'blocks.block_name',
                'host_users.full_name as host_name',
                'host_users.phone_number as host_phone',
            ])
            ->first();

        if (! $registration) {
            abort(404, "Không tìm thấy thông tin lượt khách với mã: {$cleanCode}. Vui lòng kiểm tra lại mã QR hoặc số điện thoại.");
        }

        // 2. Lấy log check-in mới nhất của lượt khách này
        $latestCheckin = DB::table('visitor_checkin_logs')
            ->where('registration_id', $registration->id)
            ->orderBy('checkin_time', 'desc')
            ->first();

        $checkinTime = $latestCheckin ? $latestCheckin->checkin_time : null;
        $checkoutTime = $latestCheckin ? $latestCheckin->checkout_time : null;

        $computedStatus = $this->resolveComputedStatus((object) [
            'qr_pass_status' => $registration->qr_pass_status,
            'expected_departure_time' => $registration->expected_departure_time,
            'checkin_time' => $checkinTime,
            'checkout_time' => $checkoutTime,
        ]);

        $canCheckin = ($computedStatus['code'] === 'EXPECTED' && $registration->qr_pass_status === 'ACTIVE');
        $canCheckout = ($computedStatus['code'] === 'INSIDE');

        return [
            'success' => true,
            'data' => [
                'registration' => [
                    'id' => $registration->id,
                    'registration_code' => $registration->registration_code,
                    'qr_access_pass_code' => $registration->qr_access_pass_code,
                    'visitor_name' => $registration->visitor_name,
                    'visitor_phone' => $registration->visitor_phone,
                    'visitor_national_id' => $registration->visitor_national_id,
                    'expected_arrival_time' => $registration->expected_arrival_time,
                    'expected_departure_time' => $registration->expected_departure_time,
                    'visit_purpose' => $registration->visit_purpose,
                    'visitor_count' => (int) ($registration->visitor_count ?? 1),
                    'vehicle_license_plate' => $registration->vehicle_license_plate,
                    'qr_pass_status' => $registration->qr_pass_status,
                    'is_pre_approved_by_resident' => (bool) $registration->is_pre_approved_by_resident,
                    'apartment' => [
                        'id' => $registration->apartment_id,
                        'number' => $registration->apartment_number,
                        'block_code' => $registration->block_code,
                        'block_name' => $registration->block_name,
                    ],
                    'host' => [
                        'name' => $registration->host_name,
                        'phone' => $registration->host_phone,
                    ],
                ],
                'status' => $computedStatus['code'],
                'status_label' => $computedStatus['label'],
                'status_color' => $computedStatus['color'],
                'can_checkin' => $canCheckin,
                'can_checkout' => $canCheckout,
                'latest_checkin_log' => $latestCheckin,
            ],
        ];
    }

    /**
     * Thực hiện Check-in khi khách đến tòa nhà (Bảo vệ đồng thời bằng DB Transaction & Row Locking)
     *
     * @param  object  $user  Tài khoản nhân viên lễ tân / bảo vệ
     * @param  array<string, mixed>  $data  Dữ liệu check-in (registration_id hoặc code, temp_card_id, notes, ...)
     * @return array<string, mixed>
     */
    public function checkIn(object $user, array $data): array
    {
        $this->assertStaffAuthorized($user, 'checkin');

        $registrationId = trim((string) ($data['registration_id'] ?? $data['id'] ?? ''));
        $qrCode = trim((string) ($data['qr_code'] ?? $data['code'] ?? ''));

        if (empty($registrationId) && empty($qrCode)) {
            abort(422, 'Vui lòng cung cấp registration_id hoặc mã QR để thực hiện check-in.');
        }

        $now = Carbon::now();
        $checkinLog = null;
        $registration = null;

        // Bọc toàn bộ kiểm tra trạng thái và ghi nhận trong DB Transaction với Row-Level Locking (lockForUpdate)
        DB::transaction(function () use ($registrationId, $qrCode, $user, $data, $now, &$checkinLog, &$registration) {
            // 1. Tìm bản ghi đăng ký với lockForUpdate chống race condition
            $query = DB::table('visitor_registrations')->lockForUpdate();
            if (! empty($registrationId)) {
                $query->where('id', $registrationId);
            } else {
                $query->where(function ($q) use ($qrCode) {
                    $q->where('qr_access_pass_code', $qrCode)
                        ->orWhere('registration_code', $qrCode);
                });
            }

            $registration = $query->first();

            if (! $registration) {
                abort(404, 'Không tìm thấy thông tin lượt khai báo khách để check-in.');
            }

            // 2. Kiểm tra nếu lượt khai báo đã bị hủy hoặc hết hạn
            if ($registration->qr_pass_status === 'CANCELLED') {
                abort(422, 'Lượt khai báo này đã bị hủy bởi cư dân, không thể check-in.');
            }

            if ($registration->qr_pass_status === 'EXPIRED') {
                abort(422, 'Lượt khai báo này đã hết hiệu lực, không thể check-in.');
            }

            // 3. Kiểm tra nếu khách đã check-in trước đó và chưa check-out (Ngăn check-in trùng với 409 Conflict)
            $existingActiveCheckin = DB::table('visitor_checkin_logs')
                ->where('registration_id', $registration->id)
                ->whereNull('checkout_time')
                ->lockForUpdate()
                ->first();

            if ($existingActiveCheckin) {
                abort(409, "Khách {$registration->visitor_name} đã check-in vào tòa nhà lúc {$existingActiveCheckin->checkin_time}. Không thể check-in trùng lặp.");
            }

            // 4. Nếu qr_pass_status đã là USED và có bản ghi check-in đã check-out
            if ($registration->qr_pass_status === 'USED') {
                $lastLog = DB::table('visitor_checkin_logs')
                    ->where('registration_id', $registration->id)
                    ->orderBy('checkin_time', 'desc')
                    ->first();

                if ($lastLog && ! empty($lastLog->checkout_time)) {
                    abort(409, "Lượt khai báo này đã được sử dụng và đã hoàn tất check-out lúc {$lastLog->checkout_time}.");
                }
            }

            // 5. Xác định vai trò nhân viên ghi nhận (Lễ tân hay Bảo vệ)
            $isGuard = false;
            if (method_exists($user, 'hasRole') && $user->hasRole('SECURITY_GUARD')) {
                $isGuard = true;
            } else {
                $isGuard = DB::table('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->where('user_roles.user_id', $user->id)
                    ->where('roles.role_code', 'SECURITY_GUARD')
                    ->exists();
            }

            $checkinLogId = (string) Str::uuid();
            $nationalId = ! empty($data['national_id_number']) ? trim($data['national_id_number']) : $registration->visitor_national_id;
            $notes = ! empty($data['notes']) ? trim($data['notes']) : 'Check-in thành công tại quầy tiếp đón';
            $tempCardId = ! empty($data['temp_card_id']) ? trim($data['temp_card_id']) : null;
            $elevatorAccess = ! empty($data['assigned_elevator_access'])
                ? (is_array($data['assigned_elevator_access']) ? json_encode($data['assigned_elevator_access']) : $data['assigned_elevator_access'])
                : json_encode([]);

            // 6. Ghi nhận thời gian Check-in bằng SERVER TIME vào bảng visitor_checkin_logs
            DB::table('visitor_checkin_logs')->insert([
                'id' => $checkinLogId,
                'registration_id' => $registration->id,
                'apartment_id' => $registration->apartment_id,
                'visitor_name' => $registration->visitor_name,
                'visitor_phone' => $registration->visitor_phone,
                'national_id_number' => $nationalId,
                'id_card_photo_url' => $data['id_card_photo_url'] ?? null,
                'ai_ocr_extracted_data' => ! empty($data['ai_ocr_extracted_data']) ? json_encode($data['ai_ocr_extracted_data']) : null,
                'checkin_time' => $now,
                'checkout_time' => null,
                'receptionist_user_id' => $isGuard ? null : $user->id,
                'security_guard_user_id' => $isGuard ? $user->id : null,
                'temp_card_id' => $tempCardId,
                'assigned_elevator_access' => $elevatorAccess,
                'notes' => $notes,
                'created_at' => $now,
            ]);

            // 7. Cập nhật trạng thái lượt khai báo qr_pass_status sang USED
            DB::table('visitor_registrations')
                ->where('id', $registration->id)
                ->update([
                    'qr_pass_status' => 'USED',
                    'updated_at' => $now,
                ]);

            $checkinLog = DB::table('visitor_checkin_logs')->where('id', $checkinLogId)->first();
        });

        // 8. Phát Realtime Event tới kênh quoc-tin.visitors SAU KHI DB ĐÃ COMMIT THÀNH CÔNG
        // triggerCooldown = false để không khóa quầy tiếp đón giữa các lượt khách độc lập!
        QuocTinRealtimeService::emit(
            'visitors',
            'visitor_checkin',
            'CHECKED_IN',
            $registration->id,
            [
                'registration_id' => $registration->id,
                'checkin_log_id' => $checkinLog->id,
                'visitor_name' => $registration->visitor_name,
                'visitor_phone' => $registration->visitor_phone,
                'apartment_id' => $registration->apartment_id,
                'checkin_time' => $now->toIso8601String(),
                'status' => 'INSIDE',
                'status_label' => 'Đang ở trong tòa nhà',
            ],
            $user->id,
            false // Không kích hoạt cooldown 120s toàn hệ thống
        );

        return [
            'success' => true,
            'message' => "Check-in thành công cho khách {$registration->visitor_name}.",
            'data' => [
                'registration_id' => $registration->id,
                'checkin_log_id' => $checkinLog->id,
                'visitor_name' => $registration->visitor_name,
                'checkin_time' => $now->toIso8601String(),
                'status' => 'INSIDE',
                'status_label' => 'Đang ở trong tòa nhà',
                'checkin_log' => $checkinLog,
            ],
        ];
    }

    /**
     * Thực hiện Check-out khi khách rời khỏi tòa nhà (Atomic Concurrency Protection)
     *
     * @param  object  $user  Tài khoản nhân viên lễ tân / bảo vệ
     * @param  array<string, mixed>  $data  Dữ liệu check-out (checkin_log_id hoặc registration_id, notes)
     * @return array<string, mixed>
     */
    public function checkOut(object $user, array $data): array
    {
        $this->assertStaffAuthorized($user, 'checkin');

        $checkinLogId = trim((string) ($data['checkin_log_id'] ?? $data['id'] ?? ''));
        $registrationId = trim((string) ($data['registration_id'] ?? ''));

        if (empty($checkinLogId) && empty($registrationId)) {
            abort(422, 'Vui lòng cung cấp checkin_log_id hoặc registration_id để thực hiện check-out.');
        }

        $now = Carbon::now();
        $updatedLog = null;
        $regId = null;

        // Bọc trong DB Transaction với Row-Level Locking chống race condition
        DB::transaction(function () use ($checkinLogId, $registrationId, $data, $now, &$updatedLog, &$regId) {
            $query = DB::table('visitor_checkin_logs')->lockForUpdate();

            if (! empty($checkinLogId)) {
                $query->where('id', $checkinLogId);
            } else {
                $query->where('registration_id', $registrationId)
                    ->orderBy('checkin_time', 'desc');
            }

            $log = $query->first();

            if (! $log) {
                abort(404, 'Không tìm thấy lượt check-in của khách này trong hệ thống.');
            }

            // Kiểm tra nếu khách đã được check-out trước đó (Ngăn check-out lặp lại với 409 Conflict)
            if (! empty($log->checkout_time)) {
                abort(409, "Khách {$log->visitor_name} đã được check-out trước đó vào lúc {$log->checkout_time}.");
            }

            $regId = $log->registration_id;
            $newNotes = $log->notes;
            if (! empty($data['notes'])) {
                $newNotes = trim($log->notes ? "{$log->notes} | Checkout: {$data['notes']}" : $data['notes']);
            }

            // Ghi nhận thời gian Check-out bằng SERVER TIME
            DB::table('visitor_checkin_logs')
                ->where('id', $log->id)
                ->update([
                    'checkout_time' => $now,
                    'notes' => $newNotes,
                ]);

            $updatedLog = DB::table('visitor_checkin_logs')->where('id', $log->id)->first();
        });

        // Phát Realtime Event CHECKED_OUT tới kênh quoc-tin.visitors SAU COMMIT
        QuocTinRealtimeService::emit(
            'visitors',
            'visitor_checkin',
            'CHECKED_OUT',
            $updatedLog->id,
            [
                'checkin_log_id' => $updatedLog->id,
                'registration_id' => $regId,
                'visitor_name' => $updatedLog->visitor_name,
                'checkout_time' => $now->toIso8601String(),
                'status' => 'CHECKED_OUT',
                'status_label' => 'Đã check-out',
            ],
            $user->id,
            false // Không kích hoạt cooldown 120s
        );

        return [
            'success' => true,
            'message' => "Check-out thành công cho khách {$updatedLog->visitor_name}.",
            'data' => [
                'checkin_log_id' => $updatedLog->id,
                'registration_id' => $regId,
                'visitor_name' => $updatedLog->visitor_name,
                'checkout_time' => $now->toIso8601String(),
                'status' => 'CHECKED_OUT',
                'status_label' => 'Đã check-out',
                'checkin_log' => $updatedLog,
            ],
        ];
    }
}
