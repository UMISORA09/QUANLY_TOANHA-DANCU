import { test, expect as baseExpect, BrowserContext } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile } from 'node:child_process';

const expect = baseExpect.configure({ timeout: 45000 });
test.skip(!process.env.RESIDENT_ANNOUNCEMENT_BROWSER_FIXTURE || !process.env.ANNOUNCEMENT_REAL_API_CONTAINER, 'Requires isolated F11 QA fixture/API.');

test('API MySQL QA thật: BQL đăng/gỡ, hai cư dân nhận chuông, đọc, tải tệp và xác nhận độc lập', async ({ browser }) => {
  test.setTimeout(300000);
  const fixture = JSON.parse(readFileSync(process.env.RESIDENT_ANNOUNCEMENT_BROWSER_FIXTURE!, 'utf8'));
  const contexts: BrowserContext[] = [];
  const create = async (role: string, token: string) => {
    const context = await browser.newContext(); contexts.push(context);
    await context.addInitScript(({ role, token }) => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'QA bảng tin' })); localStorage.setItem('smart_cassavas_token', token); }, { role, token });
    await context.route('**/api/**', async (route) => {
      const req = route.request(); const url = new URL(req.url());
      if (!/^\/api\/v1\/(admin|resident)\/announcements/.test(url.pathname)) return route.fulfill({ json: { data: [], items: [], unread_count: 0, page: 1, total_pages: 1 } });
      const args = ['exec', '-i', process.env.ANNOUNCEMENT_REAL_API_CONTAINER!, 'curl', '--silent', '--show-error', '--max-time', '60', '--request', req.method(), '--header', `Authorization: Bearer ${token}`, '--header', 'Accept: application/json'];
      if (req.headers()['content-type']) args.push('--header', `Content-Type: ${req.headers()['content-type']}`);
      const body = req.postDataBuffer(); if (body) args.push('--data-binary', '@-');
      args.push('--write-out', '\n%{http_code}', `http://127.0.0.1:9041${url.pathname}${url.search}`);
      const output = await new Promise<Buffer>((resolve, reject) => { const child = execFile('docker', args, { encoding: 'buffer', maxBuffer: 8 * 1024 * 1024 }, (error, stdout) => error ? reject(new Error('QA transport failed')) : resolve(stdout)); child.stdin?.end(body || undefined); });
      const split = output.lastIndexOf(10); const status = Number(output.subarray(split + 1).toString());
      await route.fulfill({ status, contentType: url.pathname.includes('/attachments/') && status === 200 ? 'application/pdf' : 'application/json', body: output.subarray(0, split) }).catch(() => {});
    });
    return context.newPage();
  };
  try {
    const admin = await create('admin', fixture.admin); const resident = await create('resident', fixture.resident); const second = await create('resident', fixture.second);
    await admin.goto('/admin?tab=news'); await resident.goto('/cu-dan?tab=news'); await second.goto('/cu-dan?tab=news');
    await expect(resident.getByRole('heading', { name: fixture.title, exact: true })).toHaveCount(0);
    await admin.locator('.announcement-card').filter({ hasText: fixture.title }).getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
    await admin.getByRole('button', { name: 'Chuẩn bị đăng', exact: true }).click(); await expect(admin.getByLabel('Yêu cầu xác nhận đã đọc', { exact: true })).toBeChecked(); await admin.getByLabel('Thời điểm đăng', { exact: false }).fill(''); await admin.getByRole('button', { name: 'Lưu và đăng ngay', exact: true }).click(); await expect(admin.getByRole('button', { name: 'Gỡ đăng', exact: true })).toBeVisible();
    await resident.getByRole('button', { name: /Thông báo hệ thống/ }).click();
    await resident.getByRole('region', { name: 'Thông báo từ ban quản lý' }).getByRole('button', { name: new RegExp(fixture.title) }).click();
    const firstDialog = resident.getByRole('dialog', { name: fixture.title, exact: true }); await expect(firstDialog).toContainText('Lịch bảo trì dành cho cư dân QA.');
    const confirm = firstDialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }); await expect(confirm).toBeEnabled();
    const download = resident.waitForEvent('download'); await firstDialog.getByRole('button', { name: 'Tải resident-qa.pdf', exact: true }).click(); expect((await download).suggestedFilename()).toBe('resident-qa.pdf');
    await confirm.click(); await expect(firstDialog.getByRole('status')).toContainText('Bạn đã xác nhận'); await firstDialog.getByRole('button', { name: 'Đóng', exact: true }).click();
    const secondCard = second.locator('.announcement-card').filter({ hasText: fixture.title }); await expect(secondCard).toContainText('Chưa đọc');
    await secondCard.getByRole('button', { name: `Đọc thông báo: ${fixture.title}`, exact: true }).click(); const secondDialog = second.getByRole('dialog', { name: fixture.title, exact: true });
    await secondDialog.getByRole('button', { name: 'Xác nhận đã đọc', exact: true }).click(); await expect(secondDialog.getByRole('status')).toContainText('Bạn đã xác nhận'); await secondDialog.getByRole('button', { name: 'Đóng', exact: true }).click();
    await admin.getByRole('button', { name: 'Gỡ đăng', exact: true }).click(); await admin.getByLabel('Lý do', { exact: true }).fill('QA kết thúc bản tin kiểm thử'); await admin.getByRole('button', { name: 'Xác nhận', exact: true }).click();
    await expect(admin.getByRole('button', { name: 'Chuẩn bị đăng', exact: true })).toBeVisible();
    await resident.getByRole('button', { name: 'Tải lại', exact: true }).click(); await second.getByRole('button', { name: 'Tải lại', exact: true }).click();
    await expect(resident.locator('.announcement-card').filter({ hasText: fixture.title })).toHaveCount(0); await expect(second.locator('.announcement-card').filter({ hasText: fixture.title })).toHaveCount(0);
  } finally { await Promise.all(contexts.map((context) => context.close())); }
});
