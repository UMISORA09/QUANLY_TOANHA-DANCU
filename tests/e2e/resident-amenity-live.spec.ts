import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const fixturePath = process.env.RESIDENT_BROWSER_FIXTURE;
test.skip(!fixturePath || !process.env.RESIDENT_REAL_API_URL, 'Requires a dedicated resident QA server and database fixture.');

test('cư dân đăng ký, xem và hủy bằng API thật, kiểm tra quyền và giải phóng chỗ', async ({ page, request }) => {
  test.setTimeout(180000);
  const fixture = JSON.parse(readFileSync(fixturePath!, 'utf8'));
  const base = process.env.RESIDENT_REAL_API_URL!;
  const container = process.env.RESIDENT_REAL_API_CONTAINER;
  const execute = promisify(execFile);
  const containerRequest = async (url: string, method: string, token: string, data?: string) => {
    const args = ['exec', container!, 'curl', '--silent', '--show-error', '--max-time', '30', '--request', method, '--header', `Authorization: Bearer ${token}`, '--header', 'Accept: application/json', '--header', 'Content-Type: application/json'];
    if (data) args.push('--data', data);
    args.push('--write-out', '\n%{http_code}', `http://127.0.0.1:9001${new URL(url).pathname}${new URL(url).search}`);
    const { stdout } = await execute(process.env.RESIDENT_DOCKER_BIN || 'docker', args);
    const split = stdout.lastIndexOf('\n');
    return { status: Number(stdout.slice(split + 1)), body: stdout.slice(0, split) };
  };
  if (container) {
    await page.route('**/api/**', async (route) => {
      const incoming = route.request();
      const result = await containerRequest(incoming.url(), incoming.method(), incoming.headers().authorization?.replace(/^Bearer /, '') || '', incoming.postData() || undefined);
      await route.fulfill({ status: result.status, contentType: 'application/json', body: result.body });
    });
  }
  const callApi = async (path: string, method: 'GET' | 'POST', token: string) => {
    if (container) {
      const result = await containerRequest(`${base}${path}`, method, token, method === 'POST' ? '{}' : undefined);
      return { status: result.status, data: JSON.parse(result.body) };
    }
    const response = await request.fetch(`${base}${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, ...(method === 'POST' ? { data: {} } : {}) });
    return { status: response.status(), data: await response.json() };
  };
  await page.addInitScript(({ token }) => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', resident_type: 'OWNER', name: 'Cư dân Browser QA', email: 'browser@example.test' }));
    localStorage.setItem('smart_cassavas_token', token);
  }, { token: fixture.token });
  await page.goto(`${base}/cu-dan?tab=amenities`);
  await page.getByRole('button', { name: /Browser QA BBQ/ }).click();
  await page.getByRole('button', { name: 'Ngày mai', exact: true }).click();
  await page.getByRole('button', { name: /10:00.*11:30/ }).click();
  await page.getByLabel('Số người', { exact: true }).fill('2');
  await page.getByLabel('Ghi chú', { exact: true }).fill('Browser API thật');
  await page.getByRole('checkbox').check();
  const created = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith('/resident/amenity-bookings'), { timeout: 60000 });
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  const response = await created;
  expect(response.status()).toBe(201);
  const result = await response.json();
  expect(result.booking).toMatchObject({ resident_user_id: fixture.user_ids[0], apartment_id: fixture.apartment_id, booking_date: fixture.date, total_amount: 150000, deposit_amount: 50000, attendee_count: 2, status: 'PENDING' });
  await expect(page.getByRole('status').filter({ hasText: 'Đăng ký thành công' })).toContainText(result.booking.booking_code, { timeout: 30000 });
  await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Chi tiết đăng ký' })).toContainText('Browser API thật');
  await page.getByRole('button', { name: 'Đóng', exact: true }).click();
  expect((await callApi(`/api/v1/resident/amenity-bookings/${result.booking.id}`, 'GET', fixture.second_token)).status).toBe(404);
  expect((await callApi(`/api/v1/resident/amenity-bookings/${result.booking.id}/cancel`, 'POST', fixture.second_token)).status).toBe(404);
  await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
  await page.getByLabel('Lý do hủy (không bắt buộc)').fill('Browser QA hoàn tất');
  await page.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Chi tiết đăng ký' }).getByText('Đã hủy', { exact: true })).toBeVisible({ timeout: 30000 });
  const availability = await callApi(`/api/v1/resident/amenities/${fixture.amenity_id}/availability?date=${fixture.date}&apartment_id=${fixture.apartment_id}`, 'GET', fixture.token);
  expect(availability.status).toBe(200);
  expect(availability.data.slots[0]).toMatchObject({ available: true, remaining_bookings: 2, remaining_attendees: 4 });
});
