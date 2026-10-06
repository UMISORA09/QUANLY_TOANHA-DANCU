import { test, expect, Page } from '@playwright/test';

async function mockLogin(page: Page, role: string, options: { rejected?: boolean } = {}) {
  let account = 'A';
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/login')) {
      if (options.rejected) return route.fulfill({ status: 401, json: { message: 'Thông tin đăng nhập không hợp lệ.' } });
      account = route.request().postDataJSON().identifier.includes('second') ? 'B' : 'A';
      return route.fulfill({ json: {
        access_token: `portal-test-token-${account}`,
        user: { id: `portal-user-${account}`, full_name: `Portal QA ${account}`, email: `portal-${account.toLowerCase()}@example.test`, roles: [role], resident_type: 'OWNER' },
      } });
    }
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { id: `portal-user-${account}`, full_name: `Portal QA ${account}`, email: `portal-${account.toLowerCase()}@example.test`, roles: [role] } });
    return route.fulfill({ status: 503, json: { message: 'Dữ liệu tổng quan tạm thời không khả dụng.' } });
  });
}

async function submitLogin(page: Page, identifier = 'first-user') {
  await page.locator('input[type="text"]').fill(identifier);
  await page.locator('input[type="password"]').fill('test-password');
  await page.getByRole('button', { name: 'Đăng nhập hệ thống', exact: true }).click();
}

for (const portal of [
  { role: 'resident', path: '/cu-dan' },
  { role: 'manager', path: '/quan-ly' },
  { role: 'receptionist', path: '/le-tan' },
  { role: 'admin', path: '/admin' },
]) {
  test(`${portal.role}: đúng tài khoản khi tổng quan lỗi, đăng xuất và đổi tài khoản`, async ({ page }) => {
    await mockLogin(page, portal.role);
    await page.goto('/login');
    await submitLogin(page);
    await expect(page).toHaveURL(new RegExp(`${portal.path}$`));
    await expect(page.getByText('Portal QA A', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('portal-a@example.test', { exact: true }).first()).toBeVisible();

    await page.getByTitle('Đăng xuất', { exact: true }).click();
    await expect(page).toHaveURL(portal.role === 'admin' ? /\/login$/ : /\/home$/);
    expect(await page.evaluate(() => ['smartcassavas_session', 'smart_cassavas_token', 'smart_cassavas_user'].map((key) => localStorage.getItem(key)))).toEqual([null, null, null]);

    await page.goto('/login');
    await submitLogin(page, 'second-user');
    await expect(page).toHaveURL(new RegExp(`${portal.path}$`));
    await page.reload();
    await expect(page.getByText('Portal QA B', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('portal-b@example.test', { exact: true }).first()).toBeVisible();
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('smartcassavas_session') || '{}').name)).toBe('Portal QA B');
  });
}

test('đăng nhập không chờ bộ hẹn giờ chuyển cổng', async ({ page }) => {
  await mockLogin(page, 'resident');
  await page.goto('/login');
  await expect(page.getByRole('button', { name: 'Đăng nhập hệ thống', exact: true })).toBeVisible();
  await page.clock.install();
  await page.clock.pauseAt(new Date());
  await submitLogin(page);
  await expect(page).toHaveURL(/\/cu-dan$/);
});

test('API từ chối đăng nhập thì không tạo phiên giao diện', async ({ page }) => {
  await mockLogin(page, 'resident', { rejected: true });
  await page.goto('/login?role=resident');
  await submitLogin(page);
  await expect(page.getByText('Thông tin đăng nhập không hợp lệ.', { exact: true })).toBeVisible();
  await expect(page).toHaveURL(/\/login\?role=resident$/);
  expect(await page.evaluate(() => localStorage.getItem('smartcassavas_session'))).toBeNull();
});

test('phiên cũ lấy lại tên thật từ dữ liệu tài khoản đã lưu', async ({ page }) => {
  await mockLogin(page, 'resident');
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', email: 'portal-a@example.test', name: 'Nguyễn Văn An' }));
    localStorage.setItem('smart_cassavas_token', 'portal-test-token-A');
    localStorage.setItem('smart_cassavas_user', JSON.stringify({ full_name: 'Portal QA A', email: 'portal-a@example.test' }));
  });
  await page.goto('/cu-dan');
  await expect(page.getByText('Portal QA A', { exact: true }).first()).toBeVisible();
});

test('đăng xuất từ trang chủ xóa cả phiên giao diện và phiên API', async ({ page }) => {
  await mockLogin(page, 'resident');
  await page.goto('/login');
  await submitLogin(page);
  await expect(page).toHaveURL(/\/cu-dan$/);
  await page.goto('/home?landing=true');
  await page.getByTitle('Đăng xuất tài khoản', { exact: true }).click();
  expect(await page.evaluate(() => ['smartcassavas_session', 'smart_cassavas_token', 'smart_cassavas_user'].map((key) => localStorage.getItem(key)))).toEqual([null, null, null]);
  await expect(page).toHaveURL(/\/home$/);
  await expect(page.getByTitle('Đăng xuất tài khoản', { exact: true })).not.toBeVisible();
});
