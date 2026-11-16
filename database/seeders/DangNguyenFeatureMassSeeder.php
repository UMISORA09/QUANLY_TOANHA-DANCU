<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DangNguyenFeatureMassSeeder extends Seeder
{
    /**
     * Chạy toàn bộ seeder khối lượng lớn (~500 bản ghi/chức năng) cho các phân hệ của Đặng Đăng Nguyên (F1 - F13):
     * - F1 - F4: Tiện ích & Đặt chỗ trực tuyến (Amenity Bookings & Payments)
     * - F5 - F9: Báo cáo sự cố, Phiếu sửa chữa, Lịch sử, Thảo luận & Đánh giá (Tickets & Field Service)
     * - F10 - F11: Bảng tin, Thông báo nội bộ & Lượt đọc (Announcements & Read Receipts)
     * - F12: Hòm thư Góp ý, Phản hồi BQL & Phân tích cảm xúc AI (Suggestions)
     * - F13: Khảo sát Mức độ Hài lòng Cư dân, Câu hỏi & Chi tiết phản hồi (Satisfaction Surveys & CSAT)
     */
    public function run(): void
    {
        $this->command?->info('================================================================');
        $this->command?->info('  BẮT ĐẦU SEED DỮ LIỆU CÁC CHỨC NĂNG CỦA ĐẶNG ĐĂNG NGUYÊN (F1 - F13)');
        $this->command?->info('  Mục tiêu: Mỗi module chức năng có tối thiểu 500 bản ghi dữ liệu thực tế');
        $this->command?->info('================================================================');

        $this->call([
            AmenityBookingMassSeeder::class,
            ResidentTicketMassSeeder::class,
            AnnouncementMassSeeder::class,
            SuggestionMassSeeder::class,
            SatisfactionSurveyMassSeeder::class,
        ]);

        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info('  TỔNG KẾT SỐ LƯỢNG BẢN GHI ĐÃ SEED THÀNH CÔNG:');
        $this->command?->info('  - F1-F4  amenity_bookings:         '.DB::table('amenity_bookings')->count().' bản ghi');
        $this->command?->info('  - F1-F4  amenity_booking_payments: '.DB::table('amenity_booking_payments')->count().' bản ghi');
        $this->command?->info('  - F5-F9  tickets:                  '.DB::table('tickets')->count().' bản ghi');
        $this->command?->info('  - F5-F9  ticket_history:           '.DB::table('ticket_history')->count().' bản ghi');
        $this->command?->info('  - F5-F9  ticket_ratings:           '.DB::table('ticket_ratings')->count().' bản ghi');
        $this->command?->info('  - F5-F9  ticket_comments:          '.DB::table('ticket_comments')->count().' bản ghi');
        $this->command?->info('  - F10-11 announcements:            '.DB::table('announcements')->count().' bản ghi');
        $this->command?->info('  - F10-11 announcement_reads:       '.DB::table('announcement_reads')->count().' bản ghi');
        $this->command?->info('  - F12    suggestions:              '.DB::table('suggestions')->count().' bản ghi');
        $this->command?->info('  - F13    satisfaction_surveys:     '.DB::table('satisfaction_surveys')->count().' chiến dịch');
        $this->command?->info('  - F13    survey_questions:         '.DB::table('survey_questions')->count().' câu hỏi');
        $this->command?->info('  - F13    survey_responses:         '.DB::table('survey_responses')->count().' phiếu khảo sát');
        $this->command?->info('  - F13    survey_response_details:  '.DB::table('survey_response_details')->count().' chi tiết trả lời');
        $this->command?->info('================================================================');
    }
}
