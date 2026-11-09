import { test, expect, Page } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

async function openSurvey(page: Page, outcome: 'submitted' | 'pending' | 'offline' | 'server-error', recoveryGate?: Promise<void>) {
  let submitted = false;
  let posts = 0;
  let detailReads = 0;
  const survey = {
    id: 'survey-conflict', survey_code: 'QA-CONFLICT', title: 'Khảo sát kiểm thử xung đột',
    description: 'Dữ liệu giả lập riêng cho kiểm thử giao diện.', target_block_name: 'Tòa QA',
    start_date: '2026-10-01', end_date: '2099-12-31', days_left: 10, total_questions: 1,
    is_open: true, has_submitted: false,
  };
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'Cư dân QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-survey-token');
  });
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { id: 'resident-qa', full_name: 'Cư dân QA', role: 'resident' } });
    if (path.endsWith('/resident/surveys/active')) return route.fulfill({ json: { status: 'success', data: [{ ...survey, has_submitted: submitted }] } });
    if (path.endsWith('/survey-conflict/submit')) {
      posts += 1;
      submitted = outcome === 'submitted';
      return route.fulfill({ status: outcome === 'server-error' ? 500 : 409, json: { message: 'Trạng thái khảo sát vừa thay đổi.' } });
    }
    if (path.endsWith('/resident/surveys/survey-conflict')) {
      detailReads += 1;
      if (detailReads === 2 && recoveryGate) await recoveryGate;
      if (outcome === 'offline' && posts > 0) return route.fulfill({ status: 503, json: { message: 'QA unavailable' } });
      return route.fulfill({ json: { status: 'success', data: {
        survey: { ...survey, has_submitted: submitted, submitted_at: submitted ? '27/10/2026 09:00' : null },
        questions: [{ id: 'q-draft', question_order: 1, question_text: 'Ý kiến của bạn?', question_type: 'FREE_TEXT', is_required: true }],
      } } });
    }
    return route.fulfill({ json: { data: [], items: [], unread_count: 0, total: 0 } });
  });
  await page.goto('/cu-dan?tab=feedback');
  await page.getByRole('button', { name: 'Khảo sát Mức độ Hài lòng CSAT (F13)' }).click();
  await page.getByRole('button', { name: 'Bắt đầu khảo sát' }).click();
  const draft = page.getByPlaceholder('Nhập ý kiến đóng góp của bạn (tối đa 1000 ký tự)...');
  await draft.fill('Giữ nguyên câu trả lời của cư dân.');
  await page.getByRole('button', { name: 'Gửi phiếu đánh giá', exact: true }).click();
  return { draft, posts: () => posts, detailReads: () => detailReads };
}

for (const width of [390, 1280]) {
  test(`409 reloads actual submitted state without another POST at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 850 });
    const state = await openSurvey(page, 'submitted');
    await expect(page.getByRole('heading', { name: 'Căn hộ đã hoàn thành khảo sát này' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Gửi phiếu đánh giá', exact: true })).toHaveCount(0);
    expect(state.posts()).toBe(1);
    expect(state.detailReads()).toBe(2);
    await page.getByRole('button', { name: 'Quay lại danh sách', exact: true }).click();
    await expect(page.getByText('Đã hoàn thành', { exact: true })).toBeVisible();
  });
}

test('409 retains draft when refreshed server state does not report submission', async ({ page }) => {
  const state = await openSurvey(page, 'pending');
  await expect(page.getByRole('alert')).toContainText('Trạng thái khảo sát vừa thay đổi.');
  await expect(state.draft).toHaveValue('Giữ nguyên câu trả lời của cư dân.');
  await expect(page.getByRole('button', { name: 'Gửi phiếu đánh giá', exact: true })).toBeEnabled();
  expect(state.posts()).toBe(1);
  expect(state.detailReads()).toBe(2);
});

test('failed refresh after 409 retains draft and exposes a recoverable error', async ({ page }) => {
  const state = await openSurvey(page, 'offline');
  await expect(page.getByRole('alert')).toContainText('Không thể đồng bộ trạng thái khảo sát');
  await expect(state.draft).toHaveValue('Giữ nguyên câu trả lời của cư dân.');
  await expect(page.getByRole('button', { name: 'Gửi phiếu đánh giá', exact: true })).toBeEnabled();
  expect(state.posts()).toBe(1);
});

test('server failure keeps the draft and does not claim success', async ({ page }) => {
  const state = await openSurvey(page, 'server-error');
  await expect(page.getByRole('alert')).toContainText('Trạng thái khảo sát vừa thay đổi.');
  await expect(state.draft).toHaveValue('Giữ nguyên câu trả lời của cư dân.');
  await expect(page.getByRole('button', { name: 'Gửi phiếu đánh giá', exact: true })).toBeEnabled();
  expect(state.detailReads()).toBe(1);
});

test('late conflict recovery does not overwrite a newly opened form', async ({ page }) => {
  let releaseRecovery: () => void = () => {};
  const gate = new Promise<void>(resolve => { releaseRecovery = resolve; });
  const state = await openSurvey(page, 'pending', gate);
  await expect.poll(state.detailReads).toBe(2);
  await page.getByRole('button', { name: 'Quay lại danh sách', exact: true }).click();
  await page.getByRole('button', { name: 'Bắt đầu khảo sát' }).click();
  await state.draft.fill('Nội dung bản nhập mới.');
  const recoveryResponse = page.waitForResponse(response => response.url().endsWith('/resident/surveys/survey-conflict') && response.request().method() === 'GET');
  releaseRecovery();
  await (await recoveryResponse).finished();
  await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => resolve())));
  await expect(state.draft).toHaveValue('Nội dung bản nhập mới.');
  await expect(page.getByRole('alert')).toHaveCount(0);
  expect(state.posts()).toBe(1);
});
