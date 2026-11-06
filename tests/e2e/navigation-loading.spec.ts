import { test, expect } from '@playwright/test';

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
