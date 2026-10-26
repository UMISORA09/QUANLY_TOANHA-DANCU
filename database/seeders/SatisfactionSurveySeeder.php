<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SatisfactionSurveySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed RBAC Permissions
        $permissions = [
            'SURVEY:VIEW' => 'Xem danh sách và chi tiết khảo sát',
            'SURVEY:CREATE' => 'Tạo mới chiến dịch khảo sát',
            'SURVEY:UPDATE' => 'Chỉnh sửa cấu hình chiến dịch khảo sát',
            'SURVEY:ANALYTICS' => 'Xem báo cáo thống kê và điểm số CSAT',
            'SURVEY:MANAGE' => 'Quản lý toàn bộ chiến dịch khảo sát, kích hoạt/đóng khảo sát',
        ];

        $targetRoles = Role::whereIn('role_code', ['BUILDING_MANAGER', 'SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'DEVELOPER'])->get();

        foreach ($permissions as $code => $desc) {
            $permission = Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'module' => 'SURVEY',
                    'permission_name' => $code,
                    'description' => $desc,
                ]
            );

            foreach ($targetRoles as $role) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // 2. Seed default surveys if empty
        if (SatisfactionSurvey::count() === 0) {
            $adminUser = User::whereHas('roles', function ($q): void {
                $q->whereIn('role_code', ['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER']);
            })->first() ?? User::first();

            $adminId = $adminUser?->id ?? (string) Str::uuid();
            $now = Carbon::now('Asia/Ho_Chi_Minh');

            // Survey 1: General Quarterly CSAT Survey (All blocks)
            $survey1 = SatisfactionSurvey::create([
                'id' => (string) Str::uuid(),
                'survey_code' => 'SURVEY-2026-Q4-CSAT-ALL',
                'title' => 'Khảo sát Mức độ Hài lòng Cư dân Quý 4/2026',
                'description' => 'Khảo sát định kỳ nhằm đánh giá chất lượng dịch vụ vận hành tòa nhà, an ninh, vệ sinh và hệ sinh thái tiện ích để không ngừng nâng cao trải nghiệm sống.',
                'target_block_id' => null,
                'start_date' => $now->copy()->subDays(5)->toDateString(),
                'end_date' => $now->copy()->addDays(25)->toDateString(),
                'is_active' => true,
                'created_by' => $adminId,
                'version' => 1,
            ]);

            SurveyQuestion::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey1->id,
                'question_order' => 1,
                'question_text' => 'Mức độ hài lòng tổng quan về chất lượng quản lý & vận hành chung cư của Ban Quản lý:',
                'question_type' => SurveyQuestion::TYPE_RATING_1_TO_5,
                'options_json' => [],
                'is_required' => true,
            ]);

            SurveyQuestion::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey1->id,
                'question_order' => 2,
                'question_text' => 'Đánh giá mức độ vệ sinh môi trường tại hành lang, sảnh thang máy và khu vực công cộng:',
                'question_type' => SurveyQuestion::TYPE_RATING_1_TO_5,
                'options_json' => [],
                'is_required' => true,
            ]);

            SurveyQuestion::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey1->id,
                'question_order' => 3,
                'question_text' => 'Bạn có hài lòng với tốc độ tiếp nhận và xử lý sự cố kỹ thuật của tòa nhà không?',
                'question_type' => SurveyQuestion::TYPE_YES_NO,
                'options_json' => ['Có', 'Không'],
                'is_required' => true,
            ]);

            SurveyQuestion::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey1->id,
                'question_order' => 4,
                'question_text' => 'Hệ thống tiện ích nội khu nào bạn và gia đình thường xuyên sử dụng nhất?',
                'question_type' => SurveyQuestion::TYPE_MULTIPLE_CHOICE,
                'options_json' => [
                    'Hồ bơi tràn viền',
                    'Phòng tập thể thao Gym & Yoga',
                    'Khu vui chơi trẻ em KidZone',
                    'Khu nướng BBQ ngoài trời',
                    'Khuôn viên công viên dạo bộ',
                ],
                'is_required' => true,
            ]);

            SurveyQuestion::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey1->id,
                'question_order' => 5,
                'question_text' => 'Đóng góp ý kiến hoặc đề xuất cụ thể để Ban Quản trị nâng cao chất lượng môi trường sống:',
                'question_type' => SurveyQuestion::TYPE_FREE_TEXT,
                'options_json' => [],
                'is_required' => false,
            ]);

            // Survey 2: Block-specific survey (Block A if exists)
            $blockA = Block::where('block_code', 'LIKE', '%A%')->first();
            if ($blockA) {
                $survey2 = SatisfactionSurvey::create([
                    'id' => (string) Str::uuid(),
                    'survey_code' => 'SURVEY-2026-BLOCK-A-SECURITY',
                    'title' => 'Khảo sát Chuyên đề An ninh & Kiểm soát Ra vào Tòa Tháp '.$blockA->block_name,
                    'description' => 'Thu thập ý kiến cư dân về chất lượng dịch vụ bảo vệ, kiểm soát thẻ từ và an ninh bãi xe tại tháp.',
                    'target_block_id' => $blockA->id,
                    'start_date' => $now->copy()->subDays(2)->toDateString(),
                    'end_date' => $now->copy()->addDays(20)->toDateString(),
                    'is_active' => true,
                    'created_by' => $adminId,
                    'version' => 1,
                ]);

                SurveyQuestion::create([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey2->id,
                    'question_order' => 1,
                    'question_text' => 'Đánh giá thái độ và nghiệp vụ của nhân viên an ninh trực sảnh & bãi đỗ xe:',
                    'question_type' => SurveyQuestion::TYPE_RATING_1_TO_5,
                    'options_json' => [],
                    'is_required' => true,
                ]);

                SurveyQuestion::create([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey2->id,
                    'question_order' => 2,
                    'question_text' => 'Đề xuất giải pháp tăng cường an ninh cho tòa nhà:',
                    'question_type' => SurveyQuestion::TYPE_FREE_TEXT,
                    'options_json' => [],
                    'is_required' => false,
                ]);
            }
        }
    }
}
