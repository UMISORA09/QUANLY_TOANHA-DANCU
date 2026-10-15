import { test, expect as baseExpect, Page, BrowserContext } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile } from 'node:child_process';

const fixturePath = process.env.TICKET_BROWSER_FIXTURE;
test.use({ actionTimeout: 30000 });
test.describe.configure({ timeout: 360000 });
const expect = baseExpect.configure({ timeout: 30000 });
test.skip(!fixturePath || !process.env.TICKET_REAL_API_CONTAINER, 'Requires isolated Ticket QA fixture and multi-worker API on port 9038.');

test('API thật: ảnh private, quản lý phân công, kỹ thuật sửa, cư dân mở lại và nghiệm thu', async ({ browser }) => {
  test.setTimeout(360000);
  const fixture = JSON.parse(readFileSync(fixturePath!, 'utf8'));
  const base = process.env.APP_URL || 'http://127.0.0.1:8026';
  const contexts: BrowserContext[] = [];
  const actor = async (role: string, token: string): Promise<Page> => {
    const context = await browser.newContext(); contexts.push(context);
    await context.addInitScript(({ role, token }) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role, name: 'Ticket Browser QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', token);
      localStorage.setItem('smart_cassavas_user', JSON.stringify({ role, full_name: 'Ticket Browser QA', permissions: ['TICKET:VIEW', 'TICKET:UPDATE', 'TICKET:RESOLVE'] }));
    }, { role, token });
    await context.route('**/api/**', async (route) => {
      const incoming = route.request(); const url = new URL(incoming.url());
      const args = ['exec', '-i', process.env.TICKET_REAL_API_CONTAINER!, 'curl', '--silent', '--show-error', '--max-time', '45', '--request', incoming.method(), '--header', `Authorization: ${incoming.headers().authorization || ''}`, '--header', 'Accept: application/json'];
      if (incoming.headers()['content-type']) args.push('--header', `Content-Type: ${incoming.headers()['content-type']}`);
      const body = incoming.postDataBuffer(); if (body) args.push('--data-binary', '@-');
      args.push('--write-out', '\n%{http_code}', `http://127.0.0.1:9038${url.pathname}${url.search}`);
      const output = await new Promise<Buffer>((resolve, reject) => {
        const child = execFile('docker', args, { encoding: 'buffer', maxBuffer: 10 * 1024 * 1024 }, (error, stdout) => error ? reject(new Error('QA HTTP transport failed')) : resolve(stdout));
        child.stdin?.end(body || undefined);
      });
      const split = output.lastIndexOf(10); const status = Number(output.subarray(split + 1).toString());
      await route.fulfill({ status, contentType: incoming.method() === 'GET' && url.pathname.includes('/attachments/') && status === 200 ? 'image/png' : 'application/json', body: output.subarray(0, split) });
    });
    return context.newPage();
  };
  const photo = { name: 'qa.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5ZkAAAAASUVORK5CYII=', 'base64') };
  try {
    const resident = await actor('resident', fixture.resident_token);
    await resident.goto(`${base}/cu-dan?tab=tickets`);
    await resident.locator('#ticket-open-create-btn').click();
    await resident.locator('#ticket-category-select').click();
    await resident.getByRole('option', { name: 'Browser QA Điện nước', exact: true }).click();
    await resident.locator('#ticket-title-input').fill('Browser QA sửa vòi nước');
    await resident.locator('#ticket-location-input').fill('Phòng bếp');
    await resident.locator('#ticket-description-textarea').fill('Vòi nước bị rò rỉ, cần kiểm tra.');
    await resident.locator('input[type="file"]').first().setInputFiles(photo);
    const created = resident.waitForResponse((response) => response.url().endsWith('/resident/tickets') && response.request().method() === 'POST');
    await resident.locator('#ticket-submit-btn').click();
    const response = await created; expect(response.status()).toBe(201);
    const id = (await response.json()).data.id;
    const admin = await actor('admin', fixture.admin_token);
    await admin.goto(`${base}/admin?tab=tickets&ticket_id=${id}`);
    await admin.getByRole('button', { name: 'Tiếp nhận', exact: true }).click();
    await admin.getByRole('dialog', { name: 'Tiếp nhận', exact: true }).getByRole('button', { name: 'Lưu yêu cầu', exact: true }).click();
    await admin.getByRole('button', { name: 'Phân công kỹ thuật', exact: true }).click();
    const assignment = admin.getByRole('dialog', { name: 'Phân công kỹ thuật', exact: true });
    await assignment.getByLabel('Kỹ thuật viên', { exact: true }).click();
    await admin.getByRole('option', { name: 'Kỹ thuật Ticket Browser QA', exact: true }).click();
    const assigned = admin.waitForResponse((response) => response.url().endsWith(`/tickets/${id}/workflow`) && response.request().method() === 'POST');
    await assignment.getByRole('button', { name: 'Lưu yêu cầu', exact: true }).click();
    expect((await assigned).status()).toBe(200);
    await expect(admin.getByRole('dialog').getByText('Kỹ thuật viên: Kỹ thuật Ticket Browser QA')).toBeVisible({ timeout: 30000 });
    await admin.close();
    const tech = await actor('technician', fixture.technician_token);
    const techUrl = `${base}/quan-ly?tab=tickets&ticket_id=${id}`;
    await tech.goto(techUrl);
    await expect(tech.getByRole('button', { name: 'Thêm yêu cầu', exact: true })).toHaveCount(0);
    await tech.getByRole('button', { name: 'Bắt đầu xử lý', exact: true }).click();
    await tech.getByRole('dialog', { name: 'Bắt đầu xử lý' }).getByRole('button', { name: 'Lưu yêu cầu' }).click();
    await tech.getByLabel('Ảnh kết quả xử lý').setInputFiles({ ...photo, name: 'proof.png' });
    await expect(tech.getByRole('dialog').getByRole('button', { name: /Xem ảnh/ })).toHaveCount(2);
    const resolve = async () => {
      await tech.getByRole('button', { name: 'Báo đã khắc phục', exact: true }).click();
      const dialog = tech.getByRole('dialog', { name: 'Báo đã khắc phục' });
      await dialog.getByLabel('Ghi chú xử lý').fill('Đã kiểm tra và thay van nước.');
      await dialog.getByRole('button', { name: 'Lưu yêu cầu', exact: true }).click();
      await expect(tech.getByRole('dialog')).toContainText('Chờ nghiệm thu');
    };
    await resolve();
    await resident.goto(`${base}/cu-dan?tab=tickets&ticket_id=${id}`);
    await resident.getByRole('button', { name: 'Chưa khắc phục xong', exact: true }).click();
    await resident.getByRole('dialog', { name: 'Phản hồi chưa đạt' }).locator('textarea').fill('Vòi nước vẫn còn bị rò rỉ.');
    await resident.getByRole('button', { name: 'Gửi phản hồi xử lý lại', exact: true }).click();
    await expect(resident.getByRole('dialog', { name: 'Phản hồi chưa đạt' })).toHaveCount(0);
    await tech.goto(techUrl); await resolve();
    await resident.goto(`${base}/cu-dan?tab=tickets&ticket_id=${id}`);
    await resident.getByRole('button', { name: 'Xác nhận đã sửa xong', exact: true }).click();
    await resident.getByRole('button', { name: 'Xác nhận hoàn thành', exact: true }).click();
    await expect(resident.getByRole('dialog', { name: 'Xác nhận nghiệm thu' })).toHaveCount(0);
    await expect(resident.getByRole('button', { name: 'Xác nhận đã sửa xong', exact: true })).toHaveCount(0);
  } finally { for (const context of contexts) await context.close(); }
});
