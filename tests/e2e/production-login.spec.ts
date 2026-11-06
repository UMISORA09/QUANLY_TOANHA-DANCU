import { test, expect } from '@playwright/test';
import { build } from 'vite';
import react from '@vitejs/plugin-react';

let bundle: string;
const origin = 'https://login-qa.example.test';

test.beforeAll(async () => {
  const previousNodeEnv = process.env.NODE_ENV;
  try {
    process.env.NODE_ENV = 'production';
    const result = await build({
      configFile: false,
      mode: 'production',
      plugins: [react()],
      build: {
        write: false,
        rollupOptions: {
          input: 'tests/e2e/fixtures/auth-modal.tsx',
          output: { codeSplitting: false },
        },
      },
    });
    if (Array.isArray(result) || !('output' in result)) {
      throw new Error('Expected one production build');
    }
    bundle = result.output.filter(item => item.type === 'chunk').map(item => item.code).join('\n');
  } finally {
    if (previousNodeEnv === undefined) {
      delete process.env.NODE_ENV;
    } else {
      process.env.NODE_ENV = previousNodeEnv;
    }
  }
});

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

test('production bundle excludes demo passwords and quick-login instructions', () => {
  expect(bundle).not.toMatch(/["']123567["']/);
  expect(bundle).not.toContain('MK mẫu:');
  expect(bundle).not.toContain('Tài khoản dùng thử nhanh:');
  expect(bundle).not.toContain('Chọn một vai trò để vào thẳng khu vực làm việc.');
});

for (const view of ['modal', 'login']) {
  for (const width of [1280, 390]) {
    test(`production ${view} at ${width}px requires manual login and uses the server role`, async ({ page }) => {
      await page.setViewportSize({ width, height: 844 });
      await page.route('**/qa/production-auth.js', route => route.fulfill({ contentType: 'text/javascript', body: bundle }));
      await page.route('**/qa/production-login?*', route => route.fulfill({ contentType: 'text/html',
        body: '<div id="root"></div><script type="module" src="/qa/production-auth.js"></script>' }));
      await page.route('**/api/v1/auth/login', async route => {
        expect(route.request().postDataJSON()).toMatchObject({ identifier: 'qa@example.test', password: 'qa-manual-password' });
        await route.fulfill({ json: { success: true, access_token: 'qa-session-from-server',
          user: { id: 'qa', email: 'qa@example.test', roles: ['resident'] } } });
      });
      await page.goto(`${origin}/qa/production-login?view=${view}&role=admin`);
      await expect(page.getByText('Tài khoản dùng thử nhanh:')).toHaveCount(0);
      await expect(page.getByText('TRUY CẬP NHANH')).toHaveCount(0);
      await expect(page.getByText('MK mẫu:', { exact: false })).toHaveCount(0);
      const identifier = page.locator('input[type="text"]').first();
      const password = page.locator('input[type="password"]').first();
      await expect(identifier).toHaveValue('');
      await expect(password).toHaveValue('');
      await identifier.fill('qa@example.test');
      await password.fill('qa-manual-password');
      await page.locator('button[type="submit"]').first().click();
      await expect(page.getByTestId('authenticated-role')).toHaveText('resident');
    });
  }
}
