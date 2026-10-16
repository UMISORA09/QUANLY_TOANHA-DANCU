import { test, expect } from '@playwright/test';

for (const width of [375, 768, 1440]) {
  test(`Ticket quản lý: form, combobox bàn phím và phiên bản ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Admin QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    });
    let ticket = { id: 'qa-ticket', ticket_number: 'TCK-QA-001', title: 'Kiểm tra đường nước', description: 'Sự cố cần hỗ trợ tại bếp.', location: 'Phòng bếp', category_id: 'cat', priority: 'HIGH', status: 'NEW', version: 1, created_at: '2026-10-14T08:00:00Z', apartment: { apartment_number: 'QA-01' }, history: [], attachments: [], actions: { edit: true, archive: false, assign: true } };
    const payloads: Record<string, unknown>[] = [];
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [{ id: 'cat', category_name: 'Điện nước' }, ...Array.from({ length: 30 }, (_, i) => ({ id: 'cat-' + i, category_name: 'Danh mục QA ' + i }))], blocks: [{ id: 'block', block_name: 'Tòa QA' }], apartments: [{ id: 'apt', block_id: 'block', apartment_number: 'QA-01' }], residents: [{ id: 'resident', apartment_id: 'apt', full_name: 'Cư dân QA' }], can_create: true } });
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
      return document.elementFromPoint(rect.left + rect.width / 2, rect.bottom - 8)?.closest('[role="listbox"]') === element;
    })).toBe(true);
    await category.press('End');
    const lastCategory = page.getByRole('option', { name: 'Danh mục QA 29', exact: true });
    await expect(lastCategory).toBeFocused();
    await lastCategory.click();
    await expect(category).toContainText('Danh mục QA 29');
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

test('Ticket: tải lại ảnh lỗi và đóng ảnh phóng to bằng bàn phím', async ({ page }) => {
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
  await page.getByRole('button', { name: 'Xem ảnh anh-qa.png', exact: true }).click();
  const preview = page.getByRole('dialog', { name: 'Ảnh sự cố', exact: true });
  await expect(preview.getByRole('img')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(preview).toHaveCount(0);
  await expect(page.getByRole('dialog', { name: 'TCK-QA-001', exact: true })).toContainText('Kết quả xử lý');
  expect(attempts).toBe(2);
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
    if (path.endsWith('/ticket-notifications')) {
      notifications++;
      return route.fulfill({ json: { items: notifications === 1 ? [] : [{ id: 'notification-qa', title: 'Yêu cầu mới', body_message: 'Có yêu cầu cần tiếp nhận.', is_read: false, deep_link_url: '/admin?tab=tickets' }], unread_count: notifications === 1 ? 0 : 1, page: 1, total_pages: 1 } });
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
      if (path.endsWith('/ticket-notifications/notice-qa/read')) {
        reads++;
        if (reads === 1) return route.fulfill({ status: 500, json: { message: 'Chưa thể đánh dấu đã đọc.' } });
        read = true;
        return route.fulfill({ json: { success: true } });
      }
      if (path.endsWith('/ticket-notifications')) {
        const second = url.searchParams.get('page') === '2';
        return route.fulfill({ json: { items: second ? [{ id: 'old-qa', title: 'Thông báo sự cố cũ', body_message: 'Đã xử lý.', is_read: true, deep_link_url: `${actor.portal}?tab=tickets&ticket_id=qa-ticket` }] : [
          { id: 'notice-qa', title: 'Sự cố vừa được cập nhật', body_message: 'Mở yêu cầu để xem kết quả.', is_read: read, deep_link_url: `${actor.portal}?tab=tickets&ticket_id=qa-ticket` },
          { id: 'unsafe-qa', title: 'Liên kết không hợp lệ', body_message: 'QA kiểm tra liên kết.', is_read: true, deep_link_url: 'https://example.test/?tab=tickets' },
        ], unread_count: read ? 0 : 1, page: second ? 2 : 1, total_pages: 2 } });
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
    const otherUnread = actor.role === 'admin' ? 0 : 1;
    await expect(bell).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread + 1} chưa đọc)`);
    await expect(page.getByRole('button', { name: /^Thông báo sự cố \(/ })).toHaveCount(0);
    await bell.click();
    if (otherUnread) await expect(page.getByText('Thông báo tiện ích QA', { exact: true }).last()).toBeVisible();
    const feed = page.getByRole('region', { name: 'Thông báo sự cố', exact: true });
    await expect(feed.getByText('Sự cố vừa được cập nhật', { exact: true })).toBeVisible();
    await feed.getByRole('button', { name: 'Sự cố tiếp theo', exact: true }).click();
    await expect(feed).toContainText('Thông báo sự cố cũ');
    await feed.getByRole('button', { name: 'Sự cố trước', exact: true }).click();
    await feed.getByRole('button', { name: /Liên kết không hợp lệ/ }).click();
    await expect(feed.getByRole('alert')).toContainText('Liên kết thông báo sự cố không hợp lệ');
    expect(reads).toBe(0);
    await feed.getByRole('button', { name: /Sự cố vừa được cập nhật/ }).click();
    await expect(feed.getByRole('alert')).toContainText('Chưa thể đánh dấu đã đọc');
    await expect(page).toHaveURL((url) => url.pathname === actor.portal && url.searchParams.get('tab') === 'overview');
    await expect(bell).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread + 1} chưa đọc)`);
    await feed.getByRole('button', { name: /Sự cố vừa được cập nhật/ }).click();
    await expect(page).toHaveURL((url) => url.pathname === actor.portal && url.searchParams.get('tab') === 'tickets' && url.searchParams.get('ticket_id') === 'qa-ticket');
    await expect(page.getByText('Yêu cầu mở từ chuông', { exact: true })).toBeVisible();
    await expect(page.getByTitle('Thông báo hệ thống', { exact: true })).toHaveAttribute('aria-label', `Thông báo hệ thống (${otherUnread} chưa đọc)`);
    expect(reads).toBe(2);
  });
}
