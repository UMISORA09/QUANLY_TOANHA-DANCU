import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import react from '@vitejs/plugin-react';
let server: ViteDevServer;
const origin = 'http://127.0.0.1:5178';
test.beforeAll(async () => {
  server = await createServer({ configFile: false, root: process.cwd(),
    cacheDir: 'storage/framework/testing/vite-auth-modal', plugins: [react()],
    server: { host: '127.0.0.1', port: 5178, strictPort: true } });
  await server.listen();
});
test.afterAll(async () => { await server?.close(); });
test.use({ screenshot: 'off', video: 'off', trace: 'off' });
test.beforeEach(async ({ page }) => {
  await page.route('**/qa/auth-modal', route => route.fulfill({ contentType: 'text/html',
    body: `<div id="root"></div><script type="module">
      import RefreshRuntime from '/@react-refresh';
      RefreshRuntime.injectIntoGlobalHook(window);
      window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => type => type;
      window.__vite_plugin_react_preamble_installed__ = true;
      </script><script type="module" src="/tests/e2e/fixtures/auth-modal.tsx"></script>` }));
});
for (const status of [401, 403, 500, 200]) {
  test(`failed or malformed login ${status} never authenticates or closes the modal`, async ({ page }) => {
    await page.route('**/api/v1/auth/login', route => route.fulfill({ status, json: { message: 'Login rejected', user: { id: 'qa', roles: ['admin'] } } }));
    await page.goto(`${origin}/qa/auth-modal`);
    await page.locator('button[type="submit"]').first().click();
    await expect(page.getByText(status === 200 ? 'Phản hồi đăng nhập không có phiên hoặc quyền hợp lệ.' : 'Login rejected', { exact: false }).last()).toBeVisible();
    await expect(page.getByTestId('authenticated-role')).toBeEmpty();
    await expect(page.getByTitle('Đóng', { exact: true })).toBeVisible();
    await expect(page.locator('button[type="submit"]').first()).toBeEnabled();
    expect(await page.evaluate(() => localStorage.getItem('smart_cassavas_token'))).toBeNull();
    await page.reload();
    await expect(page.getByTestId('authenticated-role')).toBeEmpty();
  });
}
test('network failure preserves modal and does not manufacture a session', async ({ page }) => {
  await page.route('**/api/v1/auth/login', route => route.abort('timedout'));
  await page.goto(`${origin}/qa/auth-modal`);
  await page.locator('button[type="submit"]').first().click();
  await expect(page.locator('button[type="submit"]').first()).toBeEnabled();
  await expect(page.getByTitle('Đóng', { exact: true })).toBeVisible();
  await expect(page.getByTestId('authenticated-role')).toBeEmpty();
  expect(await page.evaluate(() => localStorage.getItem('smart_cassavas_token'))).toBeNull();
});
test('successful login uses server role rather than the selected demo role', async ({ page }) => {
  await page.route('**/api/v1/auth/login', route => route.fulfill({ json: { success: true,
    access_token: 'qa-real-session-response', user: { id: 'qa', email: 'qa@example.test', roles: ['resident'] } } }));
  await page.goto(`${origin}/qa/auth-modal`);
  await page.locator('button[type="submit"]').first().click();
  await expect(page.getByTestId('authenticated-role')).toHaveText('resident');
  await expect(page.getByTitle('Đóng', { exact: true })).toHaveCount(0);
});
