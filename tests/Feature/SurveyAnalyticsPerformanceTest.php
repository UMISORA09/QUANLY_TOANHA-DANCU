<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Role;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyResponseDetail;
use App\Models\User;
use App\Services\SurveyAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class SurveyAnalyticsPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private SatisfactionSurvey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_csat@smartcassavas.test'],
            [
                'full_name' => 'Admin CSAT',
                'password' => bcrypt('password'),
                'status' => 'ACTIVE',
            ]
        );

        $role = Role::firstOrCreate(['role_code' => 'SUPER_ADMIN'], [
            'id' => (string) Str::uuid(),
            'role_name' => 'Super Admin',
        ]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);

        $block = Block::firstOrCreate(['block_code' => 'B_PERF'], [
            'id' => (string) Str::uuid(),
            'block_name' => 'Block Perf',
            'total_floors' => 10,
        ]);

        $floor = Floor::firstOrCreate(['block_id' => $block->id, 'floor_number' => 1], [
            'id' => (string) Str::uuid(),
            'floor_code' => 'FL_PERF_1',
            'floor_name' => 'Floor Perf 1',
        ]);

        $apartment = Apartment::firstOrCreate(
            ['apartment_number' => 'PERF-101'],
            [
                'id' => (string) Str::uuid(),
                'block_id' => $block->id,
                'floor_id' => $floor->id,
                'gross_floor_area_sqm' => 75.0,
                'net_usable_area_sqm' => 70.0,
                'status' => 'OCCUPIED',
            ]
        );

        $this->survey = SatisfactionSurvey::firstOrCreate(
            ['survey_code' => 'CSAT-PERF-01'],
            [
                'id' => (string) Str::uuid(),
                'title' => 'Khảo sát chất lượng dịch vụ',
                'target_block_id' => null,
                'status' => 'OPEN',
                'start_date' => now()->subDay(),
                'end_date' => now()->addDays(7),
                'created_by' => $this->admin->id,
                'version' => 1,
            ]
        );

        $question = SurveyQuestion::firstOrCreate(
            ['survey_id' => $this->survey->id, 'question_order' => 1],
            [
                'id' => (string) Str::uuid(),
                'question_text' => 'Mức độ hài lòng với vệ sinh tòa nhà?',
                'question_type' => SurveyQuestion::TYPE_RATING_1_TO_5,
                'is_required' => true,
            ]
        );

        $response = SurveyResponse::firstOrCreate(
            ['survey_id' => $this->survey->id, 'apartment_id' => $apartment->id],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->admin->id,
                'submitted_at' => now(),
            ]
        );

        SurveyResponseDetail::firstOrCreate(
            ['response_id' => $response->id, 'question_id' => $question->id],
            [
                'id' => (string) Str::uuid(),
                'numeric_score' => 5,
            ]
        );
    }

    public function test_survey_analytics_memoizes_results_in_cache(): void
    {
        $service = app(SurveyAnalyticsService::class);

        $analytics1 = $service->getSurveyAnalytics($this->survey, $this->admin);
        $this->assertIsArray($analytics1);
        $this->assertSame(1, $analytics1['kpis']['total_responses']);
        $this->assertEquals(5.0, $analytics1['kpis']['overall_average_rating']);

        // Gọi lần 2: Phải lấy từ cache
        $analytics2 = $service->getSurveyAnalytics($this->survey, $this->admin);
        $this->assertEquals($analytics1, $analytics2);
    }

    public function test_overview_kpis_are_cached_and_valid(): void
    {
        $service = app(SurveyAnalyticsService::class);

        $kpis = $service->getManagementOverviewKpis($this->admin);
        $this->assertIsArray($kpis);
        $this->assertArrayHasKey('total_surveys', $kpis);
        $this->assertArrayHasKey('overall_csat_percentage', $kpis);
    }
}
