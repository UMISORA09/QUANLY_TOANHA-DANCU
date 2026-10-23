import { test, expect, Page } from '@playwright/test';

const base = () => ({ id: 'a1010000-0000-4000-8000-000000000001', title: 'Thông báo QA', summary_excerpt: 'Bảo trì định kỳ', content_html: '<p>Thông tin bảo trì QA</p>', category: 'MAINTENANCE', target_scope: 'ALL_BLOCKS', target_block_id: null, target_floor_id: null, is_pinned: false, is_urgent: false, status: 'DRAFT', display_status: 'DRAFT', version: 1, publish_at: '2026-10-23T08:00:00+07:00', expires_at: null, created_at: '2026-10-23T08:00:00+07:00', author_name: 'Quản lý QA', actions: { edit: true, publish: true, archive: true }, attachments: [] as { id: string; file_name: string; mime_type: string; file_size_bytes: number }[] });
type State = { rows: ReturnType<typeof base>[]; ids: string[]; fail: boolean; conflict: boolean; reads: number };
const state = (): State => ({ rows: [], ids: [], fail: false, conflict: false, reads: 0 });
async function setup(page: Page, store: State, role = 'admin') {
  await page.addInitScript((role) => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'QA Admin' })); localStorage.setItem('smart_cassavas_token', 'qa-mock'); }, role);
  await page.route('**/api/**', async (route) => {
    const req = route.request(); const url = new URL(req.url()); const path = url.pathname;
    if (!path.includes('/admin/announcements')) return route.fulfill({ json: { data: [], items: [], unread_count: 0, page: 1, total_pages: 1, user: { full_name: 'QA' } } });
    if (path.endsWith('/options')) return route.fulfill({ json: { categories: { GENERAL: 'Thông báo chung', MAINTENANCE: 'Bảo trì' }, blocks: [{ id: 'block', block_name: 'Tòa QA' }], floors: Array.from({ length: 30 }, (_, i) => ({ id: 'floor-' + i, block_id: 'block', floor_name: 'Tầng ' + i })), can_target_all: true, can_create: true, can_publish: true, timezone: 'Asia/Ho_Chi_Minh' } });
    if (path.endsWith('/preview')) return route.fulfill({ json: { content_html: '<h2>Xem trước an toàn</h2><p>Nội dung đã được máy chủ lọc.</p>' } });
    if (path.endsWith('/uploads') && req.method() === 'POST') return route.fulfill({ json: { token: 'qa-upload', transport: 'local', url: '/api/v1/admin/announcements/uploads/qa-upload', headers: {}, expires_at: Math.floor(Date.now() / 1000) + 600 } });
    if (path.includes('/uploads/')) return route.fulfill({ json: { success: true } });
    const parts = path.split('/'); const id = parts[5];
    if (req.method() === 'GET' && path.endsWith('/announcements')) {
      store.reads++; let rows = store.rows.filter((row) => row.title.includes(url.searchParams.get('search') || ''));
      const status = url.searchParams.get('status'); if (status) rows = rows.filter((row) => row.display_status === status);
      const p = Number(url.searchParams.get('page') || 1); return route.fulfill({ json: { data: rows.slice((p - 1) * 15, p * 15), current_page: p, last_page: Math.max(1, Math.ceil(rows.length / 15)), total: rows.length } });
    }
    const row = store.rows.find((item) => item.id === id);
    if (req.method() === 'GET') return route.fulfill({ status: row ? 200 : 404, json: row || { message: 'Không tìm thấy' } });
    if (path.endsWith('/publish') || path.endsWith('/unpublish') || req.method() === 'DELETE') {
      const data = req.postDataJSON(); if (!row || data.version !== row.version) return route.fulfill({ status: 409, json: { message: 'Bài đã thay đổi.' } });
      row.version++; if (req.method() === 'DELETE') { store.rows = store.rows.filter((item) => item.id !== id); return route.fulfill({ json: { success: true } }); }
      row.status = path.endsWith('/unpublish') ? 'UNPUBLISHED' : 'PUBLISHED'; row.display_status = row.status;
      if (data.publish_at && !path.endsWith('/unpublish')) { row.display_status = 'SCHEDULED'; row.publish_at = data.publish_at + '+07:00'; }
      return route.fulfill({ json: row });
    }
    const body = req.postData() || ''; const field = (key: string) => body.match(new RegExp(String.raw`name="${key}"\r\n\r\n([\s\S]*?)\r\n--`))?.[1] || '';
    const updating = field('_method') === 'PATCH'; const identity = updating ? id : field('id'); store.ids.push(identity);
    if (store.fail) { store.fail = false; return route.abort('failed'); }
    if (store.conflict) return route.fulfill({ status: 409, json: { message: 'Bài viết đã thay đổi. Bản nhập vẫn được giữ.' } });
    const result = row || { ...base(), id: identity };
    Object.assign(result, { title: field('title'), content_html: field('content_html'), summary_excerpt: field('summary_excerpt'), version: updating ? result.version + 1 : 1 });
    if (body.includes('uploaded_attachment_tokens[]')) result.attachments = [{ id: 'attachment-1', file_name: 'rules.pdf', mime_type: 'application/pdf', file_size_bytes: 45 }];
    if (!row) store.rows.unshift(result);
    return route.fulfill({ status: updating ? 200 : 201, json: result });
  });
  await page.goto(`/${role === 'admin' ? 'admin' : 'quan-ly'}?tab=news`);
  await expect(page.getByRole('button', { name: 'Soạn thông báo', exact: true })).toBeVisible();
}

test('MOCKED: soạn, định dạng, xem trước, đính kèm, hẹn đăng, gỡ và lưu trữ', async ({ page }) => {
  const store = state(); await setup(page, store);
  await page.getByRole('button', { name: 'Soạn thông báo', exact: true }).click();
  await page.getByLabel('Tiêu đề', { exact: true }).fill('Lịch bảo trì QA');
  const editor = page.getByRole('textbox', { name: 'Nội dung bảng tin', exact: true }); await editor.fill('Lịch bảo trì mới'); await editor.press('ControlOrMeta+a');
  await page.getByRole('button', { name: 'Đậm', exact: true }).click(); await expect(editor.locator('strong')).toContainText('Lịch bảo trì mới');
  await page.getByLabel('Tệp đính kèm', { exact: true }).setInputFiles({ name: 'rules.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 QA') });
  await page.getByRole('button', { name: 'Xem trước', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Xem trước an toàn' })).toBeVisible();
  await page.getByRole('button', { name: 'Quay lại bản nhập', exact: true }).click(); await expect(page.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Lịch bảo trì QA');
  await page.getByLabel('Thời điểm đăng', { exact: false }).fill('2027-01-01T09:00');
  await page.getByRole('button', { name: 'Lưu và hẹn đăng', exact: true }).click(); await expect(page.getByRole('button', { name: 'Gỡ đăng', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Gỡ đăng', exact: true }).click(); await page.getByLabel('Lý do', { exact: true }).fill('Hoãn bảo trì theo lịch mới'); await page.getByRole('button', { name: 'Xác nhận', exact: true }).click();
  await expect.poll(() => store.rows[0].status).toBe('UNPUBLISHED');
  await page.getByRole('button', { name: 'Lưu trữ', exact: true }).click(); await page.getByLabel('Lý do', { exact: true }).fill('Lưu trữ thông báo cũ'); await page.getByRole('button', { name: 'Xác nhận', exact: true }).click();
  await expect(page.getByText('Chưa có bài viết phù hợp.', { exact: true })).toBeVisible();
});

test('MOCKED: lỗi mạng giữ bản nhập và UUID; 409 không ghi đè version', async ({ page }) => {
  const store = state(); store.fail = true; await setup(page, store);
  await page.getByRole('button', { name: 'Soạn thông báo', exact: true }).click(); await page.getByLabel('Tiêu đề', { exact: true }).fill('Bản nháp cần giữ'); await page.getByRole('textbox', { name: 'Nội dung bảng tin', exact: true }).fill('Nội dung chưa gửi');
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click(); await expect(page.getByRole('alert')).toBeVisible(); await expect(page.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Bản nháp cần giữ');
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click(); await expect.poll(() => store.rows.length).toBe(1); expect(store.ids[0]).toBe(store.ids[1]);
  await page.getByRole('button', { name: 'Sửa bài viết', exact: true }).click(); await page.getByLabel('Tiêu đề', { exact: true }).fill('Bản nhập khác máy chủ'); store.conflict = true;
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click(); await expect(page.getByRole('alert')).toContainText('Bài viết đã thay đổi'); await expect(page.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Bản nhập khác máy chủ');
  await page.getByRole('button', { name: 'Xem bản mới nhất', exact: true }).click(); await expect(page.getByText(/Phiên bản máy chủ/)).toContainText('1'); await page.getByRole('button', { name: 'Giữ bản nhập và dùng phiên bản 1', exact: true }).click(); await expect(page.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Bản nhập khác máy chủ'); store.conflict = false; await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click(); await expect.poll(() => store.rows[0].title).toBe('Bản nhập khác máy chủ');
});

test('MOCKED: mobile combobox cuộn, tương phản, hủy giữ bản nháp và bàn phím', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); const store = state(); await setup(page, store, 'manager');
  const create = page.getByRole('button', { name: 'Soạn thông báo', exact: true }); expect(await create.evaluate((node) => getComputedStyle(node).color !== getComputedStyle(node).backgroundColor)).toBe(true); await create.click();
  await page.getByLabel('Tiêu đề', { exact: true }).fill('Bản nháp mobile'); await page.getByLabel('Đối tượng nhận', { exact: true }).click(); await page.getByRole('option', { name: 'Một tầng', exact: true }).click();
  await page.getByLabel('Tầng', { exact: true }).click(); const list = page.getByRole('listbox'); await expect(list).toBeVisible(); expect(await list.locator('.overflow-y-auto').evaluate((node) => node.scrollHeight > node.clientHeight)).toBe(true); await expect.poll(() => list.evaluate((node) => { const r = node.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight && document.elementFromPoint(r.left + r.width / 2, r.bottom - 8)?.closest('[role=\"listbox\"]') === node; })).toBe(true); await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Đóng', exact: true }).click(); await expect(page.getByRole('dialog', { name: 'Bỏ thay đổi chưa lưu?' })).toBeVisible(); await page.getByRole('button', { name: 'Quay lại', exact: true }).click(); await expect(page.getByLabel('Tiêu đề', { exact: true })).toHaveValue('Bản nháp mobile');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('MOCKED: phân trang 15, tìm kiếm và URL Back/Forward', async ({ page }) => {
  const store = state(); store.rows = Array.from({ length: 32 }, (_, i) => ({ ...base(), id: 'row-' + i, title: 'Thông báo QA ' + i })); await setup(page, store);
  await expect(page.locator('.announcement-card')).toHaveCount(15); await page.getByRole('button', { name: 'Trang sau', exact: true }).click(); await expect(page.getByRole('navigation', { name: 'Phân trang bảng tin' })).toContainText('2/3'); await expect(page.locator('.announcement-card')).toHaveCount(15); await page.getByRole('button', { name: 'Trang sau', exact: true }).click(); await expect(page.locator('.announcement-card')).toHaveCount(2);
  await page.getByLabel('Tìm tiêu đề', { exact: true }).fill('Thông báo QA 31'); await expect(page.locator('.announcement-card')).toHaveCount(1); await page.reload(); await expect(page.getByRole('heading', { name: 'Bảng tin / Thông báo', exact: true })).toBeVisible();
  await page.goto('/admin?tab=tickets'); await page.goBack(); await expect(page.getByRole('button', { name: 'Soạn thông báo', exact: true })).toBeVisible(); await page.goForward(); await expect(page).toHaveURL(/tab=tickets/);
});

test('MOCKED: người chỉ có quyền đăng không phải sửa nội dung để phát hành', async ({ page }) => {
  const store = state(); store.rows = [{ ...base(), actions: { edit: false, publish: true, archive: false } }]; await setup(page, store);
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click(); await expect(page.getByRole('button', { name: 'Sửa bài viết', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Đăng theo lịch đã lưu', exact: true }).click(); await expect.poll(() => store.rows[0].status).toBe('PUBLISHED');
});
