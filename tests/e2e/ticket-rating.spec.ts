import { test, expect } from '@playwright/test';

for (const width of [1280, 390]) {
  test(`MOCKED F13: đánh giá Ticket, giữ bản nhập khi lỗi và đọc lại (${width}px)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'Cư dân QA' })); localStorage.setItem('smart_cassavas_token', 'qa-csat'); });
    const ticket = { id: 'rating-qa', ticket_number: 'CSAT-QA-01', title: 'Sửa vòi nước', description: 'Sự cố đã nghiệm thu.', apartment_id: 'qa-apartment', creator_user_id: 'qa-resident', category_id: 'qa-category', status: 'CLOSED', priority: 'MEDIUM', version: 3, created_at: '2026-11-04T08:00:00Z' };
    let saved: { id: string; ticket_id: string; rating_stars: number; review_comment: string; created_at: string } | null = null;
    let attempts = 0;
    let serverVersion = 3;
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/rating')) {
        if (route.request().method() === 'POST') {
          attempts++;
          if (attempts === 1) {
            if (width === 390) serverVersion = 4;
            return route.fulfill({ status: width === 390 ? 409 : 503, json: { message: 'Mất kết nối QA hoặc dữ liệu vừa thay đổi, vui lòng tải lại.' } });
          }
          const data = route.request().postDataJSON();
          expect(data.version).toBe(serverVersion); expect(data.rating_stars).toBe(4);
          saved = { id: 'qa-rating', ticket_id: ticket.id, rating_stars: data.rating_stars, review_comment: data.review_comment, created_at: new Date().toISOString() };
        }
        return route.fulfill({ json: { data: saved, can_rate: !saved, ticket_version: serverVersion } });
      }
      if (path.endsWith('/tickets/rating-qa')) return route.fulfill({ json: { success: true, data: ticket } });
      if (path.endsWith('/comments')) return route.fulfill({ json: { items: [], next_cursor: null, meta: { status: 'CLOSED', ticket_version: 3, history_count: 0, can_comment: false, can_internal_note: false } } });
      if (path.endsWith('/tickets')) return route.fulfill({ json: { success: true, data: [ticket], pagination: { current_page: 1, last_page: 1, total: 1, per_page: 15 }, counters: { all: 1, new: 0, in_progress: 0, resolved: 0, closed: 1, cancelled: 0 } } });
      return route.fulfill({ json: { success: true, data: [], items: [], unread_count: 0, page: 1, total_pages: 1 } });
    });
    await page.goto('/cu-dan?tab=tickets&ticket_id=rating-qa');
    const panel = page.getByRole('region', { name: 'Đánh giá sau xử lý sự cố', exact: true });
    await expect(panel.getByRole('radio')).toHaveCount(5);
    await panel.getByRole('button', { name: 'Gửi đánh giá', exact: true }).click();
    await expect(panel.getByRole('alert')).toContainText('Vui lòng chọn');
    await panel.getByRole('radio', { name: '4 sao', exact: true }).check();
    await panel.getByLabel('Nhận xét (không bắt buộc)', { exact: true }).fill('<script>QA</script>\nKỹ thuật viên hỗ trợ tốt.');
    await panel.getByRole('button', { name: 'Gửi đánh giá', exact: true }).click();
    await expect(panel.getByRole('alert')).toContainText('Mất kết nối QA');
    await expect(panel.getByRole('radio', { name: '4 sao', exact: true })).toBeChecked();
    await expect(panel.getByLabel('Nhận xét (không bắt buộc)', { exact: true })).toHaveValue('<script>QA</script>\nKỹ thuật viên hỗ trợ tốt.');
    if (width === 390) {
      const refreshed = page.waitForResponse(response => response.url().endsWith('/rating') && response.request().method() === 'GET');
      await panel.getByRole('button', { name: 'Tải lại đánh giá', exact: true }).click();
      await refreshed;
      await expect(panel.getByRole('alert')).toHaveCount(0);
    }
    await panel.getByRole('button', { name: 'Gửi đánh giá', exact: true }).click();
    await expect(panel.getByRole('status')).toContainText('4/5 sao');
    await expect(panel.locator('script')).toHaveCount(0);
    await expect(panel.getByRole('button', { name: 'Gửi đánh giá', exact: true })).toHaveCount(0);
    await page.reload(); await expect(panel.getByRole('status')).toContainText('4/5 sao');
    expect(attempts).toBe(2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}

for (const width of [1280, 390]) {
  test(`MOCKED F13: GET cũ không ghi đè đánh giá gửi thành công (${width}px)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'QA' })); localStorage.setItem('smart_cassavas_token', 'qa-review'); });
    let reads = 0, posts = 0;
    let releaseRead: () => void = () => {};
    const delayedRead = new Promise<void>(resolve => { releaseRead = resolve; });
    const empty = { data: null, can_rate: true, ticket_version: 3 };
    const ticket = { id: 'rating-qa', ticket_number: 'QA-REVIEW', title: 'Sự cố QA', description: 'Đã nghiệm thu', status: 'CLOSED', priority: 'MEDIUM', version: 3, created_at: '2026-11-04T08:00:00Z' };
    await page.route('**/api/**', async route => {
      const request = route.request(), path = new URL(request.url()).pathname;
      if (path.endsWith('/rating')) {
        if (request.method() === 'GET') {
          reads++;
          if (reads === 2) await delayedRead;
          return route.fulfill({ json: empty });
        }
        posts++;
        if (posts === 1) return route.fulfill({ status: 503, json: { message: 'QA retry' } });
        return route.fulfill({ status: 201, json: { data: { id: 'qa-rating', ticket_id: ticket.id, rating_stars: 4, review_comment: 'QA', created_at: '2026-11-04' }, can_rate: false, ticket_version: 3 } });
      }
      if (path.endsWith('/tickets/rating-qa')) return route.fulfill({ json: { success: true, data: ticket } });
      if (path.endsWith('/comments')) return route.fulfill({ json: { items: [], next_cursor: null, meta: { status: 'CLOSED', ticket_version: 3, history_count: 0, can_comment: false } } });
      return route.fulfill({ json: { success: true, data: [], items: [], unread_count: 0, pagination: { current_page: 1, last_page: 1, total: 0 } } });
    });
    try {
      await page.goto('/cu-dan?tab=tickets&ticket_id=rating-qa');
      const panel = page.getByRole('region', { name: 'Đánh giá sau xử lý sự cố', exact: true });
      await panel.getByRole('radio', { name: '4 sao', exact: true }).check();
      await panel.getByRole('button', { name: 'Gửi đánh giá', exact: true }).click();
      await expect(panel.getByRole('alert')).toContainText('QA retry');
      await panel.getByRole('button', { name: 'Tải lại đánh giá', exact: true }).click();
      await expect.poll(() => reads).toBe(2);
      await panel.getByRole('button', { name: 'Gửi đánh giá', exact: true }).click();
      await expect(panel.getByRole('status')).toContainText('4/5 sao');
      const staleResponse = page.waitForResponse(response => response.url().endsWith('/rating') && response.request().method() === 'GET');
      releaseRead();
      await (await staleResponse).finished();
      await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
      await expect(panel.getByRole('status')).toContainText('4/5 sao');
      await expect(panel.getByRole('button', { name: 'Gửi đánh giá', exact: true })).toHaveCount(0);
      expect(posts).toBe(2);
    } finally { releaseRead(); }
  });
}
