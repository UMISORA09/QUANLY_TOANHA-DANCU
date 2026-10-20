import { test, expect } from '@playwright/test';

for (const resident of [false]) {
  test(`MOCKED E2E: ${resident ? 'cư dân' : 'quản lý'} phân trang 35 yêu cầu và thu hẹp trang cuối`, async ({ page }) => {
    await page.addInitScript((role) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Ticket QA' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    }, resident ? 'resident' : 'admin');
    let count = 35;
    const requests: number[] = [];
    await page.route('**/api/**', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/tickets')) {
        const current = Number(url.searchParams.get('page') || 1);
        requests.push(current);
        const filtered = url.searchParams.has('search') && !!url.searchParams.get('search') || url.searchParams.has('status');
        const total = filtered ? 1 : count;
        const data = Array.from({ length: total }, (_, i) => ({ id: `qa-${i}`, ticket_number: `TCK-PAGE-${i}`, title: `Yêu cầu phân trang ${i}`, status: 'NEW', priority: 'HIGH', created_at: '2026-10-19T08:00:00Z', actions: {} })).slice((current - 1) * 15, current * 15);
        const pagination = { current_page: current, last_page: Math.max(1, Math.ceil(total / 15)), total, per_page: 15 };
        return route.fulfill({ json: { success: true, data, ...pagination, pagination } });
      }
      if (url.pathname.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
      return route.fulfill({ json: { success: true, data: [], items: [], unread_count: 0, page: 1, total_pages: 1 } });
    });
    await page.goto(resident ? '/cu-dan?tab=tickets' : '/admin?tab=tickets');
    const rows = page.getByRole('button', { name: 'Xem chi tiết', exact: true }).filter({ visible: true });
    const next = page.getByRole('button', { name: resident ? 'Trang yêu cầu tiếp theo' : 'Sau', exact: true });
    const previous = page.getByRole('button', { name: resident ? 'Trang yêu cầu trước' : 'Trước', exact: true });
    await expect(rows).toHaveCount(15);
    await expect(previous).toBeDisabled();
    await next.click(); await expect.poll(() => requests.at(-1)).toBe(2); await expect(rows).toHaveCount(15);
    await next.click(); await expect.poll(() => requests.at(-1)).toBe(3); await expect(rows).toHaveCount(5); await expect(next).toBeDisabled();
    await previous.click(); await expect(rows).toHaveCount(15);
    await next.click(); await expect(rows).toHaveCount(5);
    const search = resident ? page.locator('#ticket-search-input') : page.getByRole('textbox', { name: 'Tìm yêu cầu' });
    await search.fill('điện nước'); await expect(rows).toHaveCount(1);
    await expect.poll(() => requests.at(-1)).toBe(1);
    await page.getByRole('button', { name: 'Xóa bộ lọc', exact: true }).click();
    await expect(rows).toHaveCount(15);
    await next.click(); await expect(rows).toHaveCount(15);
    await next.click(); await expect(rows).toHaveCount(5);
    if (!resident) await page.getByRole('button', { name: 'Bộ lọc', exact: true }).click();
    await page.getByLabel(resident ? 'Trạng thái yêu cầu' : 'Trạng thái', { exact: true }).click();
    const filteredRequest = page.waitForRequest((req) => new URL(req.url()).searchParams.get('status') === 'NEW');
    await page.getByRole('option', { name: 'Mới gửi', exact: true }).click();
    expect(new URL((await filteredRequest).url()).searchParams.get('page')).toBe('1');
    await expect(rows).toHaveCount(1);
    await page.getByRole('button', { name: 'Xóa bộ lọc', exact: true }).click();
    await expect(rows).toHaveCount(15);
    await next.click(); await expect(rows).toHaveCount(15);
    await next.click(); await expect(rows).toHaveCount(5);
    count = 30;
    await page.getByRole('button', { name: resident ? 'Làm mới' : 'Tải lại', exact: true }).click();
    await expect(rows).toHaveCount(15);
    await expect.poll(() => requests.at(-1)).toBe(2);
    expect(requests).toEqual([1, 2, 3, 2, 3, 1, 1, 2, 3, 1, 1, 2, 3, 3, 2]);
    await search.fill('điện nước');
    await expect(rows).toHaveCount(1);
    await expect.poll(() => requests.at(-1)).toBe(1);
  });

  test(`MOCKED E2E: ${resident ? 'cư dân' : 'quản lý'} debounce, phản hồi cũ, bộ lọc và lỗi mạng`, async ({ page }) => {
    await page.addInitScript((role) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Ticket QA' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    }, resident ? 'resident' : 'admin');
    let releaseOld!: () => void;
    const oldResponse = new Promise<void>((resolve) => { releaseOld = resolve; });
    const searches: string[] = [];
    let error = false;
    await page.route('**/api/**', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/tickets')) {
        const search = url.searchParams.get('search') || '';
        searches.push(search);
        if (search === 'điện') await oldResponse;
        if (error) return route.fulfill({ status: 503, json: { message: 'QA mất kết nối' } });
        const data = search === 'không có' ? [] : [{ id: 'qa', ticket_number: 'TCK-SEARCH', title: search === 'điện' ? 'Kết quả cũ' : 'Kết quả điện nước mới nhất', priority: 'HIGH', status: 'NEW', created_at: '2026-10-19T08:00:00Z', actions: {} }];
        const pagination = { current_page: 1, last_page: 1, total: data.length, per_page: 15 };
        return route.fulfill({ json: { success: true, data, ...pagination, pagination } }).catch(() => {});
      }
      if (url.pathname.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [{ id: 'cat', category_name: 'Điện nước' }], blocks: [], apartments: [], residents: [], can_create: false } });
      return route.fulfill({ json: { success: true, data: [{ id: 'cat', category_name: 'Điện nước' }], items: [], unread_count: 0, page: 1, total_pages: 1 } });
    });
    await page.goto(resident ? '/cu-dan?tab=tickets' : '/admin?tab=tickets');
    const search = resident ? page.locator('#ticket-search-input') : page.getByRole('textbox', { name: 'Tìm yêu cầu' });
    await expect(page.getByText('Kết quả điện nước mới nhất', { exact: true }).filter({ visible: true })).toBeVisible();
    await search.fill('điện');
    await expect.poll(() => searches.includes('điện')).toBe(true);
    await search.fill('điện nước');
    await expect.poll(() => searches.includes('điện nước')).toBe(true);
    await expect(page.getByText('Kết quả điện nước mới nhất', { exact: true }).filter({ visible: true })).toBeVisible();
    releaseOld();
    await expect(page.getByText('Kết quả cũ', { exact: true })).toHaveCount(0);
    expect(searches).toEqual(['', 'điện', 'điện nước']);
    if (!resident) await page.getByRole('button', { name: 'Bộ lọc', exact: true }).click();
    await page.getByLabel(resident ? 'Mức độ ưu tiên' : 'Ưu tiên', { exact: true }).click();
    const request = page.waitForRequest((req) => new URL(req.url()).searchParams.get('priority') === 'HIGH');
    await page.getByRole('option', { name: resident ? 'Ưu tiên cao' : 'Cao', exact: true }).click();
    expect(new URL((await request).url()).searchParams.get('search')).toBe('điện nước');
    await page.getByRole('button', { name: 'Xóa bộ lọc', exact: true }).click();
    await expect(search).toHaveValue('');
    await expect.poll(() => searches.at(-1)).toBe('');
    error = true;
    await search.fill('lỗi mạng');
    await expect(page.getByText('QA mất kết nối', { exact: false }).first()).toBeVisible();
    await expect(search).toHaveValue('lỗi mạng');
    error = false;
    await page.getByRole('button', { name: 'Thử lại', exact: true }).click();
    await expect(page.getByText('Kết quả điện nước mới nhất', { exact: true }).filter({ visible: true })).toBeVisible();
    await search.fill('không có');
    await expect(page.getByText(resident ? 'Không có yêu cầu phù hợp' : 'Chưa có yêu cầu phù hợp.', { exact: true })).toBeVisible();
  });
}

for (const [width, height] of [[375, 812], [390, 844], [768, 1024], [1024, 768], [1440, 900]]) {
  test(`Ticket quản lý: form, combobox bàn phím và phiên bản ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    });
    let ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-001', title: 'Kiểm tra đường nước', description: 'Sự cố cần hỗ trợ tại bếp.', location: 'Phòng bếp', category_id: 'cat', priority: 'HIGH', status: 'NEW', version: 1, created_at: '2026-10-14T08:00:00Z', apartment: { apartment_number: 'QA-01' }, history: [], attachments: [], actions: { edit: true, archive: false, assign: true } };
    const payloads: Record<string, unknown>[] = [];
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [{ id: 'cat', category_name: 'Điện nước' }, ...Array.from({ length: 30 }, (_, i) => ({ id: 'cat-' + i, category_name: 'Danh mục QA ' + i }))], blocks: [{ id: 'block', block_name: 'Tòa QA' }, { id: 'block-2', block_name: 'Tòa QA 2' }], apartments: [{ id: 'apt', block_id: 'block', apartment_number: 'QA-01' }, { id: 'apt-2', block_id: 'block-2', apartment_number: 'QA-02' }], residents: [{ id: 'resident', apartment_id: 'apt', full_name: 'Cư dân QA' }, { id: 'resident-2', apartment_id: 'apt-2', full_name: 'Cư dân QA 2' }], can_create: true } });
      if (path.endsWith('/tickets/qa-ticket/technicians')) return route.fulfill({ json: Array.from({ length: 30 }, (_, i) => ({ id: 'tech-' + i, full_name: 'Kỹ thuật QA ' + i })) });
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
    const filterToggle = page.getByRole('button', { name: 'Bộ lọc', exact: true });
    await expect(filterToggle).toHaveAttribute('aria-expanded', 'false');
    await filterToggle.click();
    await page.getByLabel('Trạng thái', { exact: true }).click();
    await page.getByRole('option', { name: 'Mới gửi', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Bộ lọc (1)', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Xóa bộ lọc', exact: true }).click();
    await expect(page.getByLabel('Trạng thái', { exact: true })).toContainText('Tất cả');
    await filterToggle.click();
    await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
    await page.getByRole('button', { name: 'Sửa / Phân loại', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Sửa / Phân loại yêu cầu' });
    await expect(dialog).toBeVisible();
    const category = dialog.getByLabel('Danh mục', { exact: true });
    await category.click();
    const menu = page.getByRole('listbox');
    await expect.poll(() => menu.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.top >= 0 && rect.bottom <= innerHeight && rect.left >= 0 && rect.right <= innerWidth && document.elementFromPoint(rect.left + rect.width / 2, rect.bottom - 8)?.closest('[role="listbox"]') === element;
    })).toBe(true);
    await category.press('Home');
    await expect(page.getByRole('option', { name: 'Điện nước', exact: true })).toBeFocused();
    await category.press('End');
    const lastCategory = page.getByRole('option', { name: 'Danh mục QA 29', exact: true });
    await expect(lastCategory).toBeFocused();
    await lastCategory.click();
    await expect(category).toContainText('Danh mục QA 29');
    await category.click();
    await dialog.locator('.overflow-y-auto').first().evaluate((element) => element.scrollBy(0, -60));
    await page.setViewportSize({ width, height: height - 20 });
    await expect.poll(() => menu.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.top >= 0 && rect.bottom <= innerHeight && document.elementFromPoint(rect.left + 10, rect.top + 10)?.closest('[role="listbox"]') === element;
    })).toBe(true);
    await dialog.getByRole('heading', { name: 'Sửa / Phân loại yêu cầu', exact: true }).click();
    await expect(menu).toHaveCount(0);
    await category.click();
    await category.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(category).toBeFocused();
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
    await page.getByRole('button', { name: 'Phân công kỹ thuật', exact: true }).click();
    const assignment = page.getByRole('dialog', { name: 'Phân công kỹ thuật', exact: true });
    const technician = assignment.getByLabel('Kỹ thuật viên', { exact: true });
    await technician.click();
    await expect.poll(() => page.getByRole('listbox').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return document.elementFromPoint(rect.left + rect.width / 2, rect.bottom - 8)?.closest('[role="listbox"]') === element;
    })).toBe(true);
    await technician.press('End');
    await page.getByRole('option', { name: 'Kỹ thuật QA 29', exact: true }).click();
    await expect(technician).toContainText('Kỹ thuật QA 29');
    await assignment.getByRole('button', { name: 'Đóng', exact: true }).click();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Thêm yêu cầu', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Thêm yêu cầu hộ cư dân' })).toBeVisible();
    const create = page.getByRole('dialog', { name: 'Thêm yêu cầu hộ cư dân' });
    await expect(create.getByLabel('Căn hộ', { exact: true })).toBeDisabled();
    await expect(create.getByLabel('Người báo', { exact: true })).toBeDisabled();
    await expect(create.getByRole('button', { name: 'Lưu yêu cầu', exact: true })).toBeDisabled();
    await create.getByLabel('Tòa nhà', { exact: true }).click();
    await page.getByRole('option', { name: 'Tòa QA', exact: true }).click();
    await create.getByLabel('Căn hộ', { exact: true }).click();
    await page.getByRole('option', { name: 'QA-01', exact: true }).click();
    await create.getByLabel('Người báo', { exact: true }).click();
    await page.getByRole('option', { name: 'Cư dân QA', exact: true }).click();
    await expect(create.getByRole('button', { name: 'Lưu yêu cầu', exact: true })).toBeEnabled();
    await create.getByLabel('Tòa nhà', { exact: true }).click();
    await page.getByRole('option', { name: 'Tòa QA 2', exact: true }).click();
    await expect(create.getByLabel('Căn hộ', { exact: true })).toContainText('Chọn một mục');
    await expect(create.getByLabel('Người báo', { exact: true })).toBeDisabled();
    await create.getByLabel('Căn hộ', { exact: true }).click();
    await expect(page.getByRole('option', { name: 'QA-01', exact: true })).toHaveCount(0);
    await page.getByRole('option', { name: 'QA-02', exact: true }).click();
    await create.getByLabel('Người báo', { exact: true }).click();
    await page.getByRole('option', { name: 'Cư dân QA 2', exact: true }).click();

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}

test('Ticket: chặn phân công thiếu người, giữ ghi chú khi lỗi và yêu cầu xem lại khi 409', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-001', title: 'Kiểm tra đường nước', description: 'Cần kiểm tra đường nước tại bếp.', location: 'Bếp', category_id: 'cat', priority: 'HIGH', status: 'NEW', version: 1, created_at: '2026-10-16T08:00:00Z', apartment: { apartment_number: 'QA-01' }, attachments: [], history: [], actions: { edit: true, assign: true, cancel: true } };
  let writes = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [{ id: 'cat', category_name: 'Điện nước' }], blocks: [], apartments: [], residents: [], can_create: true } });
    if (path.endsWith('/technicians')) return route.fulfill({ json: [] });
    if (path.endsWith('/workflow')) { writes++; return route.fulfill({ status: 422, json: { message: 'Chưa thể hủy yêu cầu. Vui lòng thử lại.' } }); }
    if (path.endsWith('/tickets/qa-ticket')) {
      if (route.request().method() === 'PATCH') {
        writes++; ticket = { ...ticket, title: 'Nội dung từ người phụ trách', version: 2 };
        return route.fulfill({ status: 409, json: { message: 'Yêu cầu đã thay đổi.' } });
      }
      return route.fulfill({ json: ticket });
    }
    if (path.endsWith('/tickets')) return route.fulfill({ json: { data: [ticket], current_page: 1, last_page: 1, total: 1 } });
    return route.fulfill({ json: { items: [], unread_count: 0, page: 1, total_pages: 1 } });
  });
  await page.goto('/admin?tab=tickets');
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
  await page.getByRole('button', { name: 'Phân công kỹ thuật', exact: true }).click();
  const assignment = page.getByRole('dialog', { name: 'Phân công kỹ thuật', exact: true });
  await expect(assignment.getByRole('button', { name: 'Phân công kỹ thuật', exact: true })).toBeDisabled();
  await expect(assignment).toContainText('Chưa có kỹ thuật viên đủ quyền');
  await assignment.getByRole('button', { name: 'Đóng', exact: true }).click();
  await page.getByRole('button', { name: 'Hủy yêu cầu', exact: true }).click();
  const cancellation = page.getByRole('dialog', { name: 'Hủy yêu cầu', exact: true });
  const confirm = cancellation.getByRole('button', { name: 'Hủy yêu cầu', exact: true });
  await expect(confirm).toBeDisabled();
  await cancellation.getByRole('textbox').fill('Đã kiểm tra, cư dân yêu cầu dừng.');
  await confirm.click();
  await expect(cancellation.getByRole('alert')).toContainText('Chưa thể hủy');
  await expect(cancellation.getByRole('textbox')).toHaveValue('Đã kiểm tra, cư dân yêu cầu dừng.');
  await cancellation.getByRole('button', { name: 'Đóng', exact: true }).click();
  await page.getByRole('button', { name: 'Sửa / Phân loại', exact: true }).click();
  const edit = page.getByRole('dialog', { name: 'Sửa / Phân loại yêu cầu', exact: true });
  await edit.getByLabel('Tiêu đề', { exact: true }).fill('Nội dung đang nhập');
  await edit.getByRole('button', { name: 'Lưu yêu cầu', exact: true }).click();
  await expect(edit).toContainText('Yêu cầu đã được người khác cập nhật');
  await expect(edit.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Nội dung đang nhập');
  await expect(edit.getByRole('button', { name: 'Lưu yêu cầu', exact: true })).toBeDisabled();
  expect(writes).toBe(2);
  await edit.getByRole('button', { name: 'Xem bản mới nhất', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'TCK-QA-001', exact: true })).toContainText('Nội dung từ người phụ trách');
});

test('Ticket: tải lại ảnh lỗi HTTP, ảnh hỏng và đóng ảnh phóng to bằng bàn phím', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let attempts = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/attachments/photo/content')) {
      attempts++;
      if (attempts === 1) return route.fulfill({ status: 503 });
      if (attempts === 2) return route.fulfill({ contentType: 'image/png', body: 'QA corrupted image' });
      return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNy0AAAAASUVORK5CYII=', 'base64') });
    }
    const ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-001', title: 'Kiểm tra ảnh hiện trường', description: 'Ảnh sự cố QA.', status: 'NEW', priority: 'HIGH', created_at: '2026-10-16T08:00:00Z', actions: {}, attachments: [{ id: 'photo', file_name: 'anh-qa.png', file_url: '/api/v1/resident/tickets/qa-ticket/attachments/photo/content', file_type: 'IMAGE', is_resolution_proof: true }] };
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
    if (path.endsWith('/tickets/qa-ticket')) return route.fulfill({ json: ticket });
    if (path.endsWith('/tickets')) return route.fulfill({ json: { data: [ticket], current_page: 1, last_page: 1, total: 1 } });
    return route.fulfill({ json: { items: [], unread_count: 0, page: 1, total_pages: 1 } });
  });
  await page.goto('/admin?tab=tickets');
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
  await page.getByRole('button', { name: 'Thử tải lại ảnh anh-qa.png', exact: true }).click();
  await page.getByRole('button', { name: 'Thử tải lại ảnh anh-qa.png', exact: true }).click();
  await page.getByRole('button', { name: 'Xem ảnh anh-qa.png', exact: true }).click();
  const preview = page.getByRole('dialog', { name: 'Ảnh sự cố', exact: true });
  await expect(preview.getByRole('img')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(preview).toHaveCount(0);
  await expect(page.getByRole('dialog', { name: 'TCK-QA-001', exact: true })).toContainText('Kết quả xử lý');
  expect(attempts).toBe(3);
});

test('MOCKED E2E: quản lý phải xác nhận trước khi bỏ ảnh đã lưu', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Ticket QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let deletes = 0;
  const ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-PHOTO', title: 'Ảnh cần xác nhận', status: 'NEW', priority: 'HIGH', version: 4, created_at: '2026-10-19T08:00:00Z', actions: { edit: true }, attachments: [{ id: 'photo', file_name: 'anh-qa.png', file_url: '/api/v1/resident/tickets/qa-ticket/attachments/photo/content', file_type: 'IMAGE', is_resolution_proof: false }] };
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (route.request().method() === 'DELETE') {
      deletes++;
      expect(route.request().postDataJSON()).toEqual({ version: 4 });
      return route.fulfill({ json: { ...ticket, attachments: [], version: 5 } });
    }
    if (path.endsWith('/content')) return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNy0AAAAASUVORK5CYII=', 'base64') });
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
    if (path.endsWith('/tickets/qa-ticket')) return route.fulfill({ json: ticket });
    if (path.endsWith('/tickets')) return route.fulfill({ json: { data: [ticket], current_page: 1, last_page: 1, total: 1 } });
    return route.fulfill({ json: { items: [], unread_count: 0, page: 1, total_pages: 1 } });
  });
  await page.goto('/admin?tab=tickets');
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
  await page.getByRole('button', { name: 'Bỏ ảnh anh-qa.png', exact: true }).click();
  const confirm = page.getByRole('dialog', { name: 'Bỏ ảnh sự cố', exact: true });
  await expect(confirm).toBeVisible(); expect(deletes).toBe(0);
  await confirm.getByRole('button', { name: 'Đóng', exact: true }).click();
  expect(deletes).toBe(0);
  await page.getByRole('button', { name: 'Bỏ ảnh anh-qa.png', exact: true }).click();
  await confirm.getByRole('button', { name: 'Xác nhận bỏ ảnh', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'TCK-QA-PHOTO', exact: true })).toContainText('Chưa có ảnh đính kèm.');
  expect(deletes).toBe(1);
});

test('Thông báo đầu tiên tự làm mới danh sách sau khi trước đó chưa có thông báo', async ({ page }) => {
  await page.clock.install();
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let notifications = 0;
  let lists = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/manager/notifications')) {
      notifications++;
      return route.fulfill({ json: { items: notifications === 1 ? [] : [{ id: 'notification-qa', title: 'Yêu cầu mới', body_message: 'Có yêu cầu cần tiếp nhận.', is_read: false, deep_link_url: '/admin?tab=tickets' }], latest_id: notifications === 1 ? null : 'notification-qa', unread_count: notifications === 1 ? 0 : 1, page: 1, total_pages: 1 } });
    }
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
    if (path.endsWith('/tickets')) { lists++; return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } }); }
    return route.fulfill({ json: {} });
  });
  await page.goto('/admin?tab=tickets');
  await expect.poll(() => notifications).toBe(1);
  await expect.poll(() => lists).toBe(1);
  await page.clock.fastForward(15000);
  await expect.poll(() => notifications).toBe(2);
  await expect.poll(() => lists).toBe(2);
});

for (const actor of [
  { role: 'resident', portal: '/cu-dan', width: 375 },
  { role: 'manager', portal: '/quan-ly', width: 768 },
  { role: 'admin', portal: '/admin', width: 1440 },
]) {
  test(`Chuông thông báo sự cố: ${actor.role}, phân trang, lỗi đọc và mở đúng yêu cầu`, async ({ page }) => {
    await page.setViewportSize({ width: actor.width, height: 900 });
    await page.addInitScript((role) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Ticket QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    }, actor.role);
    let reads = 0;
    let read = false;
    const ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-BELL', title: 'Yêu cầu mở từ chuông', description: 'Cần hỗ trợ kiểm tra đường nước.', category_id: 'cat', priority: 'HIGH', status: 'NEW', version: 1, created_at: '2026-10-16T08:00:00Z', actions: {}, attachments: [], history: [] };
    await page.route('**/api/**', async (route) => {
      const url = new URL(route.request().url());
      const path = url.pathname;
      if (path.endsWith('/ticket-notifications/notice-qa/read') || path.endsWith('/manager/notifications/notice-qa/read')) {
        reads++;
        if (reads === 1) return route.fulfill({ status: 500, json: { message: 'Chưa thể đánh dấu đã đọc.' } });
        read = true;
        return route.fulfill({ json: { success: true, unread_count: actor.role === 'resident' ? 0 : 1 } });
      }
      if (path.endsWith('/ticket-notifications') || path.endsWith('/manager/notifications')) {
        const second = url.searchParams.get('page') === '2';
        return route.fulfill({ json: { items: second ? [{ category: 'TICKET', created_at: '2026-10-19T07:00:00Z', id: 'old-qa', title: 'Thông báo sự cố cũ', body_message: 'Đã xử lý.', is_read: true, deep_link_url: `${actor.portal}?tab=tickets&ticket_id=qa-ticket` }] : [
          { category: 'TICKET', created_at: '2026-10-19T09:00:00Z', id: 'notice-qa', title: 'Sự cố vừa được cập nhật', body_message: 'Mở yêu cầu để xem kết quả.', is_read: read, deep_link_url: `${actor.portal}?tab=tickets&ticket_id=qa-ticket` },
          { category: 'TICKET', created_at: '2026-10-19T08:00:00Z', id: 'unsafe-qa', title: 'Liên kết không hợp lệ', body_message: 'QA kiểm tra liên kết.', is_read: true, deep_link_url: 'https://example.test/?tab=tickets' },
          ...(actor.role === 'resident' ? [] : [{ id: 'amenity-qa', category: 'AMENITY_BOOKING', title: 'Thông báo tiện ích QA', body_message: 'Cần duyệt.', is_read: false, deep_link_url: '/quan-ly?tab=amenities', created_at: '2026-10-19T07:30:00Z' }]),
        ], latest_id: 'notice-qa', unread_count: (read ? 0 : 1) + (actor.role === 'resident' ? 0 : 1), page: second ? 2 : 1, total_pages: 2 } });
      }
      if (path.endsWith('/amenity-booking-notifications') || path.endsWith('/amenity-notifications')) return route.fulfill({ json: { items: [{ id: 'amenity-qa', title: 'Thông báo tiện ích QA', message: 'Đăng ký tiện ích đã được duyệt.', category: 'AMENITY_BOOKING', isRead: false, deepLink: `${actor.portal}?tab=amenities`, timeAgo: 'Vừa xong' }], unread_count: 1, page: 1, total_pages: 1 } });
      if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
      if (path.endsWith('/tickets/qa-ticket')) return route.fulfill({ json: actor.role === 'resident' ? { success: true, data: ticket } : ticket });
      if (path.endsWith('/tickets')) return route.fulfill({ json: { success: true, data: [], current_page: 1, last_page: 1, total: 0, pagination: { current_page: 1, last_page: 1, total: 0, per_page: 15 } } });
      if (path.endsWith('/management/overview')) return route.fulfill({ json: { success: true, notifications: [{ id: 'other-qa', title: 'Bản tin QA', message: 'Thông tin khác.', isRead: true }] } });
      return route.fulfill({ json: { user: { full_name: 'Ticket QA' }, apartments: [], data: [] } });
    });
    // The feed is available even when the Ticket screen is not mounted.
    await page.goto(`${actor.portal}?tab=overview`);
    const bell = page.getByTitle('Thông báo hệ thống', { exact: true });
    const otherUnread = 1;
    await expect(bell).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread + 1} chưa đọc)`);
    await expect(page.getByRole('button', { name: /^Thông báo sự cố \(/ })).toHaveCount(0);
    await bell.click();
    if (otherUnread) await expect(page.getByText('Thông báo tiện ích QA', { exact: true }).last()).toBeVisible();
    const feed = page.getByRole('region', { name: actor.role === 'resident' ? 'Thông báo sự cố' : 'Thông báo vận hành', exact: true });
    await expect(feed.getByText('Sự cố vừa được cập nhật', { exact: true })).toBeVisible();
    await feed.getByRole('button', { name: actor.role === 'resident' ? 'Sự cố tiếp theo' : 'Thông báo sau', exact: true }).click();
    await expect(feed).toContainText('Thông báo sự cố cũ');
    await feed.getByRole('button', { name: actor.role === 'resident' ? 'Sự cố trước' : 'Thông báo trước', exact: true }).click();
    const noticeButton = (title: string) => actor.role === 'resident' ? feed.getByRole('button', { name: new RegExp(title) }) : feed.getByRole('listitem').filter({ hasText: title }).getByRole('button', { name: 'Xem chi tiết', exact: true });
    await noticeButton('Liên kết không hợp lệ').click();
    await expect(feed.getByRole('alert')).toContainText(actor.role === 'resident' ? 'Liên kết thông báo sự cố không hợp lệ' : 'Liên kết thông báo không hợp lệ');
    expect(reads).toBe(0);
    await noticeButton('Sự cố vừa được cập nhật').click();
    await expect(feed.getByRole('alert')).toContainText('Chưa thể đánh dấu đã đọc');
    await expect(page).toHaveURL((url) => url.pathname === actor.portal && url.searchParams.get('tab') === 'overview');
    await expect(bell).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread + 1} chưa đọc)`);
    await noticeButton('Sự cố vừa được cập nhật').click();
    await expect(page).toHaveURL((url) => url.pathname === actor.portal && url.searchParams.get('tab') === 'tickets' && url.searchParams.get('ticket_id') === 'qa-ticket');
    await expect(page.getByText('Yêu cầu mở từ chuông', { exact: true })).toBeVisible();
    await expect(page.getByTitle('Thông báo hệ thống', { exact: true })).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread} chưa đọc)`);
    expect(reads).toBe(2);
  });
}
