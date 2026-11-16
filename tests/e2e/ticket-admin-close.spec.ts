import { test, expect } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });
for (const width of [375, 768, 1440]) test(`Admin đóng thay cư dân: lý do, lỗi mạng và 409 ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'QA Admin' }));
    localStorage.setItem('smart_cassavas_token', 'qa-admin-session');
  });
  let ticket = { id: 'qa-close', ticket_number: 'TCK-CLOSE-QA', title: 'Sự cố chờ nghiệm thu', description: 'Đã khắc phục xong', status: 'RESOLVED', priority: 'MEDIUM', version: 1, apartment: { apartment_number: 'QA-01' }, attachments: [], history: [], actions: { close: true } };
  const writes: Record<string, unknown>[] = [];
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/qa-close/workflow')) {
      const payload = route.request().postDataJSON(); writes.push(payload);
      if (writes.length === 1) return route.abort('failed');
      if (writes.length === 2) { ticket = { ...ticket, version: 2 }; return route.fulfill({ status: 409, json: { message: 'Yêu cầu đã thay đổi.' } }); }
      ticket = { ...ticket, status: 'CLOSED', version: 3, actions: { close: false } };
      return route.fulfill({ json: ticket });
    }
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
    if (path.endsWith('/tickets')) return route.fulfill({ json: { data: [ticket], current_page: 1, last_page: 1, total: 1, per_page: 15 } });
    if (path.endsWith('/qa-close')) return route.fulfill({ json: ticket });
    if (path.endsWith('/comments')) return route.fulfill({ json: { items: [], next_cursor: null, meta: { can_post: false, can_view_internal: true, can_post_internal: false, history_count: 0 } } });
    return route.fulfill({ json: { success: true, data: [], items: [], next_cursor: null, unread_count: 0 } });
  });
  await page.goto('/quan-ly?tab=tickets');
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).first().click();
  await page.getByRole('button', { name: 'Đóng thay cư dân', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Đóng thay cư dân', exact: true });
  const submit = dialog.getByRole('button', { name: 'Đóng thay cư dân', exact: true });
  const reason = dialog.getByRole('textbox');
  await expect(submit).toBeDisabled();
  await expect(dialog.getByRole('note')).toContainText('không phải xác nhận nghiệm thu');
  await reason.fill('QA cư dân đề nghị đóng thay');
  await submit.click(); await expect(submit).toBeEnabled();
  await expect(reason).toHaveValue('QA cư dân đề nghị đóng thay');
  await submit.click();
  await expect(dialog.getByText('Phiên bản máy chủ: 2', { exact: false })).toBeVisible();
  await expect(submit).toBeDisabled();
  await expect(reason).toHaveValue('QA cư dân đề nghị đóng thay');
  expect(writes).toHaveLength(2);
  await dialog.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập', exact: true }).click();
  await submit.click();
  await expect(dialog).toHaveCount(0);
  expect(writes.map(item => item.version)).toEqual([1, 1, 2]);
  expect(writes[2]).toMatchObject({ action: 'close', reason: 'QA cư dân đề nghị đóng thay' });
  await expect(page.getByRole('button', { name: 'Đóng thay cư dân', exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
