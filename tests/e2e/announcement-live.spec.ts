import { test, expect as baseExpect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile } from 'node:child_process';

const expect = baseExpect.configure({ timeout: 30000 });
test.use({ actionTimeout: 30000 });

test.skip(!process.env.ANNOUNCEMENT_BROWSER_FIXTURE || !process.env.ANNOUNCEMENT_REAL_API_CONTAINER, 'Requires isolated announcement QA fixture/API.');
test('API thật QA: soạn, tải PDF private, bỏ tệp, hẹn đăng, gỡ và lưu trữ', async ({ page }) => {
  test.setTimeout(240000);
  const fixture = JSON.parse(readFileSync(process.env.ANNOUNCEMENT_BROWSER_FIXTURE!, 'utf8'));
  await page.addInitScript((token) => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'QA Admin' })); localStorage.setItem('smart_cassavas_token', token); }, fixture.token);
  await page.route('**/api/**', async (route) => {
    const req = route.request(); const url = new URL(req.url());
    if (!url.pathname.includes('/admin/announcements')) return route.fulfill({ json: { data: [], items: [], unread_count: 0, page: 1, total_pages: 1 } });
    const args = ['exec', '-i', process.env.ANNOUNCEMENT_REAL_API_CONTAINER!, 'curl', '--silent', '--show-error', '--max-time', '45', '--request', req.method(), '--header', `Authorization: Bearer ${fixture.token}`, '--header', 'Accept: application/json'];
    if (req.headers()['content-type']) args.push('--header', `Content-Type: ${req.headers()['content-type']}`);
    const body = req.postDataBuffer(); if (body) args.push('--data-binary', '@-');
    args.push('--write-out', '\n%{http_code}', `http://127.0.0.1:9040${url.pathname}${url.search}`);
    const output = await new Promise<Buffer>((resolve, reject) => { const child = execFile('docker', args, { encoding: 'buffer', maxBuffer: 10 * 1024 * 1024 }, (error, stdout) => error ? reject(new Error('QA transport failed')) : resolve(stdout)); child.stdin?.end(body || undefined); });
    const split = output.lastIndexOf(10); const status = Number(output.subarray(split + 1).toString());
    await route.fulfill({ status, contentType: url.pathname.includes('/attachments/') && status === 200 ? 'application/pdf' : 'application/json', body: output.subarray(0, split) });
  });
  await page.goto('/admin?tab=news'); await page.getByRole('button', { name: 'Soạn thông báo', exact: true }).click();
  await page.getByLabel('Tiêu đề', { exact: true }).fill('Browser QA bảo trì thang máy'); await page.getByRole('textbox', { name: 'Nội dung bảng tin', exact: true }).fill('Kính gửi cư dân, vui lòng theo dõi lịch bảo trì.');
  await page.getByLabel('Tệp đính kèm', { exact: true }).setInputFiles({ name: 'rules.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF'), Buffer.alloc(5 * 1024 * 1024 - 46, 32)]) });
  await page.getByRole('button', { name: 'Xem trước', exact: true }).click(); await expect(page.locator('.announcement-content')).toContainText('Kính gửi cư dân'); await page.getByRole('button', { name: 'Quay lại bản nhập', exact: true }).click();
  const created = page.waitForResponse((response) => response.url().endsWith('/admin/announcements') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click(); expect((await created).status()).toBe(201);
  const downloaded = page.waitForResponse((response) => response.url().includes('/attachments/'));
  await page.getByRole('button', { name: 'Tải rules.pdf', exact: true }).click(); expect((await downloaded).status()).toBe(200);
  await page.getByRole('button', { name: 'Sửa bài viết', exact: true }).click(); await page.getByRole('button', { name: 'Bỏ tệp rules.pdf', exact: true }).click(); await page.getByRole('button', { name: 'Xác nhận', exact: true }).click(); await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Tải rules.pdf', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Chuẩn bị đăng', exact: true }).click(); await page.getByLabel('Thời điểm đăng', { exact: false }).fill('2027-01-01T09:00');
  await page.getByRole('button', { name: 'Lưu và hẹn đăng', exact: true }).click(); await expect(page.getByRole('button', { name: 'Gỡ đăng', exact: true })).toBeVisible({ timeout: 30000 });
  await page.getByRole('button', { name: 'Gỡ đăng', exact: true }).click(); await page.getByLabel('Lý do', { exact: true }).fill('Hoãn lịch bảo trì theo yêu cầu'); await page.getByRole('button', { name: 'Xác nhận', exact: true }).click();
  await page.getByRole('button', { name: 'Lưu trữ', exact: true }).click(); await page.getByLabel('Lý do', { exact: true }).fill('Lưu trữ bài mẫu QA'); await page.getByRole('button', { name: 'Xác nhận', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0, { timeout: 30000 });
});
