import { test, expect, Page } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

test.describe('F13: Khảo sát và Thống kê Mức độ Hài lòng Cư dân (CSAT)', () => {
  for (const width of [375, 768, 1440]) test(`E2E MOCKED: Manager tạo khảo sát, xem phân tích CSAT và Resident tham gia đánh giá ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
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
    await expect(page.getByTestId('analytics-modal')).toBeVisible();
    await expect(page.getByTestId('analytics-modal').getByText('60%', { exact: true })).toBeVisible();
    await expect(page.getByTestId('analytics-modal').getByText('3.6 / 5', { exact: true })).toBeVisible();
    await expect(page.getByTestId('analytics-modal').getByText('Tính theo câu trả lời, không theo người tham gia.', { exact: true })).toBeVisible();
    await expect(page.getByTestId('analytics-modal').getByText('3/5 câu trả lời chấm 4–5 sao', { exact: true })).toBeVisible();
    await page.setViewportSize({ width, height: 844 });
    await expect(page.getByTestId('analytics-modal').getByText('3/5 câu trả lời chấm 4–5 sao', { exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByTestId('close-analytics-modal').click();
    await expect(page.getByTestId('analytics-modal')).not.toBeVisible();
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.route('**/api/v1/management/surveys/*/analytics', route => route.fulfill({ json: {
      status: 'success', data: { survey: surveys[0], kpis: {
        total_responses: 0, eligible_apartments_count: 200, participation_rate_percent: 0,
        overall_average_rating: 0, overall_csat_percentage: 0, total_valid_ratings: 0,
        satisfied_ratings_count: 0, neutral_ratings_count: 0, dissatisfied_ratings_count: 0, data_status: 'NO_DATA',
      }, question_stats: [] },
    } }));
    await page.getByRole('button', { name: 'Phân tích CSAT' }).click();
    const csatCard = page.getByTestId('analytics-modal').getByText('Chỉ số CSAT', { exact: true }).locator('..');
    await expect(csatCard.getByText('N/A', { exact: true })).toBeVisible();
    await expect(csatCard.getByText('Chưa có câu trả lời chấm điểm.', { exact: true })).toBeVisible();
    await expect(csatCard).not.toContainText('0/0');
    await page.getByTestId('close-analytics-modal').click();
    await expect(page.getByTestId('analytics-modal')).not.toBeVisible();

    // 5. Open Create Survey Modal
    await page.getByRole('button', { name: 'Tạo chiến dịch khảo sát' }).click();
    await expect(page.getByTestId('survey-builder-modal')).toBeVisible();
    const type = page.getByRole('button', { name: 'Loại câu hỏi 1', exact: true });
    await type.click();
    const options = page.getByRole('listbox');
    expect(await options.evaluate(element => { const box = element.getBoundingClientRect(); return box.left >= 0 && box.right <= innerWidth && box.top >= 0 && box.bottom <= innerHeight; })).toBe(true);
    await page.getByRole('option', { name: 'Tự luận góp ý', exact: true }).click();
    await expect(type).toContainText('Tự luận góp ý');
    await type.press('ArrowDown'); await page.keyboard.press('Home'); await page.keyboard.press('Enter');
    await expect(type).toContainText('Đánh giá 1-5 sao');
    await type.click(); await page.keyboard.press('Escape');
    await expect(page.getByTestId('survey-builder-modal')).toBeVisible();
    await expect(type).toBeFocused();
    await page.getByPlaceholder('Ví dụ: Khảo sát Mức độ Hài lòng Dịch vụ Quý 4/2026...').fill('Khảo sát Thang máy 2026');
    await page.getByRole('button', { name: 'Tạo khảo sát' }).click();
    await expect(page.getByTestId('survey-builder-modal')).not.toBeVisible();

    // Verify that the new survey appears in the list
    await expect(page.getByText('Khảo sát Thang máy 2026')).toBeVisible();
  });
});

test('F13: conflict reload preserves draft and requires review before retry', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-token');
  });
  const survey = { id: 'qa-survey', survey_code: 'QA-OCC', title: 'Khảo sát ban đầu', description: '', start_date: '2026-11-01', end_date: '2027-12-31', is_active: true, version: 1, responses_count: 0, questions: [{ id: 'q1', question_text: 'Dịch vụ tốt không?', question_type: 'YES_NO', options_json: ['Có', 'Không'], is_required: true }] };
  let writes = 0;
  let detailReads = 0;
  await page.route('**/api/v1/management/surveys**', async (route) => {
    const req = route.request(); const path = new URL(req.url()).pathname;
    if (req.method() === 'PUT' || req.method() === 'PATCH') {
      writes++;
      expect(req.postDataJSON().version).toBe(writes === 1 ? 1 : 2);
      return route.fulfill(writes === 1 ? { status: 409, json: { message: 'Khảo sát đã thay đổi' } } : { json: { status: 'success', data: { ...survey, version: 3 } } });
    }
    if (path.endsWith('/qa-survey')) {
      detailReads++;
      return route.fulfill({ json: { status: 'success', data: { ...survey, title: 'Bản mới của người khác', version: 2 } } });
    }
    return route.fulfill({ json: { status: 'success', data: [survey], meta: { last_page: 1 } } });
  });
  await page.goto('/quan-ly?tab=surveys');
  await page.getByTitle('Chỉnh sửa cấu hình').click();
  const title = page.getByPlaceholder('Ví dụ: Khảo sát Mức độ Hài lòng Dịch vụ Quý 4/2026...');
  await title.fill('Nội dung đang soạn');
  await page.getByRole('button', { name: 'Lưu cập nhật', exact: true }).click();
  await expect(page.getByText('Bản mới của người khác', { exact: true })).toBeVisible();
  await expect(title).toHaveValue('Nội dung đang soạn');
  expect(detailReads).toBe(1); expect(writes).toBe(1);
  await page.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' }).click();
  await page.getByRole('button', { name: 'Lưu cập nhật', exact: true }).click();
  await expect(page.getByTestId('survey-builder-modal')).not.toBeVisible();
  expect(writes).toBe(2);
});
