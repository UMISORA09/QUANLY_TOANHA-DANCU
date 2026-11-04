import { test, expect, Page } from '@playwright/test';
import type { ResidentAnnouncement } from '../../resources/js/Services/api';

const uuid = (n: number) => `b1100000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const article = (n = 1): ResidentAnnouncement => ({ id: uuid(n), title: `Thông báo BQL ${n}`, summary_excerpt: 'Thông tin dành cho cư dân', content_html: '<h2>Nội dung an toàn</h2><p>Lịch bảo trì thang máy.</p>', category: 'MAINTENANCE', target_scope: 'BLOCK', block_name: 'Tòa QA', floor_name: null, is_pinned: false, is_urgent: false, requires_acknowledgement: true, version: 1, publish_at: '2026-10-23T09:00:00+07:00', expires_at: null, read_at: null, acknowledged_at: null, attachments: [] });
type State = { rows: ResidentAnnouncement[]; reads: number; acknowledgements: number; failRead: boolean; failDetail: boolean; conflict: boolean; unauthorized: boolean; listRequests: number; failList?: boolean; listDelay?: () => Promise<void>; delay?: (id: string) => Promise<void> };
const state = (): State => ({ rows: [article()], reads: 0, acknowledgements: 0, failRead: false, failDetail: false, conflict: false, unauthorized: false, listRequests: 0 });
async function setup(page: Page, store: State) {
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', name: 'Cư dân QA' })); localStorage.setItem('smart_cassavas_token', 'qa-f11'); });
  await page.route('**/api/**', async (route) => {
    const request = route.request(); const url = new URL(request.url());
    const prefix = '/api/v1/resident/announcements';
    if (!url.pathname.startsWith(prefix)) return route.fulfill({ json: { data: [], items: [], unread_count: 0, page: 1, total_pages: 1, user: { full_name: 'Cư dân QA' } } });
    if (store.unauthorized) return route.fulfill({ status: 401, json: { message: 'Phiên hết hạn' } });
    const parts = url.pathname.slice(prefix.length).split('/').filter(Boolean);
    if (!parts.length) {
      store.listRequests++; if (store.failList) return route.fulfill({ status: 503, json: { message: 'Mất kết nối bảng tin QA' } }); if (store.listDelay) await store.listDelay();
      let rows = store.rows.filter((row) => row.title.includes(url.searchParams.get('search') || ''));
      const filter = url.searchParams.get('filter'); if (filter === 'unread') rows = rows.filter((row) => !row.read_at); if (filter === 'acknowledgement') rows = rows.filter((row) => row.requires_acknowledgement && !row.acknowledged_at);
      if (url.searchParams.get('category')) rows = rows.filter((row) => row.category === url.searchParams.get('category'));
      rows = [...rows].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned) || b.id.localeCompare(a.id));
      const pageNumber = Number(url.searchParams.get('page') || 1);
      return route.fulfill({ json: { data: rows.slice((pageNumber - 1) * 15, pageNumber * 15), current_page: pageNumber, last_page: Math.max(1, Math.ceil(rows.length / 15)), total: rows.length, unread_count: store.rows.filter((row) => !row.read_at).length, categories: { GENERAL: 'Thông báo chung', MAINTENANCE: 'Bảo trì' }, timezone: 'Asia/Ho_Chi_Minh' } });
    }
    const row = store.rows.find((item) => item.id === parts[0]);
    if (!row || store.failDetail) return route.fulfill({ status: 404, json: { message: 'Bài không còn hiển thị' } });
    if (request.method() === 'GET') { if (store.delay) await store.delay(row.id); return route.fulfill({ json: row }).catch(() => {}); }
    if (parts[1] === 'read') {
      store.reads++; if (store.failRead) return route.fulfill({ status: 503, json: { message: 'Không ghi được trạng thái đọc' } });
      row.read_at ||= new Date().toISOString(); return route.fulfill({ json: row });
    }
    if (parts[1] === 'acknowledge') {
      store.acknowledgements++;
      if (store.conflict) { row.version++; store.conflict = false; return route.fulfill({ status: 409, json: { message: 'Bài viết đã thay đổi. Vui lòng mở lại nội dung mới nhất.' } }); }
      row.acknowledged_at ||= new Date().toISOString(); return route.fulfill({ json: row });
    }
    return route.fulfill({ json: {} });
  });
  await page.goto('/cu-dan?tab=news'); await expect(page.getByRole('heading', { name: 'Bảng tin / Thông báo', exact: true })).toBeVisible();
}

test('MOCKED F11: mở bài mới ghi đọc, xác nhận riêng và chuông mở đúng bài', async ({ page }) => {
  const store = state(); await setup(page, store);
  await expect(page.locator('.announcement-card')).toHaveCount(1); expect(store.reads).toBe(0);
  await page.getByRole('button', { name: /Thông báo hệ thống/ }).click(); await page.getByRole('region', { name: 'Thông báo từ ban quản lý' }).getByRole('button', { name: /Thông báo BQL 1/ }).click();
  const dialog = page.getByRole('dialog', { name: 'Thông báo BQL 1' }); await expect(dialog).toBeVisible(); await expect(dialog).toContainText('Lịch bảo trì thang máy.');
  await expect.poll(() => store.reads).toBe(1); expect(store.acknowledgements).toBe(0);
  await expect(page).toHaveURL(new RegExp('announcement=' + uuid(1)));
  await dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }).click(); await expect(dialog.getByRole('status')).toContainText('Bạn đã xác nhận'); expect(store.acknowledgements).toBe(1);
  await page.keyboard.press('Escape'); await expect(dialog).not.toBeVisible(); await page.goBack(); await expect(dialog).toBeVisible();
});

test('MOCKED F11: tải chi tiết lỗi không ghi đọc và retry không tự xác nhận', async ({ page }) => {
  const store = state(); store.failDetail = true; await setup(page, store); await page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 1', exact: true }).click();
  const dialog = page.getByRole('dialog'); await expect(dialog.getByRole('alert')).toContainText('không còn hiển thị'); expect(store.reads).toBe(0);
  store.failDetail = false; store.failRead = true; await dialog.getByRole('button', { name: 'Thử lại', exact: true }).click(); await expect(dialog.getByRole('alert')).toContainText('Không ghi được');
  await expect(dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true })).toBeDisabled();
  store.failRead = false; await dialog.getByRole('button', { name: 'Tải lại nội dung', exact: true }).click(); await expect(dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true })).toBeEnabled(); expect(store.acknowledgements).toBe(0);
});

test('MOCKED F11: version cũ cần tải lại trước xác nhận', async ({ page }) => {
  const store = state(); store.conflict = true; await setup(page, store); await page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 1', exact: true }).click();
  const dialog = page.getByRole('dialog'); await dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }).click(); await expect(dialog.getByRole('alert')).toContainText('đã thay đổi');
  await expect(dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true })).toBeDisabled(); await dialog.getByRole('button', { name: 'Tải lại nội dung', exact: true }).click();
  await dialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }).click(); await expect(dialog.getByRole('status')).toContainText('Bạn đã xác nhận');
});

test('MOCKED F11: 15 bài mỗi trang, tìm kiếm, bộ lọc và URL tải lại', async ({ page }) => {
  const store = state(); store.rows = Array.from({ length: 32 }, (_, i) => article(i + 1)); await setup(page, store);
  await expect(page.locator('.announcement-card')).toHaveCount(15); await page.getByRole('button', { name: 'Trang sau', exact: true }).click(); await expect(page.getByRole('navigation', { name: 'Phân trang bảng tin cư dân' })).toContainText('2/3');
  await page.getByRole('button', { name: 'Trang sau', exact: true }).click(); await expect(page.locator('.announcement-card')).toHaveCount(2);
  await page.getByLabel('Tìm tiêu đề', { exact: true }).fill('Thông báo BQL 32'); await expect(page.locator('.announcement-card')).toHaveCount(1); await page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 32', exact: true }).click();
  await page.reload(); await expect(page.getByRole('dialog', { name: 'Thông báo BQL 32' })).toBeVisible(); await page.keyboard.press('Escape');
  await page.getByLabel('Trạng thái đọc', { exact: true }).click(); await page.getByRole('option', { name: 'Chưa đọc', exact: true }).click(); await expect(page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 32', exact: true })).toHaveCount(0);
});

test('MOCKED F11: mobile dropdown, tương phản, Escape và trả focus', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); const store = state(); await setup(page, store);
  await page.getByLabel('Danh mục', { exact: true }).click(); const menu = page.getByRole('listbox'); await expect(menu).toBeVisible();
  expect(await menu.evaluate((element) => { const r = element.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight; })).toBe(true); await page.keyboard.press('Escape');
  const open = page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 1', exact: true }); await open.click();
  const confirm = page.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }); await expect(confirm).toBeEnabled(); expect(await confirm.evaluate((element) => getComputedStyle(element).color !== getComputedStyle(element).backgroundColor)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await page.keyboard.press('Escape'); await expect(open).toBeFocused();
});

test('MOCKED F11: phản hồi bài cũ không thay thế bài mới', async ({ page }) => {
  const store = state(); store.rows.push(article(2)); let release: () => void = () => {}; const delayed = new Promise<void>((resolve) => { release = resolve; }); store.delay = (id) => id === uuid(1) ? delayed : Promise.resolve();
  await setup(page, store); await page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 1', exact: true }).click();
  await page.evaluate((id) => { history.pushState({}, '', '/cu-dan?tab=news&announcement=' + id); dispatchEvent(new PopStateEvent('popstate')); }, uuid(2));
  await expect(page.getByRole('dialog', { name: 'Thông báo BQL 2' })).toBeVisible(); release(); await expect(page.getByRole('dialog', { name: 'Thông báo BQL 1' })).toHaveCount(0); expect(store.rows[0].read_at).toBeNull();
});

test('MOCKED F11: polling 15 giây nhận bài mới và hết phiên xóa dữ liệu', async ({ page }) => {
  await page.clock.install(); const store = state(); await setup(page, store); await expect(page.locator('.announcement-card')).toHaveCount(1); const requests = store.listRequests;
  store.rows.push(article(2)); await page.clock.runFor(15100); await expect(page.locator('.announcement-card')).toHaveCount(2); expect(store.listRequests).toBeGreaterThan(requests);
  store.unauthorized = true; await page.clock.runFor(15100); await expect(page.locator('.announcement-card')).toHaveCount(0); await expect(page.getByRole('alert').first()).toContainText('Phiên đăng nhập');
});


test('MOCKED F11: ẩn tab dừng polling, quay lại tải ngay và chưa hiển thị thì chưa ghi đọc', async ({ page }) => {
  await page.clock.install(); const store = state(); await setup(page, store); await expect(page.locator('.announcement-card')).toHaveCount(1);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
  const requests = store.listRequests; await page.clock.runFor(45000); expect(store.listRequests).toBe(requests);
  await page.getByRole('button', { name: 'Đọc thông báo: Thông báo BQL 1', exact: true }).click(); await expect(page.getByRole('dialog', { name: 'Thông báo BQL 1' })).toBeVisible(); expect(store.reads).toBe(0);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: false }); document.dispatchEvent(new Event('visibilitychange')); });
  await expect.poll(() => store.reads).toBe(1); await expect.poll(() => store.listRequests).toBeGreaterThan(requests);
});

test('MOCKED F11: request chậm không bị polling chồng nhau', async ({ page }) => {
  await page.clock.install(); const store = state(); await setup(page, store); await expect(page.locator('.announcement-card')).toHaveCount(1);
  let release: () => void = () => {}; const held = new Promise<void>((resolve) => { release = resolve; }); store.listDelay = () => held;
  const initial = store.listRequests; await page.getByRole('button', { name: 'Tải lại', exact: true }).click(); await expect.poll(() => store.listRequests).toBe(initial + 2);
  await page.clock.runFor(45000); expect(store.listRequests).toBe(initial + 2); store.listDelay = undefined; release();
});


test('MOCKED F11: lỗi mạng giữ dữ liệu đã tải, thử lại phục hồi', async ({ page }) => {
  const store = state(); await setup(page, store); await expect(page.locator('.announcement-card')).toHaveCount(1);
  store.failList = true; await page.getByRole('button', { name: 'Tải lại', exact: true }).click(); await expect(page.getByRole('alert')).toContainText('Mất kết nối'); await expect(page.locator('.announcement-card')).toHaveCount(1);
  store.failList = false; await page.getByRole('button', { name: 'Thử lại', exact: true }).click(); await expect(page.getByRole('alert')).toHaveCount(0); await expect(page.locator('.announcement-card')).toHaveCount(1);
});

test('MOCKED F11: đổi tìm kiếm nhanh bỏ phản hồi danh sách cũ', async ({ page }) => {
  const store = state(); store.rows.push(article(2)); await setup(page, store); await expect(page.locator('.announcement-card')).toHaveCount(2);
  let release: () => void = () => {}; const held = new Promise<void>((resolve) => { release = resolve; }); store.listDelay = () => held;
  const requests = store.listRequests; await page.getByLabel('Tìm tiêu đề', { exact: true }).fill('Thông báo BQL 1'); await expect.poll(() => store.listRequests).toBeGreaterThan(requests);
  store.listDelay = undefined; await page.getByLabel('Tìm tiêu đề', { exact: true }).fill('Thông báo BQL 2'); await expect(page.locator('.announcement-card')).toHaveCount(1); await expect(page.locator('.announcement-card')).toContainText('Thông báo BQL 2'); release(); await expect(page.locator('.announcement-card')).toContainText('Thông báo BQL 2');
});
