import { test, expect } from '@playwright/test';

const viewports = [{ width: 375, height: 812 }, { width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1024, height: 768 }, { width: 1440, height: 900 }];

for (const viewport of viewports) {
  test(`MOCKED E2E F1 catalog toolbar, category form and viewport ${viewport.width}`, async ({ page }, testInfo) => {
    await page.setViewportSize(viewport);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'UI QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'ui-qa-token');
      localStorage.setItem('smart_cassavas_user', JSON.stringify({ id: 'qa-manager', full_name: 'UI QA', roles: ['BUILDING_MANAGER'] }));
    });
    let search = '';
    let requestedSort = '';
    const category = { id: 'category-qa', category_name: 'Thể thao', category_code: 'SPORT', description: 'Dữ liệu QA', amenities_count: 1 };
    let categories = [category];
    const amenity = { id: 'amenity-qa', amenity_name: 'Phòng tập cộng đồng', amenity_code: 'GYM_QA', category_id: category.id, category_name: category.category_name, block_id: 'block-qa', block_name: 'Tòa A', location_detail: 'Tầng 2', max_capacity_per_slot: 12, hourly_rate: 50000, security_deposit_required: 100000, advance_booking_days_limit: 7, min_cancel_hours_before: 12, requires_admin_approval: true, is_active: true, updated_at: '2026-10-20T08:00:00+07:00', created_at: '2026-10-20T08:00:00+07:00', time_slots_count: 2, blackout_dates_count: 0 };
    await page.route('**/api/**', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/amenities')) {
        requestedSort = url.searchParams.get('sort') || '';
        search = url.searchParams.get('search') || url.searchParams.get('q') || '';
        return route.fulfill({ json: { items: [amenity], total: 1, page: 1, limit: 15, total_pages: 1 } });
      }
      if (url.pathname.includes('/amenity-categories')) {
        const method = route.request().method();
        if (method === 'POST') { const created = { ...category, ...route.request().postDataJSON(), id: 'category-created', amenities_count: 0 }; categories = [...categories, created]; return route.fulfill({ status: 201, json: created }); }
        if (method === 'PUT') { const updated = { ...categories.find((item) => item.id === 'category-created'), ...route.request().postDataJSON() }; categories = categories.map((item) => item.id === updated.id ? updated : item); return route.fulfill({ json: updated }); }
        if (method === 'DELETE') { categories = categories.filter((item) => item.id !== 'category-created'); return route.fulfill({ status: 204 }); }
        return route.fulfill({ json: categories });
      }
      if (url.pathname.endsWith('/blocks')) return route.fulfill({ json: [{ id: 'block-qa', block_name: 'Tòa A', block_code: 'A' }] });
      if (url.pathname.endsWith('/amenity-booking-worklist')) return route.fulfill({ json: { items: [], counts: { PENDING: 0, REPORTED: 0, REVIEW: 0 }, page: 1, total_pages: 1 } });
      return route.fulfill({ json: { items: [], data: [], user: { id: 'qa-manager', full_name: 'UI QA', roles: ['BUILDING_MANAGER'] } } });
    });
    await page.goto('/quan-ly?tab=amenities');
    await expect(page.getByRole('heading', { name: 'Danh mục Tiện ích Tòa nhà', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: /Phòng tập cộng đồng/ }).first()).toBeVisible();
    const searchInput = page.getByPlaceholder('Tìm theo tên tiện ích hoặc mã tiện ích (VD: GYM, BƠI)...');
    await searchInput.fill('Phòng tập');
    await expect.poll(() => search).toBe('Phòng tập');
    await expect.poll(() => requestedSort).toBe('relevance');
    if (process.env.UI_AUDIT_PHASE === 'before') {
      await page.getByRole('button', { name: 'Tất cả danh mục', exact: true }).click();
      await page.getByRole('button', { name: 'Thể thao', exact: true }).click();
    } else {
      const categorySelect = page.getByRole('combobox', { name: 'Tất cả danh mục', exact: true });
      await categorySelect.selectOption('category-qa');
      await categorySelect.focus();
      await page.keyboard.press('Space');
      await page.keyboard.press('Home');
      await page.keyboard.press('Enter');
      await page.keyboard.press('Tab');
      await expect(categorySelect).toHaveValue('');
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`catalog-${process.env.UI_AUDIT_PHASE || 'after'}.png`) });
    await page.getByRole('button', { name: 'Quản lý danh mục', exact: true }).click();
    await expect(page.getByRole('heading', { name: /danh mục/i }).last()).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`category-${process.env.UI_AUDIT_PHASE || 'after'}.png`) });
    if (process.env.UI_AUDIT_PHASE !== 'before' && viewport.width === 1440) {
      const dialog = page.getByRole('dialog', { name: 'Quản lý danh mục tiện ích', exact: true });
      await dialog.getByLabel('Mã danh mục', { exact: true }).fill('REST_QA');
      await dialog.getByLabel('Tên danh mục', { exact: true }).fill('Nghỉ ngơi QA');
      await dialog.getByRole('button', { name: 'Thêm danh mục', exact: true }).click();
      let row = dialog.getByRole('listitem').filter({ hasText: 'Nghỉ ngơi QA' });
      await expect(row).toBeVisible();
      await row.getByTitle('Chỉnh sửa', { exact: true }).click();
      await dialog.getByLabel('Tên danh mục', { exact: true }).fill('Nghỉ ngơi QA sửa');
      await dialog.getByRole('button', { name: 'Cập nhật', exact: true }).click();
      row = dialog.getByRole('listitem').filter({ hasText: 'Nghỉ ngơi QA sửa' });
      await expect(row).toBeVisible();
      page.once('dialog', (confirmation) => confirmation.accept());
      await row.getByTitle('Xóa danh mục', { exact: true }).click();
      await expect(row).toHaveCount(0);
    }
    if (process.env.UI_AUDIT_PHASE === 'before') await page.getByRole('button', { name: 'Đóng', exact: true }).last().click();
    else {
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog', { name: 'Quản lý danh mục tiện ích', exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Quản lý danh mục', exact: true })).toBeFocused();
    }
    await page.getByRole('button', { name: 'Thêm tiện ích', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Thêm tiện ích mới/i })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`form-${process.env.UI_AUDIT_PHASE || 'after'}.png`) });
    if (process.env.UI_AUDIT_PHASE !== 'before') {
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog', { name: 'Cấu hình tiện ích', exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Thêm tiện ích', exact: true })).toBeFocused();
    }
  });
}
