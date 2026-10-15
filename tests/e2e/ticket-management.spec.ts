import { test, expect } from '@playwright/test';

for (const mobile of [false, true]) {
  test(`Ticket quản lý: form, combobox bàn phím và phiên bản ${mobile ? 'mobile' : 'desktop'}`, async ({ page }) => {
    await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    });
    let ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-001', title: 'Kiểm tra đường nước', description: 'Sự cố cần hỗ trợ tại bếp.', location: 'Phòng bếp', category_id: 'cat', priority: 'HIGH', status: 'NEW', version: 1, created_at: '2026-10-14T08:00:00Z', apartment: { apartment_number: 'QA-01' }, history: [], attachments: [], actions: { edit: true, archive: false } };
    const payloads: Record<string, unknown>[] = [];
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [{ id: 'cat', category_name: 'Điện nước' }], blocks: [{ id: 'block', block_name: 'Tòa QA' }], apartments: [{ id: 'apt', block_id: 'block', apartment_number: 'QA-01' }], residents: [{ id: 'resident', apartment_id: 'apt', full_name: 'Cư dân QA' }], can_create: true } });
      if (path.endsWith('/tickets/qa-ticket')) {
        if (route.request().method() === 'PATCH') {
          const data = route.request().postDataJSON(); payloads.push(data);
          ticket = { ...ticket, ...data, version: ticket.version + 1 };
        }
        return route.fulfill({ json: ticket });
      }
      if (path.endsWith('/tickets')) return route.fulfill({ json: { data: [ticket], current_page: 1, last_page: 1, total: 1 } });
      return route.fulfill({ json: { items: [], unread_count: 0, page: 1, total_pages: 1 } });
    });
    await page.goto('/admin?tab=tickets');
    await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
    await page.getByRole('button', { name: 'Sửa / Phân loại', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Sửa / Phân loại yêu cầu' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Tiêu đề', { exact: true }).fill('Đã bổ sung chi tiết');
    const priority = dialog.getByLabel('Mức độ ưu tiên', { exact: true });
    await priority.focus(); await priority.press('ArrowDown');
    await page.getByRole('option', { name: 'Khẩn cấp', exact: true }).click();
    await expect(priority).toBeFocused();
    await dialog.getByRole('button', { name: 'Lưu yêu cầu', exact: true }).click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(payloads[0]).toMatchObject({ version: 1, priority: 'URGENT', title: 'Đã bổ sung chi tiết' });
    await expect(page.getByRole('dialog', { name: 'TCK-QA-001' })).toContainText('Đã bổ sung chi tiết');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Thêm yêu cầu', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Thêm yêu cầu hộ cư dân' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
