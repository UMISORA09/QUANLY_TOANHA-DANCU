import { test, expect } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

test('navigation retains the current page while a lazy route loads without another document request', async ({ page }) => {
  let releaseLogin: () => void = () => {};
  const gate = new Promise<void>(resolve => { releaseLogin = resolve; });
  let loginRequested = false;
  let documentRequests = 0;
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documentRequests++;
  });
  await page.route(/\/build\/assets\/LoginPage-[^/]+\.js$/, async route => {
    loginRequested = true;
    await gate;
    await route.continue();
  });
  try {
    await page.goto('/home');
    const homeTitle = page.getByRole('heading', { level: 1 });
    await expect(homeTitle).toContainText('Mọi vai trò');
    await page.getByRole('button', { name: 'Đăng nhập', exact: true }).first().click();
    await expect.poll(() => loginRequested).toBe(true);
    await expect(homeTitle).toContainText('Mọi vai trò');
    releaseLogin();
    await expect(page.getByRole('heading', { name: 'Đăng nhập tài khoản', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Về trang chủ', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Mọi vai trò');
    expect(documentRequests).toBe(1);
  } finally {
    releaseLogin();
  }
});

for (const [role, portal, destination] of [
  ['admin', 'DevConsolePage', '/admin'],
  ['manager', 'ManagementHome', '/quan-ly'],
  ['receptionist', 'ReceptionHome', '/le-tan'],
  ['resident', 'ResidentHome', '/cu-dan'],
]) {
  test(`successful ${role} login shows loading while its portal downloads`, async ({ page }) => {
    let releasePortal: () => void = () => {};
    const gate = new Promise<void>(resolve => { releasePortal = resolve; });
    let portalRequested = false;
    await page.route(new RegExp(`/build/assets/${portal}-[^/]+\\.js$`), async route => {
      portalRequested = true;
      await gate;
      await route.continue();
    });
    await page.route('**/api/**', route => route.fulfill({ json: { success: true, data: [] } }));
    await page.route('**/api/v1/auth/login', route => route.fulfill({ json: {
      success: true, access_token: 'qa-authenticated-session',
      user: { id: 'qa-user', email: 'qa@example.test', roles: [role], resident_type: 'OWNER' },
    } }));
    try {
      await page.goto('/login');
      await page.locator('input[type="text"]').first().fill('qa@example.test');
      await page.locator('input[type="password"]').first().fill('qa-password');
      await page.locator('button[type="submit"]').first().click();
      await expect.poll(() => portalRequested).toBe(true);
      await expect(page.getByRole('heading', { name: 'Đăng nhập tài khoản', exact: true })).toHaveCount(0);
      await expect(page.getByRole('status')).toContainText('Đang tải trang');
      releasePortal();
      await expect(page.getByText('Đang tải trang…', { exact: true })).toHaveCount(0);
      await expect(page.locator('h1,h2').first()).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Đã xảy ra lỗi giao diện', exact: true })).toHaveCount(0);
      await expect(page).toHaveURL(new RegExp(`${destination}$`));
    } finally {
      releasePortal();
    }
  });
}
