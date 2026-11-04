import { test, expect } from '@playwright/test';

for (const width of [1280, 390]) {
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
