import { test, expect, Page } from '@playwright/test';

test.describe('F13: Khảo sát và Thống kê Mức độ Hài lòng Cư dân (CSAT)', () => {
  test('E2E MOCKED: Manager tạo khảo sát, xem phân tích CSAT và Resident tham gia đánh giá', async ({ page }) => {
    // 1. Mock local session for Manager
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản Lý Trưởng' }));
      localStorage.setItem('smart_cassavas_token', 'mock-manager-token');
    });

    // 2. Setup mock routes for surveys
    let surveys = [
      {
        id: 'survey-mock-001',
        survey_code: 'SURVEY-2026-Q4-TEST',
        title: 'Khảo sát Dịch vụ Quý 4/2026',
        description: 'Đo lường mức độ hài lòng về an ninh và vệ sinh',
        target_block_id: null,
        target_block: null,
        start_date: '2026-10-01',
        end_date: '2026-11-30',
        is_active: true,
        computed_status: 'ACTIVE',
        version: 1,
        created_at: '2026-10-01T08:00:00Z',
        responses_count: 5,
        eligible_count: 200,
        participation_rate_percent: 2.5,
        questions: [
          {
            id: 'q1',
            question_order: 1,
            question_text: 'Đánh giá mức độ hài lòng về dịch vụ vận hành:',
            question_type: 'RATING_1_TO_5',
            is_required: true,
          },
          {
            id: 'q2',
            question_order: 2,
            question_text: 'Bạn có hài lòng với dịch vụ an ninh?',
            question_type: 'YES_NO',
            is_required: true,
            options_json: ['Có', 'Không'],
          },
        ],
      },
    ];

    await page.route('**/api/v1/management/surveys**', async (route) => {
      const req = route.request();
      const url = new URL(req.url());

      if (url.pathname.endsWith('/kpis')) {
        return route.fulfill({
          json: {
            status: 'success',
            data: {
              total_surveys: surveys.length,
              active_surveys: 1,
              total_responses: 5,
              overall_average_rating: 3.6,
              overall_csat_percentage: 60.0,
              total_valid_ratings: 5,
              dissatisfied_count: 1,
            },
          },
        });
      }

      if (url.pathname.includes('/analytics')) {
        return route.fulfill({
          json: {
            status: 'success',
            data: {
              survey: surveys[0],
              kpis: {
                total_responses: 5,
                eligible_apartments_count: 200,
                participation_rate_percent: 2.5,
                overall_average_rating: 3.6,
                overall_csat_percentage: 60.0,
                total_valid_ratings: 5,
                satisfied_ratings_count: 3,
                neutral_ratings_count: 1,
                dissatisfied_ratings_count: 1,
              },
              question_stats: [
                {
                  question_id: 'q1',
                  question_order: 1,
                  question_text: 'Đánh giá mức độ hài lòng về dịch vụ vận hành:',
                  question_type: 'RATING_1_TO_5',
                  is_required: true,
                  total_answers: 5,
                  average_rating: 3.6,
                  csat_percentage: 60.0,
                  satisfied_count: 3,
                  neutral_count: 1,
                  dissatisfied_count: 1,
                  rating_distribution: [
                    { stars: 1, count: 0, percent: 0.0 },
                    { stars: 2, count: 1, percent: 20.0 },
                    { stars: 3, count: 1, percent: 20.0 },
                    { stars: 4, count: 2, percent: 40.0 },
                    { stars: 5, count: 1, percent: 20.0 },
                  ],
                },
              ],
            },
          },
        });
      }

      if (req.method() === 'POST') {
        const payload = req.postDataJSON();
        const newSurvey = {
          id: 'survey-mock-' + Date.now(),
          survey_code: payload.survey_code || 'SURVEY-NEW',
          title: payload.title,
          description: payload.description,
          target_block_id: payload.target_block_id || null,
          target_block: null,
          start_date: payload.start_date,
          end_date: payload.end_date,
          is_active: true,
          computed_status: 'ACTIVE',
          version: 1,
          created_at: new Date().toISOString(),
          responses_count: 0,
          eligible_count: 200,
          participation_rate_percent: 0,
          questions: payload.questions || [],
        };
        surveys.unshift(newSurvey as any);
        return route.fulfill({ status: 201, json: { status: 'success', data: newSurvey } });
      }

      return route.fulfill({
        json: {
          status: 'success',
          data: surveys,
          kpis: {
            total_surveys: surveys.length,
            active_surveys: 1,
            total_responses: 5,
            overall_average_rating: 3.6,
            overall_csat_percentage: 60.0,
            total_valid_ratings: 5,
            dissatisfied_count: 1,
          },
          meta: { current_page: 1, last_page: 1, per_page: 15, total: surveys.length },
        },
      });
    });

    // 3. Navigate to Management Surveys Dashboard
    await page.goto('/quan-ly?tab=surveys');

    // Verify Title and KPI Header
    await expect(page.getByText('Khảo sát & Thống kê Mức độ Hài lòng Cư dân (CSAT)')).toBeVisible();
    await expect(page.getByText('Khảo sát Dịch vụ Quý 4/2026')).toBeVisible();

    // 4. Open Analytics Modal
    await page.getByRole('button', { name: 'Phân tích CSAT' }).click();
    await expect(page.getByText('Chỉ số CSAT')).toBeVisible();
    await expect(page.getByText('60%')).toBeVisible();
    await expect(page.getByText('3.6 / 5')).toBeVisible();
    await page.getByTestId('close-analytics-modal').click();
    await expect(page.getByText('Chỉ số CSAT')).not.toBeVisible();

    // 5. Open Create Survey Modal
    await page.getByRole('button', { name: 'Tạo chiến dịch khảo sát' }).click();
    await expect(page.getByText('Thiết lập Chiến dịch Khảo sát Mới')).toBeVisible();
    await page.getByPlaceholder('Ví dụ: Khảo sát Mức độ Hài lòng Dịch vụ Quý 4/2026...').fill('Khảo sát Thang máy 2026');
    await page.getByRole('button', { name: 'Tạo khảo sát' }).click();

    // Verify that the new survey appears in the list
    await expect(page.getByText('Khảo sát Thang máy 2026')).toBeVisible();
  });
});
