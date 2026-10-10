import { test, expect, type Route } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const fixturePath = process.env.RESIDENT_BROWSER_FIXTURE;
test.skip(!fixturePath || !process.env.RESIDENT_REAL_API_URL, 'Requires a dedicated resident QA server and database fixture.');

test('API thật: cư dân đăng ký, quản lý duyệt và thu tiền, cư dân hủy để đối soát hoàn', async ({ page, request, browser }) => {
  test.setTimeout(360000);
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
  const forwardApi = async (route: Route) => {
    const incoming = route.request();
    const result = await containerRequest(incoming.url(), incoming.method(), incoming.headers().authorization?.replace(/^Bearer /, '') || '', incoming.postData() || undefined);
    await route.fulfill({ status: result.status, contentType: 'application/json', body: result.body });
  };
  if (container) {
    await page.route('**/api/**', forwardApi);
  }
  await page.route('https://img.vietqr.io/**', (route) => route.abort());
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
  const managerContext = await browser.newContext();
  try {
    if (container) await managerContext.route('**/api/**', forwardApi);
    await managerContext.addInitScript(({ token }) => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý Browser QA' }));
      localStorage.setItem('smart_cassavas_token', token);
    }, { token: fixture.admin_token });
    const manager = await managerContext.newPage();
    const managerUrl = `${base}/quan-ly?tab=amenities&amenity_id=${fixture.amenity_id}&booking_code=${result.booking.booking_code}`;
    await manager.goto(managerUrl);
    const managerRow = manager.getByRole('row').filter({ hasText: result.booking.booking_code });
    await expect(managerRow).toBeVisible({ timeout: 60000 });
    const approved = manager.waitForResponse((response) => response.request().method() === 'PATCH' && response.url().endsWith(`/bookings/${result.booking.id}/status`));
    await managerRow.getByRole('button', { name: 'Duyệt', exact: true }).click();
    expect((await approved).status()).toBe(200);
    await expect(managerRow).toContainText('Chờ thanh toán', { timeout: 30000 });
    await page.bringToFront();
    await page.reload();
    await page.getByRole('button', { name: 'Lịch của tôi', exact: true }).click();
    await page.getByRole('button', { name: 'Thanh toán QR', exact: true }).click();
    const residentPayment = page.getByRole('dialog', { name: 'Thanh toán tiện ích', exact: true });
    await expect(residentPayment.getByRole('status').filter({ hasText: /^Chờ thanh toán$/ })).toBeVisible({ timeout: 30000 });
    await expect(residentPayment).toContainText('200.000');
    const reportResponse = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/resident/amenity-bookings/${result.booking.id}/payment/report`));
    await residentPayment.getByRole('button', { name: 'Đã chuyển khoản', exact: true }).click();
    expect((await reportResponse).status()).toBe(200);
    await expect(residentPayment.getByRole('status').filter({ hasText: /^Chờ đối soát$/ })).toBeVisible({ timeout: 30000 });
    const reported = await callApi(`/api/v1/resident/amenity-bookings/${result.booking.id}`, 'GET', fixture.token);
    expect(reported.data).toMatchObject({ is_paid: false, payment: { status: 'REPORTED', amount: 200000 } });
    await manager.bringToFront();
    await manager.reload();
    await managerRow.getByRole('button', { name: 'Đối soát', exact: true }).click();
    const reconciliation = manager.getByRole('dialog', { name: 'Đối soát thanh toán tiện ích' });
    const receipt = `QA-${result.booking.id}`.toUpperCase();
    await reconciliation.getByLabel('Mã giao dịch ngân hàng').fill(receipt);
    await reconciliation.getByLabel('Số tiền thực nhận').fill('200000');
    const receivedAt = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19);
    await reconciliation.getByLabel('Thời điểm nhận tiền').fill(receivedAt);
    await reconciliation.getByRole('checkbox').check();
    await reconciliation.getByRole('button', { name: 'Xác nhận nhận tiền', exact: true }).click();
    await expect(reconciliation.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toBeVisible({ timeout: 30000 });
    await page.bringToFront();
    await expect(residentPayment.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toBeVisible({ timeout: 30000 });
    const paid = await callApi(`/api/v1/resident/amenity-bookings/${result.booking.id}`, 'GET', fixture.token);
    expect(paid.data).toMatchObject({ is_paid: true, status: 'APPROVED', payment: { status: 'PAID', received_amount: 200000, bank_transaction_id: receipt } });
    await residentPayment.getByRole('button', { name: 'Đóng', exact: true }).click();
    await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
    await page.getByLabel('Lý do hủy (không bắt buộc)').fill('Browser QA hoàn tất');
    await page.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Chi tiết đăng ký' }).getByText('Đã hủy', { exact: true })).toBeVisible({ timeout: 30000 });
    const availability = await callApi(`/api/v1/resident/amenities/${fixture.amenity_id}/availability?date=${fixture.date}&apartment_id=${fixture.apartment_id}`, 'GET', fixture.token);
    expect(availability.status).toBe(200);
    expect(availability.data.slots[0]).toMatchObject({ available: true, remaining_bookings: 2, remaining_attendees: 4 });
    const cancelled = await callApi(`/api/v1/resident/amenity-bookings/${result.booking.id}`, 'GET', fixture.token);
    expect(cancelled.data).toMatchObject({ status: 'CANCELLED', payment: { status: 'REVIEW', received_amount: 200000, bank_transaction_id: receipt, refund_required: true } });
    await manager.reload();
    await expect(managerRow).toContainText('Đã hủy', { timeout: 60000 });
    await expect(managerRow).toContainText('Cần xử lý');
  } finally {
    await managerContext.close();
  }
});
