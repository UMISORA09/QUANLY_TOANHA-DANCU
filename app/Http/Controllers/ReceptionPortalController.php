<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReceptionPortalController extends Controller
{
    /**
     * Danh sách vai trò nhân viên hợp lệ được phép truy cập Tổng quan Lễ tân
     */
    protected const ALLOWED_STAFF_ROLES = [
        'ADMIN',
        'BUILDING_MANAGER',
        'MANAGER',
        'RECEPTIONIST',
        'SECURITY',
        'SECURITY_GUARD',
        'STAFF',
        'SUPER_ADMIN',
        'SUPER_ADMI',
    ];

    /**
     * Kiểm tra người dùng có quyền nhân viên lễ tân/bảo vệ/quản trị hay không.
     * Cư dân thông thường sẽ bị chặn với mã 403 Forbidden.
     */
    protected function assertStaffAuthorized(?object $user): void
    {
        if (! $user) {
            abort(401, 'Yêu cầu đăng nhập tài khoản nhân viên để truy cập Tổng quan Lễ tân.');
        }

        // 1. Quản trị viên cấp cao (SUPER_ADMIN / SUPER_ADMI)
        if (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) ||
            (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('SUPER_ADMI')))
        ) {
            return;
        }

        // Lấy danh sách role codes của người dùng
        $userRoles = [];
        if (method_exists($user, 'roles')) {
            $userRoles = $user->roles()->pluck('role_code')->map(fn ($r): string => strtoupper(trim((string) $r)))->toArray();
        }
        if (empty($userRoles) && ! empty($user->id)) {
            $userRoles = DB::table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $user->id)
                ->whereNull('roles.deleted_at')
                ->pluck('roles.role_code')
                ->map(fn ($r): string => strtoupper(trim((string) $r)))
                ->toArray();
        }

        if (in_array('SUPER_ADMIN', $userRoles, true) || in_array('SUPER_ADMI', $userRoles, true)) {
            return;
        }

        // 2. Bắt buộc kiểm tra nhóm vai trò nhân viên hợp lệ
        $isStaffRole = false;
        foreach (self::ALLOWED_STAFF_ROLES as $role) {
            if (in_array(strtoupper($role), $userRoles, true)) {
                $isStaffRole = true;
                break;
            }
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                $isStaffRole = true;
                break;
            }
        }

        // 3. Hoặc có quyền nhân viên liên quan (VISITOR:VIEW, PARCEL:VIEW, DASHBOARD:VIEW)
        $hasStaffPermission = RbacService::hasPermission($user, 'VISITOR:VIEW')
            || RbacService::hasPermission($user, 'PARCEL:VIEW')
            || RbacService::hasPermission($user, 'DASHBOARD:VIEW');

        if ($isStaffRole || $hasStaffPermission) {
            return;
        }

        abort(403, 'Bạn không có quyền truy cập dữ liệu Tổng quan Lễ tân & An ninh.');
    }

    /**
     * Lấy toàn bộ số liệu thống kê và hoạt động thời gian thực cho Cổng Lễ Tân & Bảo Vệ.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user() ?? RbacService::resolveUser($request);
        abort_unless($user, 401, 'Yêu cầu đăng nhập tài khoản nhân viên để truy cập Tổng quan Lễ tân.');
        $this->assertStaffAuthorized($user);

        try {
            // 1. Khách đang ở trong tòa (chưa check-out) - Dữ liệu thực từ database
            $activeGuestsCount = DB::table('visitor_checkin_logs')
                ->whereNull('checkout_time')
                ->count();

            // 2. Bưu phẩm chờ nhận & quá hạn (> 5 ngày) - Sargable query dùng idx_parcels_status
            $pendingParcelsCount = DB::table('parcels')
                ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
                ->count();

            $fiveDaysAgo = Carbon::now()->subDays(5);
            $threeDaysAgo = Carbon::now()->subDays(3);
            $overdueParcelsCount = DB::table('parcels')
                ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
                ->where('received_at', '<', $fiveDaysAgo)
                ->count();

            $expiringParcelsCount = DB::table('parcels')
                ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
                ->where('received_at', '<=', $threeDaysAgo)
                ->where('received_at', '>=', $fiveDaysAgo)
                ->count();

            // 3. Đăng ký xe chờ duyệt (is_active = 0 hoặc approved_by null)
            $pendingVehiclesCount = DB::table('vehicles')
                ->where(function ($q) {
                    $q->where('is_active', 0)->orWhereNull('approved_by');
                })
                ->count();

            // 4. Sự cố an ninh đang xử lý
            $activeIncidentsCount = 0;
            if (DB::getSchemaBuilder()->hasTable('security_incidents')) {
                $activeIncidentsCount = DB::table('security_incidents')
                    ->whereIn('status', ['INVESTIGATING', 'IN_PROGRESS'])
                    ->count();
            }

            // 5. Hoạt động gần nhất trong ca - Truy vấn thời gian thực từ database
            $activities = [];

            $latestGuest = DB::table('visitor_checkin_logs')
                ->orderBy('checkin_time', 'desc')
                ->first();
            if ($latestGuest) {
                $activities[] = [
                    'num' => sprintf('%02d', count($activities) + 1),
                    'title' => "Khách {$latestGuest->visitor_name} đã check-in",
                    'time' => Carbon::parse($latestGuest->checkin_time)->locale('vi')->diffForHumans(),
                    'type' => 'guest',
                ];
            }

            $latestParcel = DB::table('parcels')
                ->orderBy('received_at', 'desc')
                ->first();
            if ($latestParcel) {
                $activities[] = [
                    'num' => sprintf('%02d', count($activities) + 1),
                    'title' => "Bưu phẩm {$latestParcel->tracking_number} đã được tiếp nhận",
                    'time' => Carbon::parse($latestParcel->received_at)->locale('vi')->diffForHumans(),
                    'type' => 'parcel',
                ];
            }

            $latestVehicle = DB::table('vehicles')
                ->where(function ($q) {
                    $q->where('is_active', 0)->orWhereNull('approved_by');
                })
                ->orderBy('created_at', 'desc')
                ->first();
            if ($latestVehicle) {
                $activities[] = [
                    'num' => sprintf('%02d', count($activities) + 1),
                    'title' => "Xe {$latestVehicle->license_plate} chờ duyệt đăng ký",
                    'time' => Carbon::parse($latestVehicle->created_at)->locale('vi')->diffForHumans(),
                    'type' => 'vehicle',
                ];
            }

            // 6. Thông báo lễ tân - Tạo từ dữ liệu thực
            $notifications = [];
            if ($expiringParcelsCount > 0) {
                $notifications[] = [
                    'id' => 'alert-expiring-parcels',
                    'title' => 'Cảnh báo bưu phẩm gần hết hạn',
                    'message' => "Có {$expiringParcelsCount} kiện hàng lưu kho 3-5 ngày chưa nhận. Vui lòng gửi nhắc nhở cư dân.",
                    'timeAgo' => 'Cần xử lý ngay',
                    'category' => 'PARCEL',
                ];
            }

            if ($latestGuest) {
                $apt = DB::table('apartments')->where('id', $latestGuest->apartment_id)->value('apartment_number');
                $aptLabel = $apt ? " căn hộ {$apt}" : '';
                $notifications[] = [
                    'id' => 'guest-'.md5($latestGuest->visitor_name.$latestGuest->checkin_time),
                    'title' => 'Check-in khách',
                    'message' => "Khách {$latestGuest->visitor_name} vào thăm{$aptLabel}.",
                    'timeAgo' => Carbon::parse($latestGuest->checkin_time)->locale('vi')->diffForHumans(),
                    'category' => 'GUEST',
                ];
            }

            if ($latestParcel) {
                $notifications[] = [
                    'id' => 'parcel-'.md5($latestParcel->tracking_number.$latestParcel->received_at),
                    'title' => 'Bưu phẩm mới',
                    'message' => "Kiện hàng {$latestParcel->tracking_number} từ {$latestParcel->courier_company} đã tiếp nhận tại quầy.",
                    'timeAgo' => Carbon::parse($latestParcel->received_at)->locale('vi')->diffForHumans(),
                    'category' => 'PARCEL',
                ];
            }

            if (DB::getSchemaBuilder()->hasTable('security_incidents')) {
                $latestIncident = DB::table('security_incidents')
                    ->whereIn('status', ['INVESTIGATING', 'IN_PROGRESS'])
                    ->orderBy('incident_time', 'desc')
                    ->first();
                if ($latestIncident) {
                    $notifications[] = [
                        'id' => 'incident-'.($latestIncident->id ?? '1'),
                        'title' => 'Cảnh báo an ninh',
                        'message' => $latestIncident->title.($latestIncident->description ? ': '.$latestIncident->description : ''),
                        'timeAgo' => Carbon::parse($latestIncident->incident_time ?? now())->locale('vi')->diffForHumans(),
                        'category' => 'SECURITY',
                    ];
                }
            }

            return response()->json([
                'kpis' => [
                    'activeGuests' => $activeGuestsCount,
                    'activeGuestsNote' => 'Tất cả khu vực',
                    'pendingParcels' => $pendingParcelsCount,
                    'expiringParcels' => $expiringParcelsCount,
                    'overdueParcelsNote' => "{$overdueParcelsCount} bưu phẩm quá hạn",
                    'pendingVehicles' => $pendingVehiclesCount,
                    'pendingVehiclesNote' => 'Cần kiểm tra giấy tờ',
                    'securityIncidents' => sprintf('%02d', $activeIncidentsCount),
                    'securityIncidentsNote' => 'Đang được xử lý',
                ],
                'activities' => $activities,
                'notifications' => $notifications,
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            Log::error('ReceptionPortal overview error: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Lỗi máy chủ khi lấy dữ liệu Tổng quan Lễ tân.',
            ], 500);
        }
    }
}
