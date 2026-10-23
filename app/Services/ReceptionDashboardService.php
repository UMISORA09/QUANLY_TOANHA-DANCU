<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReceptionDashboardService
{
    /**
     * Xác thực phân quyền truy cập Dashboard Lễ tân
     * Chỉ cho phép Lễ tân, Quản lý tòa nhà, An ninh bảo vệ và Quản trị viên
     *
     * @throws HttpException
     */
    public function authorizeViewer(?object $user): object
    {
        if (! $user) {
            throw new HttpException(401, 'Vui lòng đăng nhập để truy cập Dashboard Lễ tân.');
        }

        if (
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ||
            (method_exists($user, 'hasRole') && $user->hasRole([
                'SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN',
                'BUILDING_MANAGER', 'MANAGER',
                'RECEPTIONIST', 'STAFF',
                'SECURITY_GUARD', 'SECURITY',
            ]))
        ) {
            return $user;
        }

        throw new HttpException(403, 'Bạn không có quyền truy cập Dashboard Giám sát Lễ tân.');
    }

    /**
     * Chuẩn hóa và xác thực khoảng thời gian lọc (Filter Date Range)
     *
     * @param  array<string, mixed>  $filters
     * @return array{start: Carbon, end: Carbon, preset: string, is_single_day: bool}
     *
     * @throws ValidationException
     */
    public function resolveDateRange(array $filters): array
    {
        $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $preset = strtolower(trim((string) ($filters['preset'] ?? 'today')));

        $now = Carbon::now($timezone);
        $todayStart = Carbon::today($timezone)->startOfDay();
        $todayEnd = Carbon::today($timezone)->endOfDay();

        switch ($preset) {
            case 'last_7_days':
            case '7_days':
                $start = $todayStart->copy()->subDays(6)->startOfDay();
                $end = $todayEnd->copy();
                $normalizedPreset = 'last_7_days';
                break;

            case 'this_month':
            case 'month':
                $start = $now->copy()->startOfMonth()->startOfDay();
                $end = $now->copy()->endOfMonth()->endOfDay();
                $normalizedPreset = 'this_month';
                break;

            case 'custom':
                $startInput = $filters['start_date'] ?? null;
                $endInput = $filters['end_date'] ?? null;

                if (! $startInput || ! $endInput) {
                    throw ValidationException::withMessages([
                        'start_date' => ['Vui lòng cung cấp cả ngày bắt đầu và ngày kết thúc cho khoảng tùy chọn.'],
                    ]);
                }

                try {
                    $start = Carbon::createFromFormat('Y-m-d', trim((string) $startInput), $timezone)->startOfDay();
                    $end = Carbon::createFromFormat('Y-m-d', trim((string) $endInput), $timezone)->endOfDay();
                } catch (\Throwable) {
                    throw ValidationException::withMessages([
                        'start_date' => ['Định dạng ngày lọc không hợp lệ. Vui lòng sử dụng định dạng YYYY-MM-DD.'],
                    ]);
                }

                if ($start->greaterThan($end)) {
                    throw ValidationException::withMessages([
                        'start_date' => ['Ngày bắt đầu phải nhỏ hơn hoặc bằng ngày kết thúc.'],
                    ]);
                }

                if ($start->diffInDays($end) > 90) {
                    throw ValidationException::withMessages([
                        'end_date' => ['Khoảng thời gian thống kê không được vượt quá 90 ngày để đảm bảo hiệu năng.'],
                    ]);
                }

                $normalizedPreset = 'custom';
                break;

            case 'today':
            default:
                $start = $todayStart->copy();
                $end = $todayEnd->copy();
                $normalizedPreset = 'today';
                break;
        }

        $isSingleDay = $start->isSameDay($end);

        return [
            'start' => $start,
            'end' => $end,
            'preset' => $normalizedPreset,
            'is_single_day' => $isSingleDay,
        ];
    }

    /**
     * Lấy toàn bộ dữ liệu thống kê tổng hợp cho Dashboard Giám sát Lễ tân
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDashboardMetrics(array $filters): array
    {
        $range = $this->resolveDateRange($filters);
        $start = $range['start'];
        $end = $range['end'];
        $preset = $range['preset'];
        $isSingleDay = $range['is_single_day'];

        $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $now = Carbon::now($timezone);
        $todayStart = Carbon::today($timezone)->startOfDay();
        $todayEnd = Carbon::today($timezone)->endOfDay();

        // 1. Thống kê bưu phẩm bằng Conditional Aggregation
        $parcelKpis = $this->aggregateParcelKpis($now, $todayStart, $todayEnd, $start, $end);

        // 2. Thống kê khách viếng thăm bằng Single Roundtrip Aggregation
        $visitorKpis = $this->aggregateVisitorKpis($todayStart, $todayEnd, $start, $end);

        // 3. Phân nhóm bưu phẩm theo thời gian tồn đọng (Aging Tiers)
        $agingBreakdown = [
            'under_24h' => $parcelKpis['pending_under_24h'],
            'day_1_to_3' => $parcelKpis['pending_1_to_3_days'],
            'day_3_to_5' => $parcelKpis['pending_3_to_5_days'],
            'over_5_days' => $parcelKpis['pending_over_5_days'],
            'pending_total' => $parcelKpis['pending_total'],
        ];

        // 4. Biểu đồ xu hướng (Traffic Trend: Bưu phẩm và Lượt khách)
        $trafficTrend = $this->aggregateTrafficTrend($start, $end, $isSingleDay, $timezone);

        // 5. Phân bổ theo đơn vị vận chuyển
        $courierBreakdown = $this->aggregateCourierBreakdown();

        // 6. Phân bổ mục đích khách viếng thăm
        $purposeBreakdown = $this->aggregateVisitorPurposeBreakdown($start, $end);

        // 7. Danh sách tóm tắt: Bưu phẩm tồn đọng lâu nhất (giới hạn 6 kiện)
        $topPendingParcels = $this->getTopPendingParcels($now, 6);

        // 8. Danh sách tóm tắt: Khách hiện đang ở trong tòa nhà (giới hạn 6 lượt)
        $currentVisitorsInside = $this->getCurrentVisitorsInside($now, 6);

        return [
            'meta' => [
                'preset' => $preset,
                'range_preset' => $preset,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'from_date' => $start->toDateString(),
                'to_date' => $end->toDateString(),
                'start_datetime' => $start->toIso8601String(),
                'end_datetime' => $end->toIso8601String(),
                'is_single_day' => $isSingleDay,
                'timezone' => $timezone,
                'generated_at' => $now->toIso8601String(),
            ],
            'kpis' => [
                'parcels' => [
                    'pending_total' => $parcelKpis['pending_total'],
                    'pending_today' => $parcelKpis['pending_under_24h'],
                    'received_today' => $parcelKpis['received_today'],
                    'received_in_range' => $parcelKpis['received_period'],
                    'delivered_today' => $parcelKpis['delivered_today'],
                    'delivered_in_range' => $parcelKpis['delivered_period'],
                    'overdue_parcels' => $parcelKpis['pending_over_5_days'],
                    'aging' => [
                        'under_24h' => $parcelKpis['pending_under_24h'],
                        'days_1_to_3' => $parcelKpis['pending_1_to_3_days'],
                        'days_3_to_5' => $parcelKpis['pending_3_to_5_days'],
                        'over_5_days' => $parcelKpis['pending_over_5_days'],
                    ],
                ],
                'visitors' => [
                    'currently_inside' => $visitorKpis['currently_inside'],
                    'checkin_today' => $visitorKpis['checkin_today'],
                    'checkin_in_range' => $visitorKpis['checkin_period'],
                    'checkout_today' => $visitorKpis['checkout_today'],
                    'checkout_in_range' => $visitorKpis['checkout_period'],
                    'expected_today' => $visitorKpis['expected_today'],
                    'expected_in_range' => (int) ($visitorKpis['expected_today'] ?? 0),
                ],
            ],
            'traffic_trend' => [
                'granularity' => $isSingleDay ? 'hour' : 'day',
                'series' => $trafficTrend['points'] ?? [],
            ],
            'breakdowns' => [
                'carriers' => array_map(function ($c) {
                    return [
                        'carrier' => $c['courier'] ?? 'Khác',
                        'count' => $c['count'] ?? 0,
                    ];
                }, $courierBreakdown),
                'visitor_purposes' => $purposeBreakdown,
            ],
            'top_pending_parcels' => $topPendingParcels,
            'current_visitors_inside' => $currentVisitorsInside,
            'parcels' => [
                'kpis' => [
                    'pending_total' => $parcelKpis['pending_total'],
                    'received_period' => $parcelKpis['received_period'],
                    'delivered_period' => $parcelKpis['delivered_period'],
                    'received_today' => $parcelKpis['received_today'],
                    'delivered_today' => $parcelKpis['delivered_today'],
                    'overdue_total' => $parcelKpis['pending_over_5_days'],
                    'expiring_total' => $parcelKpis['pending_3_to_5_days'],
                ],
                'aging' => $agingBreakdown,
                'courier_breakdown' => $courierBreakdown,
                'top_pending' => $topPendingParcels,
            ],
            'visitors' => [
                'kpis' => [
                    'currently_inside' => $visitorKpis['currently_inside'],
                    'checkin_period' => $visitorKpis['checkin_period'],
                    'checkout_period' => $visitorKpis['checkout_period'],
                    'checkin_today' => $visitorKpis['checkin_today'],
                    'checkout_today' => $visitorKpis['checkout_today'],
                    'expected_today' => $visitorKpis['expected_today'],
                ],
                'purpose_breakdown' => $purposeBreakdown,
                'current_inside_list' => $currentVisitorsInside,
            ],
            'trends' => $trafficTrend,
        ];
    }

    /**
     * Truy vấn tổng hợp số liệu bưu phẩm bằng một câu lệnh Conditional Aggregation
     *
     * @return array<string, int>
     */
    protected function aggregateParcelKpis(
        Carbon $now,
        Carbon $todayStart,
        Carbon $todayEnd,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        $oneDayAgo = $now->copy()->subHours(24);
        $threeDaysAgo = $now->copy()->subDays(3);
        $fiveDaysAgo = $now->copy()->subDays(5);

        $row = DB::table('parcels')
            ->selectRaw("
                COUNT(*) AS total_records,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') THEN 1 ELSE 0 END) AS pending_total,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') AND received_at >= ? THEN 1 ELSE 0 END) AS pending_under_24h,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') AND received_at < ? AND received_at >= ? THEN 1 ELSE 0 END) AS pending_1_to_3_days,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') AND received_at < ? AND received_at >= ? THEN 1 ELSE 0 END) AS pending_3_to_5_days,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') AND received_at < ? THEN 1 ELSE 0 END) AS pending_over_5_days,
                SUM(CASE WHEN received_at >= ? AND received_at <= ? THEN 1 ELSE 0 END) AS received_period,
                SUM(CASE WHEN status = 'DELIVERED' AND collected_at >= ? AND collected_at <= ? THEN 1 ELSE 0 END) AS delivered_period,
                SUM(CASE WHEN received_at >= ? AND received_at <= ? THEN 1 ELSE 0 END) AS received_today,
                SUM(CASE WHEN status = 'DELIVERED' AND collected_at >= ? AND collected_at <= ? THEN 1 ELSE 0 END) AS delivered_today
            ", [
                $oneDayAgo,
                $oneDayAgo, $threeDaysAgo,
                $threeDaysAgo, $fiveDaysAgo,
                $fiveDaysAgo,
                $periodStart, $periodEnd,
                $periodStart, $periodEnd,
                $todayStart, $todayEnd,
                $todayStart, $todayEnd,
            ])
            ->first();

        return [
            'total_records' => (int) ($row?->total_records ?? 0),
            'pending_total' => (int) ($row?->pending_total ?? 0),
            'pending_under_24h' => (int) ($row?->pending_under_24h ?? 0),
            'pending_1_to_3_days' => (int) ($row?->pending_1_to_3_days ?? 0),
            'pending_3_to_5_days' => (int) ($row?->pending_3_to_5_days ?? 0),
            'pending_over_5_days' => (int) ($row?->pending_over_5_days ?? 0),
            'received_period' => (int) ($row?->received_period ?? 0),
            'delivered_period' => (int) ($row?->delivered_period ?? 0),
            'received_today' => (int) ($row?->received_today ?? 0),
            'delivered_today' => (int) ($row?->delivered_today ?? 0),
        ];
    }

    /**
     * Truy vấn tổng hợp số liệu khách viếng thăm bằng Single Roundtrip Aggregation
     *
     * @return array<string, int>
     */
    protected function aggregateVisitorKpis(
        Carbon $todayStart,
        Carbon $todayEnd,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        $row = DB::selectOne('
            SELECT
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkout_time IS NULL) AS currently_inside,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkin_time >= ? AND checkin_time <= ?) AS checkin_period,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkout_time IS NOT NULL AND checkout_time >= ? AND checkout_time <= ?) AS checkout_period,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkin_time >= ? AND checkin_time <= ?) AS checkin_today,
                (SELECT COUNT(*) FROM visitor_checkin_logs WHERE checkout_time IS NOT NULL AND checkout_time >= ? AND checkout_time <= ?) AS checkout_today,
                (SELECT COUNT(*) FROM visitor_registrations WHERE qr_pass_status = \'ACTIVE\' AND expected_arrival_time >= ? AND expected_arrival_time <= ?) AS expected_today
        ', [
            $periodStart, $periodEnd,
            $periodStart, $periodEnd,
            $todayStart, $todayEnd,
            $todayStart, $todayEnd,
            $todayStart, $todayEnd,
        ]);

        return [
            'currently_inside' => (int) ($row?->currently_inside ?? 0),
            'checkin_period' => (int) ($row?->checkin_period ?? 0),
            'checkout_period' => (int) ($row?->checkout_period ?? 0),
            'checkin_today' => (int) ($row?->checkin_today ?? 0),
            'checkout_today' => (int) ($row?->checkout_today ?? 0),
            'expected_today' => (int) ($row?->expected_today ?? 0),
        ];
    }

    /**
     * Truy vấn phân bổ xu hướng lưu lượng bưu phẩm & khách theo ngày hoặc theo khung giờ
     *
     * @return array<string, mixed>
     */
    protected function aggregateTrafficTrend(
        Carbon $start,
        Carbon $end,
        bool $isSingleDay,
        string $timezone
    ): array {
        $driver = DB::getDriverName();

        // 1. Trường hợp thống kê 1 ngày (Hôm nay / Single Day): Phân tích theo khung giờ
        if ($isSingleDay) {
            $hourExpr = $driver === 'sqlite'
                ? "CAST(strftime('%H', checkin_time) AS INTEGER)"
                : 'HOUR(checkin_time)';

            $visitorCheckinsByHour = DB::table('visitor_checkin_logs')
                ->where('checkin_time', '>=', $start)
                ->where('checkin_time', '<=', $end)
                ->selectRaw("{$hourExpr} as hour, COUNT(*) as count")
                ->groupBy(DB::raw($hourExpr))
                ->pluck('count', 'hour')
                ->all();

            $hourCheckoutExpr = $driver === 'sqlite'
                ? "CAST(strftime('%H', checkout_time) AS INTEGER)"
                : 'HOUR(checkout_time)';

            $visitorCheckoutsByHour = DB::table('visitor_checkin_logs')
                ->whereNotNull('checkout_time')
                ->where('checkout_time', '>=', $start)
                ->where('checkout_time', '<=', $end)
                ->selectRaw("{$hourCheckoutExpr} as hour, COUNT(*) as count")
                ->groupBy(DB::raw($hourCheckoutExpr))
                ->pluck('count', 'hour')
                ->all();

            $parcelRecvHourExpr = $driver === 'sqlite'
                ? "CAST(strftime('%H', received_at) AS INTEGER)"
                : 'HOUR(received_at)';

            $parcelsRecvByHour = DB::table('parcels')
                ->where('received_at', '>=', $start)
                ->where('received_at', '<=', $end)
                ->selectRaw("{$parcelRecvHourExpr} as hour, COUNT(*) as count")
                ->groupBy(DB::raw($parcelRecvHourExpr))
                ->pluck('count', 'hour')
                ->all();

            $parcelDelivHourExpr = $driver === 'sqlite'
                ? "CAST(strftime('%H', collected_at) AS INTEGER)"
                : 'HOUR(collected_at)';

            $parcelsDelivByHour = DB::table('parcels')
                ->where('status', 'DELIVERED')
                ->whereNotNull('collected_at')
                ->where('collected_at', '>=', $start)
                ->where('collected_at', '<=', $end)
                ->selectRaw("{$parcelDelivHourExpr} as hour, COUNT(*) as count")
                ->groupBy(DB::raw($parcelDelivHourExpr))
                ->pluck('count', 'hour')
                ->all();

            // Khởi tạo các khung giờ chuẩn từ 06:00 đến 22:00
            $points = [];
            for ($h = 6; $h <= 22; $h += 2) {
                $label = sprintf('%02d:00 - %02d:00', $h, $h + 2);
                $cIn = ($visitorCheckinsByHour[$h] ?? 0) + ($visitorCheckinsByHour[$h + 1] ?? 0);
                $cOut = ($visitorCheckoutsByHour[$h] ?? 0) + ($visitorCheckoutsByHour[$h + 1] ?? 0);
                $pRecv = ($parcelsRecvByHour[$h] ?? 0) + ($parcelsRecvByHour[$h + 1] ?? 0);
                $pDeliv = ($parcelsDelivByHour[$h] ?? 0) + ($parcelsDelivByHour[$h + 1] ?? 0);

                $points[] = [
                    'key' => sprintf('%02d', $h),
                    'label' => $label,
                    'visitor_checkin' => (int) $cIn,
                    'visitor_checkout' => (int) $cOut,
                    'parcel_received' => (int) $pRecv,
                    'parcel_delivered' => (int) $pDeliv,
                ];
            }

            return [
                'type' => 'hourly',
                'points' => $points,
            ];
        }

        // 2. Trường hợp thống kê theo nhiều ngày (7 ngày gần nhất, Tháng này, Custom): Phân tích theo từng ngày
        $dateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', checkin_time)"
            : 'DATE(checkin_time)';

        $checkinsByDate = DB::table('visitor_checkin_logs')
            ->where('checkin_time', '>=', $start)
            ->where('checkin_time', '<=', $end)
            ->selectRaw("{$dateExpr} as date_key, COUNT(*) as count")
            ->groupBy(DB::raw($dateExpr))
            ->pluck('count', 'date_key')
            ->all();

        $checkoutDateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', checkout_time)"
            : 'DATE(checkout_time)';

        $checkoutsByDate = DB::table('visitor_checkin_logs')
            ->whereNotNull('checkout_time')
            ->where('checkout_time', '>=', $start)
            ->where('checkout_time', '<=', $end)
            ->selectRaw("{$checkoutDateExpr} as date_key, COUNT(*) as count")
            ->groupBy(DB::raw($checkoutDateExpr))
            ->pluck('count', 'date_key')
            ->all();

        $parcelRecvDateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', received_at)"
            : 'DATE(received_at)';

        $parcelsRecvByDate = DB::table('parcels')
            ->where('received_at', '>=', $start)
            ->where('received_at', '<=', $end)
            ->selectRaw("{$parcelRecvDateExpr} as date_key, COUNT(*) as count")
            ->groupBy(DB::raw($parcelRecvDateExpr))
            ->pluck('count', 'date_key')
            ->all();

        $parcelDelivDateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', collected_at)"
            : 'DATE(collected_at)';

        $parcelsDelivByDate = DB::table('parcels')
            ->where('status', 'DELIVERED')
            ->whereNotNull('collected_at')
            ->where('collected_at', '>=', $start)
            ->where('collected_at', '<=', $end)
            ->selectRaw("{$parcelDelivDateExpr} as date_key, COUNT(*) as count")
            ->groupBy(DB::raw($parcelDelivDateExpr))
            ->pluck('count', 'date_key')
            ->all();

        // Sinh danh sách liên tục các ngày từ start đến end
        $points = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m-d');
            $label = $cursor->format('d/m');

            $points[] = [
                'key' => $key,
                'label' => $label,
                'date' => $key,
                'visitor_checkin' => (int) ($checkinsByDate[$key] ?? 0),
                'visitor_checkout' => (int) ($checkoutsByDate[$key] ?? 0),
                'parcel_received' => (int) ($parcelsRecvByDate[$key] ?? 0),
                'parcel_delivered' => (int) ($parcelsDelivByDate[$key] ?? 0),
            ];

            $cursor->addDay();
        }

        return [
            'type' => 'daily',
            'points' => $points,
        ];
    }

    /**
     * Phân bổ bưu phẩm đang chờ nhận theo đơn vị vận chuyển
     *
     * @return array<int, array{courier: string, count: int, percentage: float}>
     */
    protected function aggregateCourierBreakdown(): array
    {
        $rows = DB::table('parcels')
            ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
            ->select('courier_company', DB::raw('COUNT(*) as total'))
            ->groupBy('courier_company')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $totalPending = $rows->sum('total');

        return $rows->map(function ($r) use ($totalPending) {
            $count = (int) $r->total;
            $percent = $totalPending > 0 ? round(($count / $totalPending) * 100, 1) : 0.0;

            return [
                'courier' => (string) ($r->courier_company ?: 'Khác'),
                'count' => $count,
                'percentage' => $percent,
            ];
        })->toArray();
    }

    /**
     * Phân bổ lượt khách theo mục đích thăm
     *
     * @return array<int, array{purpose: string, count: int, percentage: float}>
     */
    protected function aggregateVisitorPurposeBreakdown(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('visitor_checkin_logs as vcl')
            ->leftJoin('visitor_registrations as vr', 'vcl.registration_id', '=', 'vr.id')
            ->where('vcl.checkin_time', '>=', $start)
            ->where('vcl.checkin_time', '<=', $end)
            ->select(DB::raw("COALESCE(vr.visit_purpose, 'Tham quan / Gặp gỡ') as purpose"), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw("COALESCE(vr.visit_purpose, 'Tham quan / Gặp gỡ')"))
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $totalVisits = $rows->sum('total');

        return $rows->map(function ($r) use ($totalVisits) {
            $count = (int) $r->total;
            $percent = $totalVisits > 0 ? round(($count / $totalVisits) * 100, 1) : 0.0;

            return [
                'purpose' => (string) $r->purpose,
                'count' => $count,
                'percentage' => $percent,
            ];
        })->toArray();
    }

    /**
     * Danh sách tóm tắt bưu phẩm tồn đọng lâu nhất cần xử lý gấp
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTopPendingParcels(Carbon $now, int $limit = 6): array
    {
        $parcels = DB::table('parcels as p')
            ->leftJoin('apartments as a', 'p.apartment_id', '=', 'a.id')
            ->leftJoin('blocks as b', 'a.block_id', '=', 'b.id')
            ->whereIn('p.status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
            ->select([
                'p.id',
                'p.tracking_number',
                'p.courier_company',
                'p.recipient_name',
                'p.recipient_phone',
                'p.stored_location_type',
                'p.pickup_pin_code',
                'p.received_at',
                'p.status',
                'a.apartment_number',
                'b.block_code',
            ])
            ->orderBy('p.received_at', 'asc')
            ->limit($limit)
            ->get();

        return $parcels->map(function ($p) use ($now) {
            $recv = Carbon::parse($p->received_at);
            $hoursWaiting = max(0, (int) round($now->diffInHours($recv)));
            $daysWaiting = max(0, (int) floor($hoursWaiting / 24));

            return [
                'id' => (string) $p->id,
                'tracking_number' => (string) $p->tracking_number,
                'courier_company' => (string) $p->courier_company,
                'recipient_name' => (string) $p->recipient_name,
                'recipient_phone_masked' => $this->maskPhoneNumber((string) $p->recipient_phone),
                'apartment_number' => (string) ($p->apartment_number ?? 'N/A'),
                'block_code' => (string) ($p->block_code ?? ''),
                'stored_location_type' => (string) $p->stored_location_type,
                'pickup_pin_code' => (string) $p->pickup_pin_code,
                'received_at' => $recv->toIso8601String(),
                'received_formatted' => $recv->format('H:i d/m/Y'),
                'waiting_hours' => $hoursWaiting,
                'waiting_days' => $daysWaiting,
                'is_overdue' => $daysWaiting >= 5,
                'is_expiring' => $daysWaiting >= 3 && $daysWaiting < 5,
            ];
        })->toArray();
    }

    /**
     * Danh sách tóm tắt khách hiện đang ở trong tòa nhà
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getCurrentVisitorsInside(Carbon $now, int $limit = 6): array
    {
        $logs = DB::table('visitor_checkin_logs as vcl')
            ->leftJoin('apartments as a', 'vcl.apartment_id', '=', 'a.id')
            ->leftJoin('blocks as b', 'a.block_id', '=', 'b.id')
            ->leftJoin('visitor_registrations as vr', 'vcl.registration_id', '=', 'vr.id')
            ->whereNull('vcl.checkout_time')
            ->select([
                'vcl.id',
                'vcl.visitor_name',
                'vcl.visitor_phone',
                'vcl.checkin_time',
                'vcl.temp_card_id',
                'vcl.assigned_elevator_access',
                'a.apartment_number',
                'b.block_code',
                'vr.visit_purpose',
                'vr.registration_code',
            ])
            ->orderBy('vcl.checkin_time', 'asc')
            ->limit($limit)
            ->get();

        return $logs->map(function ($l) use ($now) {
            $cin = Carbon::parse($l->checkin_time);
            $minutesInside = max(0, (int) round($now->diffInMinutes($cin)));
            $hoursInside = round($minutesInside / 60, 1);

            return [
                'id' => (string) $l->id,
                'visitor_name' => (string) $l->visitor_name,
                'visitor_phone_masked' => $this->maskPhoneNumber((string) $l->visitor_phone),
                'apartment_number' => (string) ($l->apartment_number ?? 'N/A'),
                'block_code' => (string) ($l->block_code ?? ''),
                'visit_purpose' => (string) ($l->visit_purpose ?: 'Thăm căn hộ'),
                'registration_code' => (string) ($l->registration_code ?: 'Vãng lai'),
                'checkin_time' => $cin->toIso8601String(),
                'checkin_formatted' => $cin->format('H:i d/m/Y'),
                'duration_minutes' => $minutesInside,
                'duration_hours' => $hoursInside,
                'temp_card_assigned' => ! empty($l->temp_card_id),
            ];
        })->toArray();
    }

    /**
     * Ẩn một phần số điện thoại để bảo vệ quyền riêng tư cá nhân (PII Protection)
     */
    protected function maskPhoneNumber(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) >= 9) {
            return substr($clean, 0, 3).'****'.substr($clean, -3);
        }

        return $phone;
    }
}
