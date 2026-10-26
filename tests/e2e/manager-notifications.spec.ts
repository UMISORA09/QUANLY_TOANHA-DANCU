import { test, expect } from '@playwright/test';

const notice = (id: string, category = 'TICKET', is_read = false) => ({ id, category, is_read, title: `Thông báo QA ${id}`, body_message: 'Dữ liệu kiểm thử riêng.', created_at: '2026-10-20T08:00:00Z', deep_link_url: category === 'TICKET' ? '/quan-ly?tab=tickets&ticket_id=qa-ticket' : '/quan-ly?tab=amenities' });

for (const actor of [{ role: 'manager', path: '/quan-ly', width: 375 }, { role: 'admin', path: '/admin', width: 1440 }]) {
  test(`Chuông chung ${actor.role}: chỉ badge khi nhận, cùng danh sách, reload, bàn phím và kích thước`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width: actor.width, height: 812 });
    await page.clock.install();
    await page.addInitScript((role) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Notification QA' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    }, actor.role);
    let arrived = false, polls = 0;
    const notices = [notice('new-amenity', 'AMENITY_BOOKING'), notice('older-ticket')];
    await page.route('**/api/**', (route) => {
      if (new URL(route.request().url()).pathname.endsWith('/manager/notifications')) {
        polls++;
        return route.fulfill({ json: { items: arrived ? notices : [], unread_count: arrived ? 2 : 0, latest_id: arrived ? 'new-amenity' : null, page: 1, total_pages: 1 } });
      }
      return route.fulfill({ json: { items: [], data: [], success: true } });
    });
    await page.goto(`${actor.path}?tab=overview`);
    const bell = page.getByTitle('Thông báo hệ thống', { exact: true });
    await expect.poll(() => polls).toBe(1);
    arrived = true;
    await page.clock.fastForward(15000);
    await expect(bell).toHaveAttribute('aria-label', 'Thông báo hệ thống (2 chưa đọc)');
    await expect(bell).toContainText('2');
    await expect(page.getByRole('region', { name: 'Thông báo vận hành' })).toHaveCount(0);
    await expect(page.getByText('Thông báo QA new-amenity', { exact: true })).toHaveCount(0);
    await expect(page).toHaveURL(`${actor.path}?tab=overview`);
    await bell.click();
    const inbox = page.getByRole('region', { name: 'Thông báo vận hành' });
    await expect(inbox.getByRole('listitem')).toHaveCount(2);
    await expect(inbox.getByRole('listitem').first()).toContainText('new-amenity');
    const bounds = await inbox.boundingBox();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(actor.width);
    expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(812);
    await page.keyboard.press('Escape');
    await expect(inbox).toHaveCount(0);
    await expect(bell).toBeFocused();
    await page.reload();
    await expect(bell).toHaveAttribute('aria-label', 'Thông báo hệ thống (2 chưa đọc)');
    await expect(inbox).toHaveCount(0);
    await bell.click();
    await expect(inbox.getByRole('listitem')).toHaveCount(2);
    await page.screenshot({ path: testInfo.outputPath('notification-center.png') });
  });
}

test('Chuông chung: lỗi mạng, retry, đọc thành công rồi refetch lỗi vẫn giữ đúng badge', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Notification QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let unavailable = true, read = false;
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/read')) { read = true; return route.fulfill({ json: { unread_count: 0 } }); }
    if (path.endsWith('/manager/notifications')) {
      if (unavailable) return route.fulfill({ status: 503, json: { message: 'QA mất kết nối.' } });
      return route.fulfill({ json: { items: [notice('read-me', 'TICKET', read)], unread_count: read ? 0 : 1, latest_id: 'read-me', page: 1, total_pages: 1 } });
    }
    return route.fulfill({ json: { items: [], data: [] } });
  });
  await page.goto('/admin');
  await page.getByTitle('Thông báo hệ thống', { exact: true }).click();
  const inbox = page.getByRole('region', { name: 'Thông báo vận hành' });
  await expect(inbox.getByRole('alert')).toContainText('QA mất kết nối');
  unavailable = false;
  await inbox.getByRole('button', { name: 'Thử lại' }).click();
  await expect(inbox.getByRole('listitem')).toHaveCount(1);
  await inbox.getByRole('button', { name: 'Đánh dấu đã đọc' }).click();
  await expect(page.getByTitle('Thông báo hệ thống', { exact: true })).toHaveAttribute('aria-label', 'Thông báo hệ thống (0 chưa đọc)');
  unavailable = true;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(inbox.getByRole('alert')).toContainText('QA mất kết nối');
  await expect(inbox.getByRole('listitem')).toContainText('Đã đọc');
  await expect(page.getByTitle('Thông báo hệ thống', { exact: true })).toHaveAttribute('aria-label', 'Thông báo hệ thống (0 chưa đọc)');
});

test('Chuông chung: phản hồi cũ không ghi đè bộ lọc mới; 401 xóa dữ liệu phiên cũ', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Notification QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let release!: () => void;
  const old = new Promise<void>((resolve) => { release = resolve; });
  let expired = false, categoryRequests = 0;
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/manager/notifications')) {
      const category = url.searchParams.get('category');
      if (expired) return route.fulfill({ status: 401, json: { message: 'Phiên đã hết hạn.' } });
      if (category === 'TICKET') { categoryRequests++; await old; }
      return route.fulfill({ json: { items: [notice(category === 'AMENITY_BOOKING' ? 'filtered-amenity' : 'old-ticket', category || 'TICKET')], unread_count: 2, latest_id: 'newest', page: 1, total_pages: 1 } });
    }
    return route.fulfill({ json: { items: [], data: [] } });
  });
  await page.goto('/quan-ly');
  await page.getByTitle('Thông báo hệ thống', { exact: true }).click();
  const inbox = page.getByRole('region', { name: 'Thông báo vận hành' });
  await expect(inbox.getByRole('listitem')).toHaveCount(1);
  await inbox.getByLabel('Nhóm thông báo', { exact: true }).click();
  await page.getByRole('option', { name: 'Sự cố', exact: true }).click();
  await expect.poll(() => categoryRequests).toBe(1);
  await inbox.getByLabel('Nhóm thông báo', { exact: true }).click();
  await page.getByRole('option', { name: 'Tiện ích', exact: true }).click();
  await expect(inbox.getByRole('listitem')).toContainText('filtered-amenity');
  release();
  await expect(inbox.getByRole('listitem')).not.toContainText('old-ticket');
  expired = true;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(inbox.getByRole('alert')).toContainText('Phiên đã hết hạn');
  await expect(inbox.getByRole('listitem')).toHaveCount(0);
  await expect(page.getByTitle('Thông báo hệ thống', { exact: true })).toHaveAttribute('aria-label', 'Thông báo hệ thống (0 chưa đọc)');
});

test('Chuông chung: đồng bộ đã đọc giữa hai tab và xóa thông báo khi đổi tài khoản', async ({ context, page }) => {
  await context.addInitScript(() => {
    if (!localStorage.getItem('smart_cassavas_token')) {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Notification QA' }));
      localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
    }
  });
  let read = false;
  await context.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/read')) { read = true; return route.fulfill({ json: { unread_count: 0 } }); }
    if (path.endsWith('/manager/notifications')) {
      const other = route.request().headers().authorization === 'Bearer qa-other-account';
      return route.fulfill({ json: { items: [notice(other ? 'other-account' : 'shared-account', 'TICKET', read)], unread_count: read ? 0 : 1, latest_id: other ? 'other-account' : 'shared-account', page: 1, total_pages: 1 } });
    }
    return route.fulfill({ json: { items: [], data: [] } });
  });
  await page.goto('/admin');
  const second = await context.newPage();
  await second.goto('/admin');
  const bell = (tab: typeof page) => tab.getByTitle('Thông báo hệ thống', { exact: true });
  await expect(bell(second)).toHaveAttribute('aria-label', 'Thông báo hệ thống (1 chưa đọc)');
  await bell(page).click();
  await page.getByRole('region', { name: 'Thông báo vận hành' }).getByRole('button', { name: 'Đánh dấu đã đọc' }).click();
  await expect(bell(second)).toHaveAttribute('aria-label', 'Thông báo hệ thống (0 chưa đọc)');
  await page.evaluate(() => {
    localStorage.setItem('smart_cassavas_token', 'qa-other-account');
    window.dispatchEvent(new Event('AUTH_SESSION_CHANGED'));
  });
  await expect(page.getByRole('region', { name: 'Thông báo vận hành' }).getByRole('listitem')).toContainText('other-account');
  await expect(page.getByText('Thông báo QA shared-account', { exact: true })).toHaveCount(0);
  await second.close();
});

test('Chuông chung: ngừng poll khi tab ẩn, focus không tạo request chồng', async ({ page }) => {
  await page.clock.install();
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'Notification QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  });
  let polls = 0, running = 0, maxRunning = 0;
  await page.route('**/api/**', async (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/manager/notifications')) {
      polls++; running++; maxRunning = Math.max(maxRunning, running);
      await route.fulfill({ json: { items: [], unread_count: 0, latest_id: null, page: 1, total_pages: 1 } });
      running--;
      return;
    }
    return route.fulfill({ json: { items: [], data: [] } });
  });
  await page.goto('/admin');
  await expect.poll(() => polls).toBe(1);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
  await page.clock.fastForward(60000);
  expect(polls).toBe(1);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: false }); document.dispatchEvent(new Event('visibilitychange')); });
  await expect.poll(() => polls).toBe(2);
  expect(maxRunning).toBe(1);
});
