import { test, expect } from '@playwright/test';

for (const width of [375, 768, 1440]) {
  test(`MOCKED F12: nhãn, bàn phím, giữ bản nhập và mã ẩn danh (${width}px)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'Cư dân QA' }));
      localStorage.setItem('smart_cassavas_token', 'qa-f12-mock');
    });
    let failSubmit = true;
    await page.route('**/api/**', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      if (path.endsWith('/resident/suggestion-boxes')) return route.fulfill({ json: { data: Array.from({ length: 20 }, (_, i) => ({ id: `box-${i}`, box_name: `Hòm thư ${i}`, block: null })) } });
      if (path.endsWith('/resident/suggestions') && request.method() === 'POST') {
        return failSubmit
          ? route.fulfill({ status: 503, json: { message: 'Tạm mất kết nối QA' } })
          : route.fulfill({ status: 201, json: { success: true, anonymous_token: 'SC-QA-ONLY', data: {} } });
      }
      return route.fulfill({ json: { data: [], items: [], unread_count: 0, pagination: { total: 0 } } });
    });
    await page.goto('/cu-dan?tab=feedback');
    const box = page.getByLabel('Hòm thư tiếp nhận', { exact: true });
    await expect(box).toContainText('Hòm thư 0');
    await box.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('listbox'); await expect(menu).toBeVisible();
    expect(await menu.evaluate((element) => { const r = element.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight; })).toBe(true);
    expect(await menu.locator('.overflow-y-auto').evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
    await menu.getByRole('option').first().focus();
    await page.keyboard.press('End'); await page.keyboard.press('Enter');
    await expect(box).toContainText('Hòm thư 19'); await expect(box).toBeFocused();
    await page.getByLabel('Chủ đề góp ý', { exact: true }).click();
    await page.getByRole('option', { name: 'An ninh & Trật tự', exact: true }).click();
    const title = page.getByLabel('Tiêu đề góp ý', { exact: false });
    await title.fill('Đề xuất cải thiện an ninh');
    await page.getByLabel('Nội dung chi tiết', { exact: false }).fill('Bổ sung chiếu sáng ở khu vực sảnh.');
    await page.getByRole('checkbox', { name: /Gửi ẩn danh/ }).check();
    const submit = page.getByRole('button', { name: 'Gửi ý kiến góp ý', exact: true });
    await submit.click(); await expect(page.getByText('Tạm mất kết nối QA', { exact: true })).toBeVisible();
    await expect(title).toHaveValue('Đề xuất cải thiện an ninh');
    failSubmit = false; await submit.click();
    const dialog = page.getByRole('dialog', { name: 'Gửi Góp Ý Ẩn Danh Thành Công!', exact: true });
    await expect(dialog).toBeVisible(); await expect(dialog).toContainText('SC-QA-ONLY');
    await page.keyboard.press('Escape'); await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Tôi đã lưu mã tra cứu', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByLabel('Mã tra cứu góp ý ẩn danh', { exact: true })).toHaveValue('SC-QA-ONLY');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}

for (const width of [375, 768, 1440]) test(`F12 manager: 409 automatically reloads detail and preserves unsent reply ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'QA' })); localStorage.setItem('smart_cassavas_token', 'qa-token'); });
  let item = { id: 'qa-suggestion', title: 'Góp ý QA', content: 'Nội dung góp ý', content_text: 'Nội dung góp ý', topic_category: 'OTHER', status: 'RECEIVED', version: 1, is_anonymous: true, created_at: '2026-11-01T00:00:00Z', official_response_text: '' };
  let writes = 0; let reads = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/qa-suggestion/reply')) {
      writes++; const payload = route.request().postDataJSON();
      expect(payload.version).toBe(writes === 1 ? 1 : 2);
      if (writes === 1) { item = { ...item, version: 2, official_response_text: 'Phản hồi mới trên máy chủ' }; return route.fulfill({ status: 409, json: { message: 'Góp ý đã được cập nhật.' } }); }
      item = { ...item, official_response_text: payload.official_response_text, version: 3 };
      return route.fulfill({ json: { success: true, data: item } });
    }
    if (path.endsWith('/qa-suggestion')) { reads++; return route.fulfill({ json: { success: true, data: item } }); }
    if (path.endsWith('/management/suggestions')) return route.fulfill({ json: { data: [item], pagination: { last_page: 1, total: 1 } } });
    return route.fulfill({ json: { data: [], items: [], unread_count: 0 } });
  });
  await page.goto('/quan-ly?tab=feedback');
  const status = page.getByRole('button', { name: 'Trạng thái góp ý', exact: true });
  await status.click();
  const menu = page.getByRole('listbox');
  expect(await menu.evaluate(element => getComputedStyle(element).borderRadius)).toBe('12px');
  await page.getByRole('option', { name: 'Chờ tiếp nhận', exact: true }).click();
  const open = page.getByRole('button', { name: 'Xử lý', exact: true });
  await open.click();
  const dialog = page.getByRole('dialog', { name: 'Chi tiết góp ý', exact: true });
  const replyStatus = dialog.getByRole('button', { name: 'Trạng thái sau phản hồi', exact: true });
  await replyStatus.click();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  await expect(replyStatus).toBeFocused();
  const reply = page.getByPlaceholder('Nhập nội dung phản hồi chính thức gửi tới cư dân (văn minh, chuẩn mực, nêu rõ giải pháp hoặc tiến độ xử lý)...');
  await reply.fill('Bản trả lời đang soạn');
  const submit = page.getByRole('button', { name: 'Lưu & Gửi phản hồi chính thức' });
  await submit.click();
  await expect(page.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' })).toBeVisible();
  await expect(reply).toHaveValue('Bản trả lời đang soạn'); await expect(submit).toBeDisabled();
  expect(reads).toBe(2); expect(writes).toBe(1);
  await page.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' }).click();
  await submit.click(); await expect.poll(() => writes).toBe(2);
  await expect(submit).toBeEnabled();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(open).toBeFocused();
});

for (const mode of ['empty', 'network']) test('F12: unavailable boxes show a useful message and retry preserves draft ' + mode, async ({ page }) => {
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'QA' })); localStorage.setItem('smart_cassavas_token', 'qa-boxes'); });
  let ready = false;
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/resident/suggestion-boxes')) return route.fulfill(ready ? { json: { success: true, data: [{ id: 'qa-box', box_name: 'Hòm thư toàn khu QA', description: '', block: null }] } } : mode === 'empty' ? { json: { success: true, data: [] } } : { status: 503, json: { message: 'Chưa tải được hòm thư QA' } });
    return route.fulfill({ json: { data: [], items: [], unread_count: 0, pagination: { total: 0 } } });
  });
  await page.goto('/cu-dan?tab=feedback');
  const box = page.getByRole('button', { name: 'Hòm thư tiếp nhận', exact: true });
  await expect(box).toBeDisabled();
  const submit = page.getByRole('button', { name: 'Gửi ý kiến góp ý', exact: true }); await expect(submit).toBeDisabled();
  const title = page.getByPlaceholder('VD: Đề xuất bổ sung thêm thùng rác phân loại tại sảnh thang máy Block A');
  await title.fill('Bản nhập cần giữ QA');
  ready = true;
  await page.getByRole('button', { name: mode === 'empty' ? 'Tải lại hòm thư' : 'Thử tải lại hòm thư', exact: true }).click();
  await expect(box).toBeEnabled(); await expect(box).toContainText('Hòm thư toàn khu QA');
  await expect(title).toHaveValue('Bản nhập cần giữ QA'); await expect(submit).toBeEnabled();
});
