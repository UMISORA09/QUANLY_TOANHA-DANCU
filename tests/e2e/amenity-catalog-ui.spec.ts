import { test, expect, type Page } from '@playwright/test';

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
    const category = { id: 'category-qa', version: 1, category_name: 'Thể thao', category_code: 'SPORT', description: 'Dữ liệu QA', amenities_count: 1 };
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
    await expect(page.getByRole('option', { name: /Phòng tập cộng đồng/ })).toBeVisible();
    await searchInput.press('ArrowDown');
    await searchInput.press('Enter');
    await expect(searchInput).toHaveValue('Phòng tập cộng đồng');
    await expect(page.getByRole('listbox', { name: 'Tiện ích gợi ý' })).not.toBeVisible();
    await expect(page.getByRole('cell', { name: /Phòng tập cộng đồng/ }).first().locator('mark')).toContainText('Phòng tập cộng đồng');
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

test('F1: stale amenity edit preserves draft and reloads server revision automatically', async ({ page }) => {
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'QA' })); localStorage.setItem('smart_cassavas_token', 'qa-token'); });
  let amenity = { id: 'qa-occ', amenity_name: 'Tiện ích QA', amenity_code: 'QA_GYM', category_id: 'cat', category_name: 'Thể thao', block_id: null, location_detail: 'Tầng 1', max_capacity_per_slot: 10, hourly_rate: 10000, security_deposit_required: 0, advance_booking_days_limit: 7, min_cancel_hours_before: 12, requires_admin_approval: true, is_active: true, updated_at: '2026-11-01T08:00:00+07:00', created_at: '2026-11-01T08:00:00+07:00' };
  let writes = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/amenities/qa-occ')) {
      if (route.request().method() === 'PUT' || route.request().method() === 'PATCH') {
        writes++;
        expect(route.request().postDataJSON().updated_at).toBe(writes === 1 ? '2026-11-01T08:00:00+07:00' : '2026-11-01T08:00:01+07:00');
        if (writes === 1) { amenity = { ...amenity, amenity_name: 'Tên mới trên máy chủ', updated_at: '2026-11-01T08:00:01+07:00' }; return route.fulfill({ status: 409, json: { message: 'Tiện ích đã thay đổi' } }); }
      }
      return route.fulfill({ json: amenity });
    }
    if (path.endsWith('/amenities')) return route.fulfill({ json: { items: [amenity], total: 1, page: 1, limit: 15, total_pages: 1 } });
    if (path.endsWith('/amenity-categories')) return route.fulfill({ json: [{ id: 'cat', category_name: 'Thể thao', category_code: 'SPORT' }] });
    if (path.endsWith('/blocks')) return route.fulfill({ json: [] });
    return route.fulfill({ json: { data: [], items: [], unread_count: 0, counts: {} } });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByTitle('Chỉnh sửa cấu hình', { exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Cấu hình tiện ích' });
  const title = dialog.getByLabel('Tên tiện ích', { exact: true });
  await title.fill('Bản nhập của tôi'); await dialog.getByRole('button', { name: 'Lưu cấu hình' }).click();
  await expect(dialog.getByRole('region', { name: 'Bản tiện ích mới nhất' })).toContainText('Tên mới trên máy chủ');
  await expect(title).toHaveValue('Bản nhập của tôi'); expect(writes).toBe(1);
  await dialog.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' }).click();
  await dialog.getByRole('button', { name: 'Lưu cấu hình' }).click(); await expect(dialog).not.toBeVisible(); expect(writes).toBe(2);
});

function configurationFixture() {
  return {
    amenity: { id: 'cfg-amenity', amenity_name: 'Tiện ích cấu hình QA', amenity_code: 'CFG_QA', category_id: 'cfg-category', category_name: 'Danh mục QA', block_id: null, location_detail: 'Tầng 1', max_capacity_per_slot: 10, hourly_rate: 0, security_deposit_required: 0, advance_booking_days_limit: 7, min_cancel_hours_before: 12, requires_admin_approval: true, is_active: true, updated_at: '2026-11-01T08:00:00+07:00', created_at: '2026-11-01T08:00:00+07:00' },
    category: { id: 'cfg-category', version: 1, category_name: 'Danh mục QA', category_code: 'CFG_QA', description: 'Mô tả ban đầu', icon_name: 'Tag', amenities_count: 1 },
    slot: { id: 'cfg-slot', version: 1, amenity_id: 'cfg-amenity', day_of_week: 1, slot_start_time: '09:00', slot_end_time: '10:00', slot_label: 'Giờ ban đầu', max_bookings: 1, is_active: true },
    blackout: { id: 'cfg-blackout', version: 1, amenity_id: 'cfg-amenity', blackout_date: '2026-12-01', reason: 'Bảo trì ban đầu', start_time: null, end_time: null },
    categoryWrites: [] as number[], slotWrites: [] as number[], blackoutDeletes: [] as number[], failCategoryRead: false, failBlackoutRead: false, deletedBlackout: false,
  };
}
async function setupConfiguration(page: Page, state: ReturnType<typeof configurationFixture>) {
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'QA' })); localStorage.setItem('smart_cassavas_token', 'qa-configuration'); });
  await page.route('**/api/**', async (route) => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (path.endsWith('/amenity-categories/cfg-category') && request.method() === 'PUT') {
      const payload = request.postDataJSON(); state.categoryWrites.push(payload.version);
      if (state.categoryWrites.length === 1) {
        state.category = { ...state.category, version: 2, description: 'Nội dung mới trên máy chủ' }; state.failCategoryRead = true;
        return route.fulfill({ status: 409, json: { message: 'Danh mục đã thay đổi' } });
      }
      expect(payload.version).toBe(state.category.version);
      state.category = { ...state.category, ...payload, version: state.category.version + 1 };
      return route.fulfill({ json: state.category });
    }
    if (path.endsWith('/amenity-categories')) {
      if (state.failCategoryRead) { state.failCategoryRead = false; return route.fulfill({ status: 503, json: { message: 'QA mất mạng' } }); }
      return route.fulfill({ json: [state.category] });
    }
    if (path.endsWith('/time-slots/cfg-slot') && request.method() === 'PUT') {
      const payload = request.postDataJSON(); state.slotWrites.push(payload.version);
      if (state.slotWrites.length === 1) { state.slot = { ...state.slot, version: 2, slot_label: 'Giờ mới trên máy chủ' }; return route.fulfill({ status: 409, json: { message: 'Khung giờ đã thay đổi' } }); }
      expect(payload.version).toBe(state.slot.version);
      state.slot = { ...state.slot, ...payload, version: state.slot.version + 1 };
      return route.fulfill({ json: state.slot });
    }
    if (path.endsWith('/time-slots')) return route.fulfill({ json: [state.slot] });
    if (path.endsWith('/blackouts/cfg-blackout') && request.method() === 'DELETE') {
      const payload = request.postDataJSON(); state.blackoutDeletes.push(payload.version);
      if (state.blackoutDeletes.length === 1) { state.blackout = { ...state.blackout, version: 2, reason: 'Bảo trì đã thay đổi' }; state.failBlackoutRead = true; return route.fulfill({ status: 409, json: { message: 'Lịch bảo trì đã thay đổi' } }); }
      expect(payload.version).toBe(2); state.deletedBlackout = true; return route.fulfill({ json: { success: true } });
    }
    if (path.endsWith('/blackouts')) {
      if (state.failBlackoutRead) { state.failBlackoutRead = false; return route.fulfill({ status: 503, json: { message: 'QA mất mạng' } }); }
      return route.fulfill({ json: state.deletedBlackout ? [] : [state.blackout] });
    }
    if (path.endsWith('/amenities')) return route.fulfill({ json: { items: [state.amenity], total: 1, page: 1, limit: 15, total_pages: 1 } });
    if (path.endsWith('/blocks')) return route.fulfill({ json: [] });
    if (path.endsWith('/amenity-booking-worklist')) return route.fulfill({ json: { items: [], counts: { PENDING: 0, REPORTED: 0, REVIEW: 0 }, page: 1, total_pages: 1 } });
    return route.fulfill({ json: { items: [], data: [], unread_count: 0, user: { id: 'qa', full_name: 'QA', roles: ['BUILDING_MANAGER'] } } });
  });
  await page.goto('/quan-ly?tab=amenities');
}
for (const width of [390, 1280]) {
  test(`F1 category: failed conflict refresh keeps draft blocked until review ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const state = configurationFixture(); await setupConfiguration(page, state);
    await page.getByRole('button', { name: 'Quản lý danh mục', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Quản lý danh mục tiện ích' });
    await dialog.getByTitle('Chỉnh sửa', { exact: true }).click();
    const description = dialog.getByLabel('Mô tả danh mục'); await description.fill('Bản nhập cần giữ');
    const save = dialog.getByRole('button', { name: 'Cập nhật', exact: true }); await save.click();
    await expect(dialog.getByRole('alert')).toContainText('Chưa tải được danh mục mới');
    await expect(description).toHaveValue('Bản nhập cần giữ'); await expect(save).toBeDisabled(); expect(state.categoryWrites).toEqual([1]);
    await dialog.getByRole('button', { name: 'Tải lại bản mới', exact: true }).click();
    await expect(dialog.getByRole('region', { name: 'Bản danh mục mới nhất' })).toContainText('Nội dung mới trên máy chủ');
    await dialog.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' }).click();
    await save.click(); await expect.poll(() => state.categoryWrites).toEqual([1, 2]);
    await expect(dialog.getByRole('listitem').filter({ hasText: 'Bản nhập cần giữ' })).toBeVisible();
  });
  test(`F1 slot: conflict reload preserves edited label and uses reviewed version ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const state = configurationFixture(); await setupConfiguration(page, state);
    await page.getByTitle('Cấu hình khung giờ (Time Slots)', { exact: true }).click();
    await page.getByTitle('Chỉnh sửa', { exact: true }).click();
    const label = page.getByPlaceholder('VD: Khung giờ sáng 1, Ca trưa, Buổi tối gia đình...'); await label.fill('Nhãn đang nhập');
    const save = page.getByRole('button', { name: 'Cập nhật slot', exact: true }); await save.click();
    await expect(page.getByRole('region', { name: 'Bản khung giờ mới nhất' })).toContainText('Giờ mới trên máy chủ');
    await expect(label).toHaveValue('Nhãn đang nhập'); await expect(save).toBeDisabled(); expect(state.slotWrites).toEqual([1]);
    await page.getByRole('button', { name: 'Đã xem bản mới, tiếp tục sửa bản nhập' }).click();
    await save.click(); await expect.poll(() => state.slotWrites).toEqual([1, 2]);
    await expect(page.getByText(/^Nhãn đang nhập • Max/)).toBeVisible();
  });
}
test('F1 blackout: stale deletion reloads latest and never automatically deletes it', async ({ page }) => {
  const state = configurationFixture(); await setupConfiguration(page, state);
  await page.getByTitle('Ngày đóng cửa bảo trì', { exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Ngày Đóng cửa & Bảo trì' });
  const reason = dialog.getByLabel('Lý do đóng cửa / bảo trì'); await reason.fill('Lý do chưa gửi');
  page.once('dialog', (confirm) => confirm.accept()); await dialog.getByTitle('Xóa lịch bảo trì').click();
  await expect(dialog.getByRole('alert')).toContainText('QA mất mạng');
  expect(state.blackoutDeletes).toEqual([1]);
  await dialog.getByRole('button', { name: 'Tải lại danh sách', exact: true }).click();
  await expect(dialog.getByText('Bảo trì đã thay đổi', { exact: true })).toBeVisible();
  expect(state.blackoutDeletes).toEqual([1]); expect(state.deletedBlackout).toBe(false); await expect(reason).toHaveValue('Lý do chưa gửi');
  page.once('dialog', (confirm) => confirm.accept()); await dialog.getByTitle('Xóa lịch bảo trì').click();
  await expect.poll(() => state.blackoutDeletes).toEqual([1, 2]); expect(state.deletedBlackout).toBe(true);
});
