import { test, expect, Page } from '@playwright/test';

type Comment = { id: string; ticket_id: string; comment_text: string; is_internal_staff_note: boolean; created_at: string; sender: { id: string; full_name: string; role: string } };
type State = { comments: Comment[]; payloads: { id: string }[]; reads: number; historyReads: number; historyCount: number; status: string; readStatus: number; loseResponse: boolean; viewInternal: boolean; };
const state = (): State => ({ comments: [], payloads: [], reads: 0, historyReads: 0, historyCount: 1, status: 'NEW', readStatus: 200, loseResponse: false, viewInternal: true });
const comment = (i: number): Comment => ({ id: `00000000-0000-4000-8000-${String(i).padStart(12, '0')}`, ticket_id: 'qa-ticket', comment_text: `Trao đổi ${i}`, is_internal_staff_note: false, created_at: '2026-10-23T08:00:00+07:00', sender: { id: 'resident', full_name: 'Cư dân QA', role: 'Cư dân' } });

async function setup(page: Page, store: State, role: 'resident' | 'admin' = 'resident') {
  await page.addInitScript((role) => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Ticket Discussion QA' }));
    localStorage.setItem('smart_cassavas_token', 'qa-mocked-token');
  }, role);
  await page.route('**/api/**', async (route) => {
    const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
    if (path.endsWith('/comments')) {
      if (request.method() === 'POST') {
        const payload = request.postDataJSON(); store.payloads.push(payload);
        if (store.status !== 'NEW') return route.fulfill({ status: 409, json: { message: 'Yêu cầu đã kết thúc. Nội dung chưa được gửi.' } });
        let item = store.comments.find((row) => row.id === payload.id);
        const existed = !!item;
        if (!item) { item = { ...payload, ticket_id: 'qa-ticket', created_at: '2026-10-23T08:01:00+07:00', sender: { id: role, full_name: role === 'admin' ? 'Quản lý QA' : 'Cư dân QA', role: role === 'admin' ? 'Quản trị viên' : 'Cư dân' } }; store.comments.push(item!); }
        if (store.loseResponse) { store.loseResponse = false; return route.abort('failed'); }
        return route.fulfill({ status: existed ? 200 : 201, json: { data: item } });
      }
      store.reads++;
      if (store.readStatus !== 200) return route.fulfill({ status: store.readStatus, json: { message: 'QA lỗi tải' } });
      const offset = Number(url.searchParams.get('cursor') || 0);
      const staff = role === 'admin' && store.viewInternal;
      const rows = store.comments.filter((row) => (staff || !row.is_internal_staff_note) && (!url.searchParams.get('since') || row.created_at >= url.searchParams.get('since')!)).sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id.localeCompare(a.id));
      return route.fulfill({ json: { items: rows.slice(offset, offset + 15), next_cursor: offset + 15 < rows.length ? String(offset + 15) : null, meta: { status: store.status, ticket_version: 1, history_count: store.historyCount, can_comment: store.status === 'NEW', can_internal_note: staff && store.status === 'NEW', can_view_internal: staff } } });
    }
    if (path.endsWith('/history')) {
      store.historyReads++;
      const offset = Number(url.searchParams.get('cursor') || 0);
      const rows = Array.from({ length: store.historyCount }, (_, i) => ({ id: `history-${store.historyCount - i}`, ticket_id: 'qa-ticket', action_type: 'CREATED', new_status: 'NEW', created_at: '2026-10-23T08:00:00+07:00', notes: 'Tạo yêu cầu QA', performer: { id: 'resident', full_name: 'Cư dân QA', role: 'Cư dân' } }));
      return route.fulfill({ json: { items: rows.slice(offset, offset + 15), next_cursor: offset + 15 < rows.length ? String(offset + 15) : null } });
    }
    if (/\/tickets\/[^/]+$/.test(path) && !path.endsWith('/options') && !path.endsWith('/categories')) {
      const id = path.split('/').at(-1)!;
      const ticket = { id, ticket_number: 'TCK-QA-DISCUSSION', title: 'Sự cố cần trao đổi QA', description: 'Cần kiểm tra vòi nước.', location: 'Bếp', status: store.status, priority: 'MEDIUM', version: 1, created_at: '2026-10-23T08:00:00+07:00', attachments: [], history: [], actions: {} };
      return route.fulfill({ json: role === 'admin' ? ticket : { success: true, data: ticket } });
    }
    if (path.endsWith('/tickets/options')) return route.fulfill({ json: { categories: [], blocks: [], apartments: [], residents: [], can_create: false } });
    if (path.endsWith('/tickets')) return route.fulfill({ json: { success: true, data: [], pagination: { current_page: 1, last_page: 1, total: 0, per_page: 15 }, current_page: 1, last_page: 1, total: 0 } });
    return route.fulfill({ json: { success: true, data: [], items: [], unread_count: 0, page: 1, total_pages: 1, user: { full_name: 'Ticket QA' }, apartments: [] } });
  });
  await page.goto(`/${role === 'admin' ? 'admin' : 'cu-dan'}?tab=tickets&ticket_id=qa-ticket`);
  await expect(page.getByLabel('Nội dung trao đổi', { exact: true })).toBeEnabled();
}

test('MOCKED: hai phiên nhận bình luận, ghi chú nội bộ chỉ nhân viên và không tải lại lịch sử mỗi lượt', async ({ browser }) => {
  const store = state();
  const residentContext = await browser.newContext(); const adminContext = await browser.newContext();
  try {
    const resident = await residentContext.newPage(); const admin = await adminContext.newPage();
    await setup(resident, store); await setup(admin, store, 'admin');
    await resident.getByLabel('Nội dung trao đổi', { exact: true }).fill('Cư dân bổ sung tình trạng vòi nước.');
    await resident.getByRole('button', { name: 'Gửi bình luận', exact: true }).click();
    await expect(admin.getByText('Cư dân bổ sung tình trạng vòi nước.', { exact: true })).toBeVisible({ timeout: 10000 });
    await admin.getByLabel('Nội dung trao đổi', { exact: true }).fill('Ghi chú riêng cho kỹ thuật QA');
    await admin.getByLabel('Ghi chú nội bộ', { exact: true }).check();
    await admin.getByRole('button', { name: 'Gửi bình luận', exact: true }).click();
    await expect(admin.getByText('Ghi chú riêng cho kỹ thuật QA', { exact: true })).toBeVisible();
    const before = store.reads;
    await expect.poll(() => store.reads).toBeGreaterThan(before);
    await expect(resident.getByText('Ghi chú riêng cho kỹ thuật QA', { exact: true })).toHaveCount(0);
    await expect(resident.getByLabel('Ghi chú nội bộ', { exact: true })).toHaveCount(0);
    await admin.getByLabel('Nội dung trao đổi', { exact: true }).fill('Quản lý đã nhận thông tin.');
    await admin.getByRole('button', { name: 'Gửi bình luận', exact: true }).click();
    await expect(resident.getByText('Quản lý đã nhận thông tin.', { exact: true })).toBeVisible({ timeout: 10000 });
    expect(store.historyReads).toBe(2);
    await resident.getByRole('tab', { name: 'Lịch sử xử lý', exact: true }).click();
    await expect(resident.getByRole('list', { name: 'Lịch sử xử lý' })).toContainText('Tạo yêu cầu QA');
  } finally { await residentContext.close(); await adminContext.close(); }
});

test('MOCKED: mất phản hồi sau commit giữ bản nháp và retry cùng UUID; HTML hiển thị dạng chữ', async ({ page }) => {
  const store = state(); store.loseResponse = true;
  await setup(page, store);
  const field = page.getByLabel('Nội dung trao đổi', { exact: true });
  const text = '<script>window.qaInjected=true</script>\nNội dung giữ lại';
  await field.fill(text); await page.getByRole('button', { name: 'Gửi bình luận', exact: true }).click();
  await expect(field).toHaveValue(text);
  await expect(page.getByRole('alert')).toContainText(/fetch|network|failed/i);
  await page.getByRole('button', { name: 'Gửi bình luận', exact: true }).click();
  await expect(field).toHaveValue('');
  await expect(page.getByText(text, { exact: true })).toBeVisible();
  expect(store.payloads).toHaveLength(2);
  expect(store.payloads[0].id).toBe(store.payloads[1].id);
  expect(store.comments).toHaveLength(1);
  expect(await page.evaluate(() => (window as unknown as { qaInjected?: boolean }).qaInjected)).toBeUndefined();
});

test('MOCKED: phân trang cùng giây, giữ vị trí đọc và báo có bình luận mới', async ({ page }) => {
  const store = state(); store.comments = Array.from({ length: 35 }, (_, i) => comment(i + 1));
  await setup(page, store);
  const list = page.getByLabel('Danh sách bình luận', { exact: true });
  await expect(list.locator('article')).toHaveCount(15);
  await page.getByRole('button', { name: 'Tải nội dung trước', exact: true }).click();
  await expect(list.locator('article')).toHaveCount(30);
  await page.getByRole('button', { name: 'Tải nội dung trước', exact: true }).click();
  await expect(list.locator('article')).toHaveCount(35);
  await list.evaluate((element) => { element.scrollTop = 0; element.dispatchEvent(new Event('scroll')); });
  const anchor = list.locator('article').first(); const before = await anchor.boundingBox();
  store.comments.push({ ...comment(36), created_at: '2026-10-23T08:01:00+07:00' });
  await expect(page.getByRole('button', { name: /Có bình luận mới/ })).toBeVisible({ timeout: 10000 });
  const after = await anchor.boundingBox(); expect(Math.abs(after!.y - before!.y)).toBeLessThan(3);
  await page.getByRole('button', { name: /Có bình luận mới/ }).click();
  await expect(page.getByText('Trao đổi 36', { exact: true })).toBeVisible();
});

test('MOCKED: dừng polling khi ẩn tab, tải ngay khi quay lại và dừng khi hết phiên', async ({ page }) => {
  const store = state(); await page.clock.install(); await setup(page, store);
  await expect.poll(() => store.historyReads).toBe(1);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
  const reads = store.reads; await page.clock.fastForward(20000); expect(store.reads).toBe(reads);
  store.comments.push(comment(1));
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: false }); document.dispatchEvent(new Event('visibilitychange')); });
  await expect(page.getByText('Trao đổi 1', { exact: true })).toBeVisible();
  store.readStatus = 401; await page.clock.fastForward(3100);
  await expect(page.getByRole('link', { name: 'Đăng nhập lại', exact: true })).toBeVisible();
  await expect(page.getByText('Trao đổi 1', { exact: true })).toHaveCount(0);
  const stopped = store.reads; await page.clock.fastForward(30000); expect(store.reads).toBe(stopped);
});

test('MOCKED: đóng Ticket khi đang nhập giữ nội dung nhưng khóa gửi, mobile không tràn ngang và có bàn phím', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const store = state(); await setup(page, store);
  const field = page.getByLabel('Nội dung trao đổi', { exact: true });
  await field.fill('Bản nháp trước khi yêu cầu được đóng');
  store.status = 'CLOSED';
  await expect(field).toBeDisabled({ timeout: 10000 });
  await expect(field).toHaveValue('Bản nháp trước khi yêu cầu được đóng');
  await expect(page.getByRole('button', { name: 'Gửi bình luận', exact: true })).toBeDisabled();
  const tab = page.getByRole('tab', { name: 'Trao đổi', exact: true }); await tab.focus(); await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Lịch sử xử lý', exact: true })).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('MOCKED: lỗi tải giữ dữ liệu và bản nháp, backoff 3 rồi 6 giây trước khi hồi phục', async ({ page }) => {
  const store = state(); store.comments.push(comment(1));
  await page.clock.install({ time: new Date('2026-10-23T08:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-23T08:01:00Z'));
  await setup(page, store);
  await expect(page.getByText('Đang tải trao đổi…')).toHaveCount(0);
  const field = page.getByLabel('Nội dung trao đổi', { exact: true }); await field.fill('Bản nháp khi mất mạng');
  const first = store.reads; store.readStatus = 503;
  await page.clock.fastForward(3100);
  await expect(page.getByRole('alert')).toContainText('Nội dung đã có vẫn được giữ lại');
  expect(store.reads).toBe(first + 1);
  await expect(page.getByText('Trao đổi 1', { exact: true })).toBeVisible();
  await expect(field).toHaveValue('Bản nháp khi mất mạng');
  await page.clock.fastForward(2999); expect(store.reads).toBe(first + 1);
  await page.clock.fastForward(1); await expect.poll(() => store.reads).toBe(first + 2);
  store.readStatus = 200; await page.clock.fastForward(6000);
  await expect(page.getByRole('alert')).toHaveCount(0);
  await expect(field).toHaveValue('Bản nháp khi mất mạng');
});

test('MOCKED: thu hồi quyền nội bộ xóa nội dung đã tải và không tự chuyển bản nháp sang công khai', async ({ page }) => {
  const store = state(); store.comments.push({ ...comment(1), comment_text: 'Nội bộ cần bảo vệ', is_internal_staff_note: true });
  await setup(page, store, 'admin');
  await expect(page.getByText('Nội bộ cần bảo vệ', { exact: true })).toBeVisible();
  await page.getByLabel('Nội dung trao đổi', { exact: true }).fill('Bản nháp riêng');
  await page.getByLabel('Ghi chú nội bộ', { exact: true }).check(); store.viewInternal = false;
  await expect(page.getByText('Nội bộ cần bảo vệ', { exact: true })).toHaveCount(0, { timeout: 10000 });
  await expect(page.getByRole('button', { name: 'Gửi bình luận', exact: true })).toBeDisabled();
  await expect(page.getByLabel('Nội dung trao đổi', { exact: true })).toHaveValue('Bản nháp riêng');
  await page.getByLabel('Ghi chú nội bộ', { exact: true }).click();
  await expect(page.getByLabel('Ghi chú nội bộ', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Gửi bình luận', exact: true })).toBeEnabled();
});

test('MOCKED: lịch sử phân trang và nhận đủ hơn 15 sự kiện mới cùng giây', async ({ page }) => {
  const store = state(); store.historyCount = 35; await setup(page, store);
  await page.getByRole('tab', { name: 'Lịch sử xử lý', exact: true }).click();
  const rows = page.getByRole('list', { name: 'Lịch sử xử lý' }).locator('li');
  await expect(rows).toHaveCount(15);
  await page.getByRole('button', { name: 'Tải nội dung trước', exact: true }).click(); await expect(rows).toHaveCount(30);
  await page.getByRole('button', { name: 'Tải nội dung trước', exact: true }).click(); await expect(rows).toHaveCount(35);
  store.historyCount = 55;
  await expect(rows).toHaveCount(55, { timeout: 10000 });
});

test('MOCKED: request chậm không tạo polling chồng nhau khi nhận focus liên tiếp', async ({ page }) => {
  const store = state(); await page.clock.install(); await setup(page, store);
  await expect(page.getByText('Đang tải trao đổi…')).toHaveCount(0);
  let release!: () => void; const gate = new Promise<void>((resolve) => { release = resolve; });
  let running = 0, maximum = 0, calls = 0;
  await page.route('**/api/v1/tickets/qa-ticket/comments?*', async (route) => {
    running++; maximum = Math.max(maximum, running); calls++;
    if (calls === 1) await gate;
    await route.fallback(); running--;
  });
  await page.clock.fastForward(3100); await expect.poll(() => calls).toBe(1);
  await page.evaluate(() => { window.dispatchEvent(new Event('focus')); window.dispatchEvent(new Event('focus')); });
  await page.clock.fastForward(20000); expect(calls).toBe(1);
  release(); await expect.poll(() => running).toBe(0);
  await page.clock.fastForward(1);
  expect(maximum).toBe(1);
});

test('MOCKED: đổi Ticket khi request cũ chưa xong không hiển thị phản hồi cũ; SCSS giữ tương phản', async ({ page }) => {
  const store = state(); await setup(page, store);
  const tab = page.getByRole('tab', { name: 'Trao đổi', exact: true });
  expect(await tab.evaluate((element) => ({ background: getComputedStyle(element).backgroundColor, color: getComputedStyle(element).color }))).toEqual({ background: 'rgb(10, 10, 10)', color: 'rgb(255, 255, 255)' });
  let release!: () => void; const gate = new Promise<void>((resolve) => { release = resolve; });
  let held = false;
  await page.route('**/api/v1/tickets/qa-ticket/comments*', async (route) => {
    held = true; await gate; await route.fallback();
  });
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => held).toBe(true);
  store.comments = [{ ...comment(2), ticket_id: 'qa-next', comment_text: 'Nội dung Ticket mới' }];
  await page.goto('/cu-dan?tab=tickets&ticket_id=qa-next');
  await expect(page.getByText('Nội dung Ticket mới', { exact: true })).toBeVisible();
  store.comments = [{ ...comment(1), comment_text: 'Phản hồi cũ cần bỏ' }]; release();
  await expect(page.getByText('Phản hồi cũ cần bỏ', { exact: true })).toHaveCount(0);
});
