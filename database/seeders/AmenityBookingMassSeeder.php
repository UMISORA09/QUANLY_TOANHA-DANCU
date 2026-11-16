<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AmenityBookingMassSeeder extends Seeder
{
    /**
     * Seed approximately 500 amenity bookings (F1 - F4).
     */
    public function run(): void
    {
        $targetCount = 500;
        $currentCount = DB::table('amenity_bookings')->count();

        if ($currentCount >= $targetCount) {
            $this->command?->info("amenity_bookings đã có {$currentCount} bản ghi (>= {$targetCount}), bỏ qua tạo thêm.");

            return;
        }

        $needed = $targetCount - $currentCount;
        $this->command?->info("Đang tạo thêm {$needed} bản ghi đặt chỗ tiện ích (amenity_bookings)...");

        $amenities = DB::table('amenities')->where('is_active', 1)->get();
        if ($amenities->isEmpty()) {
            $amenities = DB::table('amenities')->get();
        }

        if ($amenities->isEmpty()) {
            $this->command?->error('Không tìm thấy tiện ích nào trong bảng amenities để seed booking.');

            return;
        }

        $apartments = DB::table('apartments')->get();
        if ($apartments->isEmpty()) {
            $this->command?->error('Không tìm thấy căn hộ nào trong bảng apartments.');

            return;
        }

        // Lấy danh sách cư dân đã gắn với căn hộ
        $residentRows = DB::table('residents')
            ->select('user_id', 'apartment_id')
            ->get();

        $adminUsers = DB::table('users')
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->all();

        $adminId = $adminUsers[0] ?? null;

        $timeSlots = [
            ['start' => '06:00:00', 'end' => '08:00:00'],
            ['start' => '08:00:00', 'end' => '10:00:00'],
            ['start' => '10:00:00', 'end' => '12:00:00'],
            ['start' => '14:00:00', 'end' => '16:00:00'],
            ['start' => '16:00:00', 'end' => '18:00:00'],
            ['start' => '18:00:00', 'end' => '20:00:00'],
            ['start' => '19:30:00', 'end' => '21:30:00'],
        ];

        $residentNotesList = [
            'Tổ chức tiệc sinh nhật gia đình ấm cúng.',
            'Cần mượn thêm 4 ghế xếp và 1 bếp nướng phụ.',
            'Đăng ký giao lưu câu lạc bộ thể thao cuối tuần.',
            'Khách mời gồm 2 gia đình cư dân cùng tòa.',
            'Gia đình có trẻ nhỏ, nhờ hỗ trợ kiểm tra an toàn quanh khu vực hồ bơi.',
            'Tập luyện chuẩn bị cho giải đấu nội khu.',
            'Họp nhóm học tập cho các cháu học sinh.',
            'Liên hoan mừng tân gia căn hộ mới nhận.',
            'Buổi tập yoga buổi sáng sớm cùng hàng xóm.',
            'Đăng ký sử dụng thường xuyên theo tuần.',
            'Sử dụng phòng sinh hoạt để bàn công tác ban đại diện tầng.',
            'Yêu cầu hỗ trợ bật hệ thống âm thanh và mic không dây.',
            'Gia đình tự chuẩn bị nguyên vật liệu và cam kết dọn dẹp sạch sẽ.',
            'Cần chuẩn bị sẵn ổ cắm điện cho thiết bị chiếu phim gia đình.',
            null,
            null,
            null,
        ];

        $rejectionReasons = [
            'Tiện ích tạm đóng cửa để bảo dưỡng định kỳ hệ thống lọc nước.',
            'Trùng lịch bảo trì hệ thống chiếu sáng khu vực thể thao.',
            'Số lượng người tham gia vượt quá sức chứa tối đa của phòng.',
            'Khung giờ này đã có lịch đặt trước của Ban quản trị tổ chức sự kiện chung.',
            'Căn hộ đang có công nợ phí dịch vụ quá hạn, vui lòng thanh toán trước khi đặt.',
        ];

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $bookingsBatch = [];
        $paymentsBatch = [];
        $batchSize = 100;

        for ($i = 0; $i < $needed; $i++) {
            $bookingId = (string) Str::uuid();
            $amenity = $amenities->random();

            if (! $residentRows->isEmpty() && rand(0, 10) > 2) {
                $residentPair = $residentRows->random();
                $userId = $residentPair->user_id;
                $apartmentId = $residentPair->apartment_id;
            } else {
                $apartment = $apartments->random();
                $apartmentId = $apartment->id;
                $userId = $adminUsers[array_rand($adminUsers)];
            }

            // Phân bổ ngày: 65% quá khứ (đã diễn ra), 35% tương lai
            $isPast = (rand(1, 100) <= 65);
            if ($isPast) {
                $bookingDate = $now->copy()->subDays(rand(1, 75));
            } else {
                $bookingDate = $now->copy()->addDays(rand(0, 25));
            }

            $slot = $timeSlots[array_rand($timeSlots)];
            $attendeeCount = rand(1, max(2, min(15, (int) ($amenity->capacity ?? 10))));
            $hourlyRate = (float) ($amenity->hourly_rate ?? 50000);
            $totalAmount = $hourlyRate * 2;
            $depositAmount = (float) ($amenity->deposit_amount ?? 100000);

            // Xác định trạng thái
            if ($isPast) {
                $randStatus = rand(1, 100);
                if ($randStatus <= 80) {
                    $status = 'COMPLETED';
                    $isPaid = 1;
                    $checkedInAt = $bookingDate->copy()->setTimeFromTimeString($slot['start'])->addMinutes(rand(5, 20));
                    $checkedInBy = $adminId;
                    $approvedBy = $adminId;
                    $approvedAt = $bookingDate->copy()->subDays(1);
                    $rejectionReason = null;
                } elseif ($randStatus <= 92) {
                    $status = 'CANCELLED';
                    $isPaid = 0;
                    $checkedInAt = null;
                    $checkedInBy = null;
                    $approvedBy = null;
                    $approvedAt = null;
                    $rejectionReason = null;
                } else {
                    $status = 'REJECTED';
                    $isPaid = 0;
                    $checkedInAt = null;
                    $checkedInBy = null;
                    $approvedBy = $adminId;
                    $approvedAt = $bookingDate->copy()->subDays(1);
                    $rejectionReason = $rejectionReasons[array_rand($rejectionReasons)];
                }
            } else {
                $randStatus = rand(1, 100);
                if ($randStatus <= 65) {
                    $status = 'CONFIRMED';
                    $isPaid = 1;
                    $checkedInAt = null;
                    $checkedInBy = null;
                    $approvedBy = $adminId;
                    $approvedAt = $now->copy()->subHours(rand(1, 24));
                    $rejectionReason = null;
                } elseif ($randStatus <= 90) {
                    $status = 'PENDING';
                    $isPaid = 0;
                    $checkedInAt = null;
                    $checkedInBy = null;
                    $approvedBy = null;
                    $approvedAt = null;
                    $rejectionReason = null;
                } else {
                    $status = 'CANCELLED';
                    $isPaid = 0;
                    $checkedInAt = null;
                    $checkedInBy = null;
                    $approvedBy = null;
                    $approvedAt = null;
                    $rejectionReason = null;
                }
            }

            $bookingCode = 'BKG-'.$bookingDate->format('Ymd').'-'.strtoupper(Str::random(6));
            $qrCode = 'QR-'.$bookingCode.'-'.strtoupper(Str::random(8));
            $createdAt = $bookingDate->copy()->subDays(rand(1, 5))->setTime(rand(7, 21), rand(0, 59));

            $bookingsBatch[] = [
                'id' => $bookingId,
                'booking_code' => $bookingCode,
                'amenity_id' => $amenity->id,
                'apartment_id' => $apartmentId,
                'resident_user_id' => $userId,
                'booking_date' => $bookingDate->toDateString(),
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'attendee_count' => $attendeeCount,
                'total_amount' => $totalAmount,
                'deposit_amount' => $depositAmount,
                'is_paid' => $isPaid,
                'payment_id' => null,
                'status' => $status,
                'approved_by_user_id' => $approvedBy,
                'approved_at' => $approvedAt,
                'rejection_reason' => $rejectionReason,
                'checkin_qr_code' => $qrCode,
                'checked_in_at' => $checkedInAt,
                'checked_in_by_staff_id' => $checkedInBy,
                'resident_notes' => $residentNotesList[array_rand($residentNotesList)],
                'admin_notes' => in_array($status, ['CONFIRMED', 'COMPLETED']) ? 'Đã xác nhận đặt chỗ qua hệ thống.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => null,
            ];

            // Nếu booking có phí và đã trả hoặc đang chờ, tạo thanh toán mẫu
            if (($totalAmount > 0 || $depositAmount > 0) && in_array($status, ['CONFIRMED', 'COMPLETED', 'PENDING'])) {
                $payStatus = in_array($status, ['CONFIRMED', 'COMPLETED']) ? 'PAID' : 'PENDING';
                $paymentsBatch[] = [
                    'id' => (string) Str::uuid(),
                    'booking_id' => $bookingId,
                    'reference' => 'PAY-'.strtoupper(Str::random(10)),
                    'amount' => $totalAmount + $depositAmount,
                    'bank_bin' => '970422',
                    'bank_name' => 'MBBank',
                    'account_number' => '19036789999',
                    'account_name' => 'BQL CHUNG CU SMART CASSAVAS',
                    'status' => $payStatus,
                    'expires_at' => $createdAt->copy()->addHours(2),
                    'reported_at' => $payStatus === 'PAID' ? $createdAt->copy()->addMinutes(15) : null,
                    'bank_transaction_id' => $payStatus === 'PAID' ? 'MB'.rand(10000000, 99999999) : null,
                    'received_amount' => $payStatus === 'PAID' ? ($totalAmount + $depositAmount) : null,
                    'received_at' => $payStatus === 'PAID' ? $createdAt->copy()->addMinutes(15) : null,
                    'confirmed_at' => $payStatus === 'PAID' ? $createdAt->copy()->addMinutes(20) : null,
                    'confirmed_by_user_id' => $payStatus === 'PAID' ? $adminId : null,
                    'review_reason' => null,
                    'refund_required' => false,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            if (count($bookingsBatch) >= $batchSize) {
                DB::table('amenity_bookings')->insert($bookingsBatch);
                if (! empty($paymentsBatch)) {
                    DB::table('amenity_booking_payments')->insert($paymentsBatch);
                }
                $bookingsBatch = [];
                $paymentsBatch = [];
            }
        }

        if (! empty($bookingsBatch)) {
            DB::table('amenity_bookings')->insert($bookingsBatch);
            if (! empty($paymentsBatch)) {
                DB::table('amenity_booking_payments')->insert($paymentsBatch);
            }
        }

        $finalCount = DB::table('amenity_bookings')->count();
        $this->command?->info("Đã seed thành công! Tổng số amenity_bookings hiện tại: {$finalCount}");
    }
}
