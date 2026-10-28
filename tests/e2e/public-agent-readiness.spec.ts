import { test, expect } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

test('public endpoints negotiate content and expose valid discovery files over HTTP', async ({ request }) => {
  for (const path of ['/', '/home', '/about', '/contact', '/privacy', '/docs']) {
    const markdown = await request.get(path, { headers: { Accept: 'text/markdown' } });
    expect(markdown.status(), path).toBe(200);
    expect(markdown.headers()['content-type']).toContain('text/markdown');
    expect(markdown.headers().vary).toContain('Accept');
    expect((await markdown.text()).length).toBeGreaterThan(500);
    const html = await request.get(path, { headers: { Accept: 'text/html' } });
    expect(html.status(), path).toBe(200);
    expect(html.headers()['content-type']).toContain('text/html');
    expect(await html.text()).toContain('<h1>');
  }
  const missing = await request.get('/unknown-agent-guide', { headers: { Accept: 'text/markdown' } });
  expect(missing.status()).toBe(404);
  expect(missing.headers()['content-type']).toContain('text/markdown');
  expect(await missing.text()).toContain('/docs');
  for (const path of ['/llms.txt', '/sitemap.xml', '/openapi.json', '/.well-known/mcp', '/social-card.png', '/robots.txt']) {
    const response = await request.get(path);
    expect(response.status(), path).toBe(200);
    expect((await response.body()).length, path).toBeGreaterThan(20);
  }
  const spec = await (await request.get('/openapi.json')).json();
  expect(spec.openapi).toBe('3.1.0');
  const discovery = await (await request.get('/.well-known/mcp')).json();
  expect(discovery.transport).toBe('streamable-http');
  const result = await request.post('/mcp/public', {
    headers: { Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-11-25' },
    data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'read-public-guide', arguments: { page: 'docs' } } },
  });
  expect(result.status()).toBe(200);
  expect((await result.json()).result.content[0].text).toContain('# Tài liệu SMART CASSAVAS');
});

test('homepage remains useful with JavaScript disabled', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
  try {
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('SMART CASSAVAS — Nền tảng quản lý tòa nhà');
    await expect(page.getByRole('main')).toContainText('đăng ký sử dụng tiện ích');
    await page.getByRole('link', { name: 'Tài liệu SMART CASSAVAS' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tài liệu SMART CASSAVAS');
  } finally {
    await context.close();
  }
});

test('React homepage preserves its interface and links to real public pages', async ({ page }) => {
  await page.goto('/home');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Mọi vai trò');
  await expect(page.locator('footer a[href="/privacy"]')).toHaveText('Bảo mật');
  await page.locator('footer a[href="/docs"]').click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tài liệu SMART CASSAVAS');
});

for (const width of [390, 1280]) {
  test(`public guides remain readable and keyboard accessible at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 850 });
    for (const path of ['/about', '/contact', '/privacy', '/docs']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      expect((await page.locator('main').innerText()).length).toBeGreaterThan(500);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      await page.keyboard.press('Tab');
      await expect(page.locator('header a')).toBeFocused();
    }
  });
}
