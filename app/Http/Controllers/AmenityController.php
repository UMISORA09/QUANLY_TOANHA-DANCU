<?php

namespace App\Http\Controllers;

use App\Services\AmenityService;
use App\Services\Search\SearchCacheService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Controller Quản lý Tiện ích & Slot theo Kiến trúc Tinh gọn (Lightweight Architecture)
 * Phân tầng tối giản: Tiếp nhận Request -> Chuẩn hóa Input -> Gọi Service -> Trả về DTO chuẩn
 */
class AmenityController extends Controller
{
    public function __construct(
        public AmenityService $amenityService
    ) {}

    /**
     * Lấy phiên bản dữ liệu tiện ích hiện tại phục vụ ETag & Cache Invalidation
     */
    public function getDataVersion(): int
    {
        return (int) Cache::get('amenities_data_version', 1);
    }

    /**
     * Tăng phiên bản dữ liệu khi có thay đổi CRUD để làm mới ETag & Invalidate Cache tìm kiếm
     */
    public function bumpDataVersion(): void
    {
        if (! Cache::has('amenities_data_version')) {
            Cache::forever('amenities_data_version', 1);
        }
        Cache::increment('amenities_data_version');

        // Invalidate toàn bộ cache tìm kiếm tiện ích (Section 12)
        SearchCacheService::invalidate();
    }

    /**
     * Trích xuất và chuẩn hóa dữ liệu từ Request (Hỗ trợ cả JSON body, raw stream và form data)
     *
     * @return array<string, mixed>
     */
    protected function extractPayload(Request $request): array
    {
        $payload = $request->json()->all();
        if (empty($payload)) {
            $raw = $request->getContent();
            if (! empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        return ! empty($payload) ? $payload : $request->all();
    }

    /**
     * Lấy danh sách các tòa nhà / Block
     */
    public function getBlocks(): JsonResponse
    {
        $blocks = DB::table('blocks')
            ->select('id', 'block_name', 'block_code')
            ->orderBy('block_name')
            ->get();

        return response()->json($blocks);
    }

    /**
     * Lấy danh sách danh mục tiện ích kèm số lượng tiện ích trực thuộc
     */
    public function getCategories(): JsonResponse
    {
        $categories = DB::table('amenity_categories')
            ->select('id', 'category_name', 'category_code', 'icon_name', 'description', 'created_at')
            ->orderBy('category_name')
            ->get();

        $amenityCounts = DB::table('amenities')
            ->whereNull('deleted_at')
            ->groupBy('category_id')
            ->select('category_id', DB::raw('count(*) as count'))
            ->pluck('count', 'category_id')
            ->toArray();

        $result = $categories->map(function ($cat) use ($amenityCounts) {
            return [
                'id' => $cat->id,
                'category_name' => $cat->category_name,
                'category_code' => $cat->category_code,
                'icon_name' => $cat->icon_name,
                'description' => $cat->description,
                'created_at' => Carbon::parse($cat->created_at)->toIso8601String(),
                'amenities_count' => (int) ($amenityCounts[$cat->id] ?? 0),
            ];
        });

        return response()->json($result);
    }

    /**
     * Tạo mới danh mục tiện ích
     */
    public function createCategory(Request $request): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'category_name' => 'required|string|max:100',
            'category_code' => 'required|string|max:50',
            'icon_name' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ])->validate();

        $id = (string) Str::uuid();
        $now = Carbon::now();

        DB::table('amenity_categories')->insert([
            'id' => $id,
            'category_name' => trim($validated['category_name']),
            'category_code' => strtoupper(trim($validated['category_code'])),
            'icon_name' => $this->amenityService->nullifyEmpty($validated['icon_name'] ?? null) ?? 'Sparkles',
            'description' => $this->amenityService->nullifyEmpty($validated['description'] ?? null),
            'created_at' => $now,
        ]);

        $this->bumpDataVersion();

        return response()->json([
            'id' => $id,
            'category_name' => trim($validated['category_name']),
            'category_code' => strtoupper(trim($validated['category_code'])),
            'icon_name' => $this->amenityService->nullifyEmpty($validated['icon_name'] ?? null) ?? 'Sparkles',
            'description' => $this->amenityService->nullifyEmpty($validated['description'] ?? null),
            'created_at' => $now->toIso8601String(),
            'amenities_count' => 0,
        ], 201);
    }

    /**
     * Cập nhật danh mục tiện ích
     */
    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'category_name' => 'required|string|max:100',
            'category_code' => 'required|string|max:50',
            'icon_name' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ])->validate();

        $exists = DB::table('amenity_categories')->where('id', $id)->first();
        if (! $exists) {
            return response()->json(['detail' => 'Không tìm thấy danh mục tiện ích.'], 404);
        }

        DB::table('amenity_categories')->where('id', $id)->update([
            'category_name' => trim($validated['category_name']),
            'category_code' => strtoupper(trim($validated['category_code'])),
            'icon_name' => $this->amenityService->nullifyEmpty($validated['icon_name'] ?? null) ?? $exists->icon_name,
            'description' => $this->amenityService->nullifyEmpty($validated['description'] ?? null),
        ]);

        $this->bumpDataVersion();

        $count = DB::table('amenities')
            ->where('category_id', $id)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'id' => $id,
            'category_name' => trim($validated['category_name']),
            'category_code' => strtoupper(trim($validated['category_code'])),
            'icon_name' => $this->amenityService->nullifyEmpty($validated['icon_name'] ?? null) ?? $exists->icon_name,
            'description' => $this->amenityService->nullifyEmpty($validated['description'] ?? null),
            'created_at' => Carbon::parse($exists->created_at)->toIso8601String(),
            'amenities_count' => $count,
        ]);
    }

    /**
     * Xóa danh mục tiện ích
     */
    public function deleteCategory(string $id): JsonResponse
    {
        $hasAmenities = DB::table('amenities')
            ->where('category_id', $id)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasAmenities) {
            return response()->json([
                'detail' => 'Không thể xóa danh mục này vì đang có tiện ích trực thuộc!',
            ], 400);
        }

        DB::table('amenity_categories')->where('id', $id)->delete();
        $this->bumpDataVersion();

        return response()->json(['success' => true, 'message' => 'Đã xóa danh mục tiện ích.']);
    }

    /**
     * Lấy danh sách tiện ích có phân trang, lọc và tìm kiếm (Tối ưu hóa với ETag & 304 Cache)
     */
    public function getAmenities(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'block_id' => $request->query('block_id'),
            'is_active' => $request->query('is_active'),
            'sort' => $request->query('sort', 'created_at_desc'),
            'page' => $request->query('page', 1),
            'limit' => $request->query('limit', 10),
        ];

        $version = $this->getDataVersion();
        $fingerprint = md5(json_encode($filters).'_v'.$version);
        $etag = '"amenities-'.$fingerprint.'"';

        $clientEtag = $request->header('If-None-Match');
        if ($clientEtag && trim($clientEtag) === $etag) {
            return response()->json(null, 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache, must-revalidate',
            ]);
        }

        $result = $this->amenityService->getPaginatedAmenities($filters);

        return response()->json($result, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }

    /**
     * Lấy chi tiết một tiện ích
     */
    public function getAmenity(string $id): JsonResponse
    {
        $amenity = $this->amenityService->getAmenityById($id);

        if (! $amenity) {
            return response()->json(['detail' => 'Không tìm thấy tiện ích.'], 404);
        }

        return response()->json($amenity);
    }

    /**
     * Tạo mới tiện ích
     */
    public function createAmenity(Request $request): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'category_id' => 'required|string',
            'block_id' => 'nullable|string',
            'amenity_name' => 'required|string|max:150',
            'amenity_code' => 'required|string|max:60',
            'location_detail' => 'required|string|max:255',
            'max_capacity_per_slot' => 'required|integer|min:1',
            'hourly_rate' => 'required|numeric|min:0',
            'security_deposit_required' => 'nullable|numeric|min:0',
            'advance_booking_days_limit' => 'nullable|integer|min:1',
            'min_cancel_hours_before' => 'nullable|integer|min:0',
            'requires_admin_approval' => 'nullable|boolean',
            'rules_and_regulations' => 'nullable|string',
            'cover_image_url' => 'nullable|string|max:500',
            'gallery_images' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ])->validate();

        try {
            $created = $this->amenityService->createAmenity($validated);
            $this->bumpDataVersion();

            return response()->json($created, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
    }

    /**
     * Cập nhật tiện ích kèm kiểm tra Slot Capacity & Booking Conflict (Section 13 & 14)
     */
    public function updateAmenity(Request $request, string $id): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'category_id' => 'sometimes|required|string',
            'block_id' => 'nullable|string',
            'amenity_name' => 'sometimes|required|string|max:150',
            'amenity_code' => 'sometimes|required|string|max:60',
            'location_detail' => 'sometimes|required|string|max:255',
            'max_capacity_per_slot' => 'sometimes|required|integer|min:1',
            'hourly_rate' => 'sometimes|required|numeric|min:0',
            'security_deposit_required' => 'nullable|numeric|min:0',
            'advance_booking_days_limit' => 'nullable|integer|min:1',
            'min_cancel_hours_before' => 'nullable|integer|min:0',
            'requires_admin_approval' => 'nullable|boolean',
            'rules_and_regulations' => 'nullable|string',
            'cover_image_url' => 'nullable|string|max:500',
            'gallery_images' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ])->validate();

        try {
            $updated = $this->amenityService->updateAmenity($id, $validated);
            $this->bumpDataVersion();

            return response()->json($updated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
    }

    /**
     * Bật / tắt trạng thái hoạt động tiện ích
     */
    public function toggleAmenityStatus(Request $request, string $id): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'is_active' => 'required|boolean',
        ])->validate();

        DB::table('amenities')->where('id', $id)->update([
            'is_active' => $validated['is_active'] ? 1 : 0,
            'updated_at' => Carbon::now(),
        ]);

        $this->bumpDataVersion();

        $amenity = $this->amenityService->getAmenityById($id);

        return response()->json($amenity);
    }

    /**
     * Xóa mềm tiện ích với kiểm tra phụ thuộc đặt chỗ (Section 12)
     */
    public function deleteAmenity(string $id): JsonResponse
    {
        $amenity = DB::table('amenities')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $amenity) {
            return response()->json(['detail' => 'Không tìm thấy tiện ích hoặc đã bị xóa.'], 404);
        }

        // Kiểm tra xem có booking nào đang hoạt động không (Section 12)
        $activeBookingsCount = DB::table('amenity_bookings')
            ->where('amenity_id', $id)
            ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
            ->whereNull('deleted_at')
            ->count();

        if ($activeBookingsCount > 0) {
            return response()->json([
                'detail' => "Không thể xóa tiện ích '{$amenity->amenity_name}' vì đang có {$activeBookingsCount} lượt đặt chỗ (booking) đang hoạt động. Vui lòng xử lý các đặt chỗ này trước khi xóa.",
            ], 409);
        }

        // Thực hiện Soft Delete để bảo toàn dữ liệu liên kết
        DB::table('amenities')->where('id', $id)->update([
            'deleted_at' => Carbon::now(),
            'is_active' => 0,
        ]);

        $this->bumpDataVersion();

        return response()->json(['success' => true, 'message' => 'Đã xóa tiện ích thành công.']);
    }

    /**
     * Lấy danh sách đặt chỗ của tiện ích (Section 14)
     */
    public function getAmenityBookings(string $id): JsonResponse
    {
        $amenity = DB::table('amenities')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $amenity) {
            return response()->json(['detail' => 'Không tìm thấy tiện ích.'], 404);
        }

        $bookings = $this->amenityService->getAmenityBookings($id);

        return response()->json($bookings);
    }

    /**
     * Đặt chỗ sử dụng tiện ích (Booking Capacity & Inactive Enforcement - Section 14 & 15)
     */
    public function bookAmenity(Request $request, string $id): JsonResponse
    {
        $amenity = DB::table('amenities')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $amenity) {
            return response()->json(['detail' => 'Không tìm thấy tiện ích.'], 404);
        }

        // 1. Kiểm tra trạng thái hoạt động (Section 15)
        if (! $amenity->is_active) {
            return response()->json([
                'detail' => 'Tiện ích hiện đang tạm ngưng hoạt động, không thể đặt chỗ mới.',
            ], 400);
        }

        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'apartment_id' => 'required|string',
            'resident_user_id' => 'required|string',
            'booking_date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'attendee_count' => 'nullable|integer|min:1',
            'resident_notes' => 'nullable|string',
        ])->validate();

        $bookingDate = $validated['booking_date'];
        $startTime = $validated['start_time'];
        $endTime = $validated['end_time'];
        $attendeeCount = (int) ($validated['attendee_count'] ?? 1);

        // 2. Kiểm tra ngày đóng cửa bảo trì (Blackout dates)
        $isBlackout = DB::table('amenity_blackouts')
            ->where('amenity_id', $id)
            ->where('blackout_date', $bookingDate)
            ->first();

        if ($isBlackout) {
            return response()->json([
                'detail' => "Tiện ích đóng cửa bảo trì vào ngày {$bookingDate} (Lý do: {$isBlackout->reason}).",
            ], 400);
        }

        // 3. Kiểm tra sức chứa (Capacity Check - Section 14)
        $currentBookings = DB::table('amenity_bookings')
            ->where('amenity_id', $id)
            ->where('booking_date', $bookingDate)
            ->where('start_time', $startTime)
            ->where('end_time', $endTime)
            ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total_bookings, COALESCE(SUM(attendee_count), 0) as total_attendees')
            ->first();

        $curAttendees = (int) ($currentBookings->total_attendees ?? 0);

        if (($curAttendees + $attendeeCount) > (int) $amenity->max_capacity_per_slot) {
            return response()->json([
                'detail' => "Khung giờ này đã vượt quá sức chứa tối đa ({$amenity->max_capacity_per_slot} người). Hiện tại đã có {$curAttendees} người đăng ký.",
            ], 409);
        }

        // 4. Tạo mã booking và ghi nhận vào cơ sở dữ liệu thật
        $bookingId = (string) Str::uuid();
        $bookingCode = 'BKG-'.date('Ymd').'-'.strtoupper(Str::random(6));
        $qrCode = 'QR-'.strtoupper(Str::random(16));
        $now = Carbon::now();

        $hourlyRate = (float) $amenity->hourly_rate;
        $deposit = (float) ($amenity->security_deposit_required ?? 0);

        DB::table('amenity_bookings')->insert([
            'id' => $bookingId,
            'booking_code' => $bookingCode,
            'amenity_id' => $id,
            'apartment_id' => $validated['apartment_id'],
            'resident_user_id' => $validated['resident_user_id'],
            'booking_date' => $bookingDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'attendee_count' => $attendeeCount,
            'total_amount' => $hourlyRate,
            'deposit_amount' => $deposit,
            'is_paid' => $hourlyRate > 0 ? 0 : 1,
            'status' => $amenity->requires_admin_approval ? 'PENDING' : 'APPROVED',
            'checkin_qr_code' => $qrCode,
            'resident_notes' => $this->amenityService->nullifyEmpty($validated['resident_notes'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdBooking = DB::table('amenity_bookings')->where('id', $bookingId)->first();
        $this->bumpDataVersion();

        return response()->json($createdBooking, 201);
    }

    /**
     * Lấy danh sách khung giờ của tiện ích
     */
    public function getTimeSlots(string $amenityId): JsonResponse
    {
        $slots = DB::table('amenity_time_slots')
            ->where('amenity_id', $amenityId)
            ->orderBy('day_of_week')
            ->orderBy('slot_start_time')
            ->get();

        $res = $slots->map(function ($s) {
            return [
                'id' => $s->id,
                'amenity_id' => $s->amenity_id,
                'day_of_week' => (int) $s->day_of_week,
                'slot_start_time' => substr($s->slot_start_time, 0, 5),
                'slot_end_time' => substr($s->slot_end_time, 0, 5),
                'slot_label' => $s->slot_label,
                'max_bookings' => (int) $s->max_bookings,
                'is_active' => (bool) $s->is_active,
                'created_at' => Carbon::parse($s->created_at)->toIso8601String(),
            ];
        });

        return response()->json($res);
    }

    /**
     * Tạo khung giờ cho tiện ích
     */
    public function createTimeSlot(Request $request, string $amenityId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'day_of_week' => 'required|integer|between:0,6',
            'slot_start_time' => 'required|string',
            'slot_end_time' => 'required|string',
            'slot_label' => 'nullable|string|max:60',
            'max_bookings' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ])->validate();

        $id = (string) Str::uuid();
        $now = Carbon::now();

        DB::table('amenity_time_slots')->insert([
            'id' => $id,
            'amenity_id' => $amenityId,
            'day_of_week' => $validated['day_of_week'],
            'slot_start_time' => $validated['slot_start_time'],
            'slot_end_time' => $validated['slot_end_time'],
            'slot_label' => $this->amenityService->nullifyEmpty($validated['slot_label'] ?? null),
            'max_bookings' => $validated['max_bookings'] ?? 1,
            'is_active' => isset($validated['is_active']) ? ($validated['is_active'] ? 1 : 0) : 1,
            'created_at' => $now,
        ]);

        $this->bumpDataVersion();

        return response()->json([
            'id' => $id,
            'amenity_id' => $amenityId,
            'day_of_week' => (int) $validated['day_of_week'],
            'slot_start_time' => substr($validated['slot_start_time'], 0, 5),
            'slot_end_time' => substr($validated['slot_end_time'], 0, 5),
            'slot_label' => $this->amenityService->nullifyEmpty($validated['slot_label'] ?? null),
            'max_bookings' => (int) ($validated['max_bookings'] ?? 1),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_at' => $now->toIso8601String(),
        ], 201);
    }

    /**
     * Cập nhật khung giờ
     */
    public function updateTimeSlot(Request $request, string $amenityId, string $slotId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'day_of_week' => 'required|integer|between:0,6',
            'slot_start_time' => 'required|string',
            'slot_end_time' => 'required|string',
            'slot_label' => 'nullable|string|max:60',
            'max_bookings' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ])->validate();

        DB::table('amenity_time_slots')
            ->where('id', $slotId)
            ->where('amenity_id', $amenityId)
            ->update([
                'day_of_week' => $validated['day_of_week'],
                'slot_start_time' => $validated['slot_start_time'],
                'slot_end_time' => $validated['slot_end_time'],
                'slot_label' => $this->amenityService->nullifyEmpty($validated['slot_label'] ?? null),
                'max_bookings' => $validated['max_bookings'] ?? 1,
                'is_active' => isset($validated['is_active']) ? ($validated['is_active'] ? 1 : 0) : 1,
            ]);

        $this->bumpDataVersion();

        $slot = DB::table('amenity_time_slots')->where('id', $slotId)->first();

        return response()->json([
            'id' => $slot->id,
            'amenity_id' => $slot->amenity_id,
            'day_of_week' => (int) $slot->day_of_week,
            'slot_start_time' => substr($slot->slot_start_time, 0, 5),
            'slot_end_time' => substr($slot->slot_end_time, 0, 5),
            'slot_label' => $slot->slot_label,
            'max_bookings' => (int) $slot->max_bookings,
            'is_active' => (bool) $slot->is_active,
            'created_at' => Carbon::parse($slot->created_at)->toIso8601String(),
        ]);
    }

    /**
     * Bật / tắt trạng thái khung giờ
     */
    public function toggleTimeSlotStatus(Request $request, string $amenityId, string $slotId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'is_active' => 'required|boolean',
        ])->validate();

        DB::table('amenity_time_slots')
            ->where('id', $slotId)
            ->where('amenity_id', $amenityId)
            ->update([
                'is_active' => $validated['is_active'] ? 1 : 0,
            ]);

        $this->bumpDataVersion();

        $slot = DB::table('amenity_time_slots')->where('id', $slotId)->first();

        return response()->json([
            'id' => $slot->id,
            'amenity_id' => $slot->amenity_id,
            'day_of_week' => (int) $slot->day_of_week,
            'slot_start_time' => substr($slot->slot_start_time, 0, 5),
            'slot_end_time' => substr($slot->slot_end_time, 0, 5),
            'slot_label' => $slot->slot_label,
            'max_bookings' => (int) $slot->max_bookings,
            'is_active' => (bool) $slot->is_active,
            'created_at' => Carbon::parse($slot->created_at)->toIso8601String(),
        ]);
    }

    /**
     * Xóa khung giờ
     */
    public function deleteTimeSlot(string $amenityId, string $slotId): JsonResponse
    {
        DB::table('amenity_time_slots')
            ->where('id', $slotId)
            ->where('amenity_id', $amenityId)
            ->delete();

        $this->bumpDataVersion();

        return response()->json(['success' => true, 'message' => 'Đã xóa khung giờ.']);
    }

    /**
     * Lấy danh sách ngày/giờ đóng cửa bảo trì (Blackouts)
     */
    public function getBlackouts(string $amenityId): JsonResponse
    {
        $blackouts = DB::table('amenity_blackouts')
            ->where('amenity_id', $amenityId)
            ->orderBy('blackout_date', 'desc')
            ->get();

        $res = $blackouts->map(function ($b) {
            return [
                'id' => $b->id,
                'amenity_id' => $b->amenity_id,
                'blackout_date' => $b->blackout_date,
                'start_time' => $b->start_time ? substr($b->start_time, 0, 5) : null,
                'end_time' => $b->end_time ? substr($b->end_time, 0, 5) : null,
                'reason' => $b->reason,
                'created_by' => $b->created_by,
                'created_at' => Carbon::parse($b->created_at)->toIso8601String(),
            ];
        });

        return response()->json($res);
    }

    /**
     * Tạo ngày đóng cửa bảo trì
     */
    public function createBlackout(Request $request, string $amenityId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'blackout_date' => 'required|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'reason' => 'required|string|max:255',
        ])->validate();

        $id = (string) Str::uuid();
        $now = Carbon::now();

        $startTime = $this->amenityService->nullifyEmpty($validated['start_time'] ?? null);
        $endTime = $this->amenityService->nullifyEmpty($validated['end_time'] ?? null);

        DB::table('amenity_blackouts')->insert([
            'id' => $id,
            'amenity_id' => $amenityId,
            'blackout_date' => $validated['blackout_date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reason' => trim($validated['reason']),
            'created_at' => $now,
        ]);

        $this->bumpDataVersion();

        return response()->json([
            'id' => $id,
            'amenity_id' => $amenityId,
            'blackout_date' => $validated['blackout_date'],
            'start_time' => $startTime ? substr($startTime, 0, 5) : null,
            'end_time' => $endTime ? substr($endTime, 0, 5) : null,
            'reason' => trim($validated['reason']),
            'created_by' => null,
            'created_at' => $now->toIso8601String(),
        ], 201);
    }

    /**
     * Cập nhật ngày đóng cửa bảo trì
     */
    public function updateBlackout(Request $request, string $amenityId, string $blackoutId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'blackout_date' => 'required|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'reason' => 'required|string|max:255',
        ])->validate();

        $startTime = $this->amenityService->nullifyEmpty($validated['start_time'] ?? null);
        $endTime = $this->amenityService->nullifyEmpty($validated['end_time'] ?? null);

        DB::table('amenity_blackouts')
            ->where('id', $blackoutId)
            ->where('amenity_id', $amenityId)
            ->update([
                'blackout_date' => $validated['blackout_date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'reason' => trim($validated['reason']),
            ]);

        $this->bumpDataVersion();

        $b = DB::table('amenity_blackouts')->where('id', $blackoutId)->first();

        return response()->json([
            'id' => $b->id,
            'amenity_id' => $b->amenity_id,
            'blackout_date' => $b->blackout_date,
            'start_time' => $b->start_time ? substr($b->start_time, 0, 5) : null,
            'end_time' => $b->end_time ? substr($b->end_time, 0, 5) : null,
            'reason' => $b->reason,
            'created_by' => $b->created_by,
            'created_at' => Carbon::parse($b->created_at)->toIso8601String(),
        ]);
    }

    /**
     * Xóa ngày đóng cửa bảo trì
     */
    public function deleteBlackout(string $amenityId, string $blackoutId): JsonResponse
    {
        DB::table('amenity_blackouts')
            ->where('id', $blackoutId)
            ->where('amenity_id', $amenityId)
            ->delete();

        $this->bumpDataVersion();

        return response()->json(['success' => true, 'message' => 'Đã xóa lịch bảo trì.']);
    }

    /**
     * Cập nhật trạng thái đặt chỗ (Duyệt, Từ chối, Hoàn tất, Hủy)
     */
    public function updateBookingStatus(Request $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'status' => 'required|string|in:PENDING,APPROVED,CONFIRMED,COMPLETED,CANCELLED,REJECTED',
            'admin_notes' => 'nullable|string|max:500',
        ])->validate();

        $booking = DB::table('amenity_bookings')
            ->where('id', $bookingId)
            ->where('amenity_id', $amenityId)
            ->whereNull('deleted_at')
            ->first();

        if (! $booking) {
            return response()->json(['detail' => 'Không tìm thấy thông tin đặt chỗ.'], 404);
        }

        $now = Carbon::now();
        $updateData = [
            'status' => strtoupper($validated['status']),
            'updated_at' => $now,
        ];

        if (array_key_exists('admin_notes', $validated)) {
            $updateData['admin_notes'] = $this->amenityService->nullifyEmpty($validated['admin_notes']);
        }

        DB::transaction(function () use ($bookingId, $updateData) {
            DB::table('amenity_bookings')->where('id', $bookingId)->update($updateData);
        });

        $this->bumpDataVersion();

        $updated = DB::table('amenity_bookings')->where('id', $bookingId)->first();

        return response()->json($updated);
    }

    /**
     * Hủy đặt chỗ (Section 14 & Multi-tab sync)
     */
    public function cancelBooking(Request $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $this->extractPayload($request);
        $validated = validator($data, [
            'reason' => 'nullable|string|max:500',
        ])->validate();

        $booking = DB::table('amenity_bookings')
            ->where('id', $bookingId)
            ->where('amenity_id', $amenityId)
            ->whereNull('deleted_at')
            ->first();

        if (! $booking) {
            return response()->json(['detail' => 'Không tìm thấy thông tin đặt chỗ.'], 404);
        }

        if (in_array($booking->status, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
            return response()->json(['detail' => "Đặt chỗ này đã ở trạng thái {$booking->status}, không thể hủy."], 400);
        }

        $now = Carbon::now();
        $notes = $this->amenityService->nullifyEmpty($validated['reason'] ?? null);

        DB::transaction(function () use ($bookingId, $now, $notes) {
            DB::table('amenity_bookings')->where('id', $bookingId)->update([
                'status' => 'CANCELLED',
                'admin_notes' => $notes,
                'updated_at' => $now,
            ]);
        });

        $this->bumpDataVersion();

        $updated = DB::table('amenity_bookings')->where('id', $bookingId)->first();

        return response()->json([
            'success' => true,
            'message' => 'Đã hủy đặt chỗ thành công.',
            'booking' => $updated,
        ]);
    }
}
