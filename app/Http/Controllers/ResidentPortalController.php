<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidentPortalController extends Controller
{
    /**
     * Lấy toàn bộ dữ liệu tổng quan cho Cổng Cư Dân của người dùng đang đăng nhập hoặc tài khoản mặc định.
     */
    public function overview(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        // Tìm user theo id hoặc lấy tài khoản cư dân mẫu Nguyễn Văn An
        $user = null;
        if (! empty($userId)) {
            $user = DB::table('users')->where('id', $userId)->first();
        }

        if (! $user) {
            $user = DB::table('users')
                ->where('email', 'nguyenvanan@cassavas.vn')
                ->orWhere('username', 'nguyenvanan')
                ->orWhere('phone_number', '0901234567')
                ->first();
        }

        if (! $user) {
            $user = DB::table('users')
                ->where('email', 'nguyenvana@cassavas.vn')
                ->orWhere('username', 'nguyenvana')
                ->first();
        }

        if (! $user) {
            return response()->json([
                'detail' => 'Không tìm thấy dữ liệu cư dân.',
            ], 404);
        }

        // 1. Căn hộ của cư dân
        $residentRecord = DB::table('residents')
            ->where('user_id', $user->id)
            ->where('is_active', 1)
            ->first();

        $apartmentId = $residentRecord ? $residentRecord->apartment_id : null;

        $apartment = null;
        if ($apartmentId) {
            $apartment = DB::table('apartments')
                ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
                ->where('apartments.id', $apartmentId)
                ->select(
                    'apartments.*',
                    'blocks.block_name',
                    'blocks.block_code',
                    'blocks.hotline_phone'
                )
                ->first();
        }

        if (! $apartment) {
            $apartment = DB::table('apartments')
                ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
                ->where('apartments.apartment_number', 'A1-05')
                ->select(
                    'apartments.*',
                    'blocks.block_name',
                    'blocks.block_code',
                    'blocks.hotline_phone'
                )
                ->first();
            if ($apartment) {
                $apartmentId = $apartment->id;
            }
        }

        // 2. Danh sách Hóa đơn
        $invoicesQuery = DB::table('invoices');
        if ($apartmentId) {
            $invoicesQuery->where('apartment_id', $apartmentId);
        } else {
            $invoicesQuery->where('resident_user_id', $user->id);
        }
        $invoices = $invoicesQuery
            ->orderBy('due_date', 'desc')
            ->get();

        // 3. Danh sách Sự cố & Phản ánh (Tickets)
        $ticketsQuery = DB::table('tickets')
            ->leftJoin('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id');
        if ($apartmentId) {
            $ticketsQuery->where(function ($q) use ($apartmentId, $user) {
                $q->where('tickets.apartment_id', $apartmentId)
                    ->orWhere('tickets.creator_user_id', $user->id);
            });
        } else {
            $ticketsQuery->where('tickets.creator_user_id', $user->id);
        }
        $tickets = $ticketsQuery
            ->select('tickets.*', 'ticket_categories.category_name', 'ticket_categories.category_code')
            ->orderBy('tickets.created_at', 'desc')
            ->get();

        // 4. Danh sách Lịch đặt tiện ích (Amenity Bookings)
        $bookingsQuery = DB::table('amenity_bookings')
            ->leftJoin('amenities', 'amenity_bookings.amenity_id', '=', 'amenities.id');
        if ($apartmentId) {
            $bookingsQuery->where(function ($q) use ($apartmentId, $user) {
                $q->where('amenity_bookings.apartment_id', $apartmentId)
                    ->orWhere('amenity_bookings.resident_user_id', $user->id);
            });
        } else {
            $bookingsQuery->where('amenity_bookings.resident_user_id', $user->id);
        }
        $bookings = $bookingsQuery
            ->select('amenity_bookings.*', 'amenities.amenity_name', 'amenities.location_detail', 'amenities.amenity_code')
            ->orderBy('amenity_bookings.booking_date', 'desc')
            ->orderBy('amenity_bookings.start_time', 'asc')
            ->get();

        // 5. Danh sách Khách đăng ký ra vào (Visitors)
        $visitorsQuery = DB::table('visitor_registrations');
        if ($apartmentId) {
            $visitorsQuery->where(function ($q) use ($apartmentId, $user) {
                $q->where('apartment_id', $apartmentId)
                    ->orWhere('host_resident_user_id', $user->id);
            });
        } else {
            $visitorsQuery->where('host_resident_user_id', $user->id);
        }
        $visitors = $visitorsQuery
            ->orderBy('expected_arrival_time', 'desc')
            ->get();

        // 6. Danh sách các Tiện ích khả dụng để cư dân có thể đặt
        $availableAmenities = DB::table('amenities')
            ->where('is_active', 1)
            ->select('id', 'amenity_name', 'amenity_code', 'location_detail', 'max_capacity_per_slot', 'hourly_rate')
            ->get();

        // 7. Danh mục Ticket
        $ticketCategories = DB::table('ticket_categories')
            ->select('id', 'category_name', 'category_code')
            ->get();

        // Thống kê nhanh
        $unpaidInvoices = $invoices->whereIn('status', ['ISSUED', 'OVERDUE']);
        $totalDebt = $unpaidInvoices->sum('remaining_balance');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'national_id_number' => $user->national_id_number,
                'status' => $user->status,
                'resident_type' => $residentRecord ? $residentRecord->resident_type : 'OWNER',
                'is_head_of_household' => $residentRecord ? (bool) $residentRecord->is_head_of_household : true,
                'stay_start_date' => $residentRecord ? $residentRecord->stay_start_date : '2025-01-15',
                'avatar_url' => null,
            ],
            'apartment' => $apartment ? [
                'id' => $apartment->id,
                'apartment_number' => $apartment->apartment_number,
                'block_name' => $apartment->block_name ?? 'Khu A - Tòa Nhà Ruby',
                'block_code' => $apartment->block_code ?? 'BLOCK_A',
                'room_type' => $apartment->room_type ?? '2_BEDROOM',
                'gross_floor_area_sqm' => (float) ($apartment->gross_floor_area_sqm ?? 85.50),
                'net_usable_area_sqm' => (float) ($apartment->net_usable_area_sqm ?? 80.20),
                'bedroom_count' => (int) ($apartment->bedroom_count ?? 2),
                'bathroom_count' => (int) ($apartment->bathroom_count ?? 2),
                'hotline_phone' => $apartment->hotline_phone ?? '024 3999 1111',
            ] : null,
            'kpis' => [
                'unpaid_invoices_count' => $unpaidInvoices->count(),
                'total_debt' => (float) $totalDebt,
                'active_tickets_count' => $tickets->whereIn('status', ['NEW', 'IN_PROGRESS', 'PROCESSING', 'RECEIVED'])->count(),
                'upcoming_bookings_count' => $bookings->where('status', 'CONFIRMED')->count(),
                'active_visitors_count' => $visitors->where('qr_pass_status', 'ACTIVE')->count(),
            ],
            'invoices' => $invoices,
            'tickets' => $tickets,
            'bookings' => $bookings,
            'visitors' => $visitors,
            'available_amenities' => $availableAmenities,
            'ticket_categories' => $ticketCategories,
        ]);
    }

    /**
     * Trích xuất dữ liệu đầu vào linh hoạt từ JSON hoặc form-data.
     */
    private function getRequestData(Request $request): array
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $raw = $request->getContent();
            $body = json_decode($raw, true) ?? [];
        }

        return array_merge($body, $request->all());
    }

    /**
     * Tạo yêu cầu phản ánh / sửa chữa kỹ thuật mới từ Cư Dân.
     */
    public function createTicket(Request $request): JsonResponse
    {
        $request->merge($this->getRequestData($request));

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'nullable|string',
            'priority' => 'nullable|string|in:LOW,MEDIUM,HIGH,URGENT',
            'preferred_service_time' => 'nullable|date',
            'apartment_id' => 'nullable|string',
            'user_id' => 'nullable|string',
        ]);

        $userId = $validated['user_id'] ?? $request->input('user_id');
        if (! $userId) {
            $user = DB::table('users')->where('email', 'nguyenvanan@cassavas.vn')->first();
            $userId = $user ? $user->id : (string) Str::uuid();
        }

        $apartmentId = $validated['apartment_id'] ?? null;
        if (! $apartmentId) {
            $resident = DB::table('residents')->where('user_id', $userId)->first();
            $apartmentId = $resident ? $resident->apartment_id : null;
            if (! $apartmentId) {
                $apt = DB::table('apartments')->where('apartment_number', 'A1-05')->first();
                $apartmentId = $apt ? $apt->id : (string) Str::uuid();
            }
        }

        $categoryId = $validated['category_id'] ?? null;
        if (! $categoryId) {
            $cat = DB::table('ticket_categories')->first();
            $categoryId = $cat ? $cat->id : (string) Str::uuid();
        }

        $ticketId = (string) Str::uuid();
        $ticketNumber = 'TICKET-'.rand(1100, 9999);
        $now = Carbon::now();

        DB::table('tickets')->insert([
            'id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'category_id' => $categoryId,
            'apartment_id' => $apartmentId,
            'creator_user_id' => $userId,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'priority' => $validated['priority'] ?? 'MEDIUM',
            'status' => 'NEW',
            'preferred_service_time' => ! empty($validated['preferred_service_time']) ? Carbon::parse($validated['preferred_service_time']) : null,
            'sla_deadline' => $now->copy()->addHours(24),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $newTicket = DB::table('tickets')
            ->leftJoin('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id')
            ->where('tickets.id', $ticketId)
            ->select('tickets.*', 'ticket_categories.category_name', 'ticket_categories.category_code')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Gửi yêu cầu hỗ trợ kỹ thuật thành công!',
            'ticket' => $newTicket,
        ], 201);
    }

    /**
     * Đặt lịch sử dụng tiện ích (cầu lông, gym, bbq...).
     */
    public function createAmenityBooking(Request $request): JsonResponse
    {
        $request->merge($this->getRequestData($request));

        $validated = $request->validate([
            'amenity_id' => 'required|string',
            'booking_date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'attendee_count' => 'nullable|integer|min:1',
            'apartment_id' => 'nullable|string',
            'user_id' => 'nullable|string',
        ]);

        $userId = $validated['user_id'] ?? null;
        if (! $userId) {
            $user = DB::table('users')->where('email', 'nguyenvanan@cassavas.vn')->first();
            $userId = $user ? $user->id : (string) Str::uuid();
        }

        $apartmentId = $validated['apartment_id'] ?? null;
        if (! $apartmentId) {
            $resident = DB::table('residents')->where('user_id', $userId)->first();
            $apartmentId = $resident ? $resident->apartment_id : null;
            if (! $apartmentId) {
                $apt = DB::table('apartments')->where('apartment_number', 'A1-05')->first();
                $apartmentId = $apt ? $apt->id : (string) Str::uuid();
            }
        }

        $amenity = DB::table('amenities')->where('id', $validated['amenity_id'])->first();
        if (! $amenity) {
            return response()->json(['detail' => 'Tiện ích không tồn tại!'], 404);
        }

        $bookingId = (string) Str::uuid();
        $bookingCode = 'BK-'.Str::upper(Str::random(8));
        $qrCode = 'QR_AMENITY_'.Str::upper(Str::random(16));
        $now = Carbon::now();

        DB::table('amenity_bookings')->insert([
            'id' => $bookingId,
            'booking_code' => $bookingCode,
            'amenity_id' => $amenity->id,
            'apartment_id' => $apartmentId,
            'resident_user_id' => $userId,
            'booking_date' => $validated['booking_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'attendee_count' => $validated['attendee_count'] ?? 2,
            'total_amount' => $amenity->hourly_rate ?? 0.00,
            'is_paid' => 1,
            'status' => 'CONFIRMED',
            'checkin_qr_code' => $qrCode,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $booking = DB::table('amenity_bookings')
            ->leftJoin('amenities', 'amenity_bookings.amenity_id', '=', 'amenities.id')
            ->where('amenity_bookings.id', $bookingId)
            ->select('amenity_bookings.*', 'amenities.amenity_name', 'amenities.location_detail', 'amenities.amenity_code')
            ->first();

        if (! Cache::has('amenities_data_version')) {
            Cache::forever('amenities_data_version', 1);
        }
        Cache::increment('amenities_data_version');

        return response()->json([
            'success' => true,
            'message' => 'Đặt tiện ích thành công! Mã Check-in QR đã được tạo.',
            'booking' => $booking,
        ], 201);
    }

    /**
     * Đăng ký khách viếng thăm & cấp thẻ QR Pass.
     */
    public function createVisitor(Request $request): JsonResponse
    {
        $request->merge($this->getRequestData($request));

        $validated = $request->validate([
            'visitor_name' => 'required|string|max:255',
            'visitor_phone' => 'nullable|string|max:30',
            'visit_purpose' => 'nullable|string|max:255',
            'expected_arrival_time' => 'required|date',
            'vehicle_license_plate' => 'nullable|string|max:50',
            'apartment_id' => 'nullable|string',
            'user_id' => 'nullable|string',
        ]);

        $userId = $validated['user_id'] ?? null;
        if (! $userId) {
            $user = DB::table('users')->where('email', 'nguyenvanan@cassavas.vn')->first();
            $userId = $user ? $user->id : (string) Str::uuid();
        }

        $apartmentId = $validated['apartment_id'] ?? null;
        if (! $apartmentId) {
            $resident = DB::table('residents')->where('user_id', $userId)->first();
            $apartmentId = $resident ? $resident->apartment_id : null;
            if (! $apartmentId) {
                $apt = DB::table('apartments')->where('apartment_number', 'A1-05')->first();
                $apartmentId = $apt ? $apt->id : (string) Str::uuid();
            }
        }

        $visitorId = (string) Str::uuid();
        $code = 'VIS-'.date('Ymd').'-'.rand(100, 999);
        $qrPass = 'PASS_'.Str::upper(Str::random(18));
        $now = Carbon::now();

        DB::table('visitor_registrations')->insert([
            'id' => $visitorId,
            'registration_code' => $code,
            'host_resident_user_id' => $userId,
            'apartment_id' => $apartmentId,
            'visitor_name' => $validated['visitor_name'],
            'visitor_phone' => $validated['visitor_phone'] ?? '',
            'visit_purpose' => $validated['visit_purpose'] ?? 'Thăm cư dân',
            'expected_arrival_time' => Carbon::parse($validated['expected_arrival_time']),
            'vehicle_license_plate' => $validated['vehicle_license_plate'] ?? null,
            'qr_access_pass_code' => $qrPass,
            'qr_pass_status' => 'ACTIVE',
            'is_pre_approved_by_resident' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $visitor = DB::table('visitor_registrations')->where('id', $visitorId)->first();

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký khách thành công! Đã tạo thẻ QR Pass thông hành.',
            'visitor' => $visitor,
        ], 201);
    }

    /**
     * Thanh toán trực tuyến hóa đơn (Mô phỏng VietQR/VNPAY).
     */
    public function payInvoice(Request $request, string $id): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) {
            return response()->json(['detail' => 'Hóa đơn không tồn tại!'], 404);
        }

        $now = Carbon::now();
        DB::table('invoices')->where('id', $id)->update([
            'status' => 'PAID',
            'paid_amount' => $invoice->total_amount,
            'remaining_balance' => 0.00,
            'updated_at' => $now,
        ]);

        $updated = DB::table('invoices')->where('id', $id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Thanh toán hóa đơn thành công!',
            'invoice' => $updated,
        ]);
    }
}
