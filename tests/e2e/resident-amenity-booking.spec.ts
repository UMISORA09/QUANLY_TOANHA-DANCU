import { test, expect, Page } from '@playwright/test';
import type { AmenityBookingPayment } from '../../resources/js/Services/api';

const today = '2026-10-05';
const amenity = {
  id: 'amenity-test', category_id: 'category-test', block_id: 'block-test', amenity_name: 'Vườn BBQ',
  location_detail: 'Tầng thượng', max_capacity_per_slot: 4, hourly_rate: 100000, security_deposit_required: 50000,
  advance_booking_days_limit: 7, min_cancel_hours_before: 12, requires_admin_approval: true,
  rules_and_regulations: 'Giữ gìn vệ sinh chung.', is_active: true,
};
const availableSlot = { slot_id: 'slot-test', start_time: '10:00', end_time: '11:30', slot_label: 'Buổi sáng', available: true, reason: null, remaining_bookings: 2, remaining_attendees: 4, total_amount: 150000, deposit_amount: 50000 };
const booking = { id: 'booking-test', booking_code: 'BK-TEST', amenity_id: amenity.id, apartment_id: 'apartment-test', apartment_number: 'A-101', resident_user_id: 'user-test', amenity_name: amenity.amenity_name, location_detail: amenity.location_detail, booking_date: today, start_time: '10:00', end_time: '11:30', attendee_count: 2, total_amount: 150000, deposit_amount: 50000, is_paid: false, status: 'PENDING', can_cancel: true, cancel_deadline: '2026-10-04T22:00:00+07:00' };

const paymentFixture = (): AmenityBookingPayment => ({ id: 'payment-test', booking_id: booking.id, reference: 'TI-TEST', amount: 200000, bank_name: 'Vietcombank', account_number: '1234567890', account_name: 'TEST RECEIVER', status: 'PENDING', expires_at: new Date(Date.now() + 15 * 60000).toISOString(), qr_url: 'https://img.vietqr.io/image/test.png', can_pay: true, can_confirm: true, refund_required: false, bank_transaction_id: null, received_amount: null, received_at: null, confirmed_at: null, review_reason: null });

async function fixture(page: Page, options: { conflict?: boolean; unauthorized?: boolean; slowFirstDate?: boolean; networkError?: boolean; amenities?: typeof amenity[]; qrPayment?: boolean } = {}) {
  let payment = paymentFixture();
  let saved: (typeof booking & { payment?: AmenityBookingPayment }) | null = null;
  let conflict = options.conflict;
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'resident', resident_type: 'OWNER', email: 'resident@example.test', name: 'Cư dân kiểm thử' }));
    localStorage.setItem('smart_cassavas_token', 'test-token');
  });
  await page.route('**/api/**', (route) => route.fulfill({ json: { data: { blocks: [], listings: [] } } }));
  await page.route('**/api/v1/resident/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (options.unauthorized) return route.fulfill({ status: 401, json: { message: 'Unauthorized' } });
    if (url.pathname.endsWith('/payment/report')) {
      payment = { ...payment, status: 'REPORTED', can_pay: false, expires_at: new Date(Date.now() + 30 * 60000).toISOString() };
      if (saved) saved.payment = payment;
      return route.fulfill({ json: { payment } });
    }
    if (url.pathname.endsWith('/payment')) return route.fulfill({ json: { payment } });
    if (url.pathname.endsWith('/overview')) return route.fulfill({ json: {} });
    if (url.pathname.endsWith('/amenities')) {
      if (options.networkError) return route.abort('failed');
      return route.fulfill({ json: { apartments: [{ id: 'apartment-test', apartment_number: 'A-101', block_id: 'block-test', block_name: 'Tòa A' }], amenities: options.amenities || [amenity], categories: [{ id: 'category-test', category_name: 'BBQ' }], today, timezone: 'Asia/Ho_Chi_Minh' } });
    }
    if (url.pathname.endsWith('/availability')) {
      const date = url.searchParams.get('date');
      if (options.slowFirstDate && date === today) await new Promise((resolve) => setTimeout(resolve, 500));
      const full = conflict === false;
      return route.fulfill({ json: { slots: [{ ...availableSlot, start_time: date === '2026-10-06' ? '14:00' : '10:00', available: !full, remaining_bookings: full ? 0 : 2, reason: full ? 'Khung giờ đã hết chỗ.' : null }] } });
    }
    if (url.pathname.endsWith('/cancel')) {
      saved = { ...booking, status: 'CANCELLED', can_cancel: false };
      return route.fulfill({ json: { success: true, booking: saved } });
    }
    if (url.pathname.endsWith('/amenity-bookings') && request.method() === 'POST') {
      const payload = request.postDataJSON();
      expect(payload).toMatchObject({ amenity_id: amenity.id, slot_id: 'slot-test', apartment_id: 'apartment-test', attendee_count: 2, accepted_rules: true });
      expect(payload.user_id).toBeUndefined();
      if (conflict) {
        conflict = false;
        return route.fulfill({ status: 409, json: { message: 'Khung giờ vừa hết chỗ.' } });
      }
      saved = { ...booking, ...(options.qrPayment ? { status: 'APPROVED', payment } : {}) };
      return route.fulfill({ status: 201, json: { success: true, booking: saved } });
    }
    if (url.pathname.endsWith('/amenity-bookings')) return route.fulfill({ json: { items: saved ? [saved] : [], page: 1, total: saved ? 1 : 0, total_pages: 1 } });
    return route.fulfill({ json: saved || booking });
  });
  await page.goto('/cu-dan?tab=amenities');
  return { confirmPayment: () => {
    payment = { ...payment, status: 'PAID', can_pay: false, can_confirm: false, bank_transaction_id: 'VCB-TEST', received_amount: 200000 };
    if (saved) { saved.payment = payment; saved.is_paid = true; }
  } };
}

async function chooseSlot(page: Page) {
  await expect(page.getByRole('dialog', { name: /Đăng ký tiện ích/ })).not.toBeVisible();
  await page.getByRole('button', { name: /Vườn BBQ/ }).click();
  await expect(page.getByRole('dialog', { name: /Đăng ký tiện ích/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Đặt tiện ích', exact: true, includeHidden: true }).first()).toBeAttached();
  await page.getByRole('button', { name: /10:00.*11:30/ }).click();
  await page.getByLabel('Số người', { exact: true }).fill('2');
  await page.getByRole('checkbox').check();
}

test('quản lý từ chối đăng ký với lý do bắt buộc', async ({ page }) => {
  let current = { ...booking, resident_name: 'Cư dân QA', resident_phone: '', rejection_reason: '' };
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA', email: 'manager@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'test-admin-token');
  });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/bookings/booking-test/status')) {
      const payload = route.request().postDataJSON();
      if (payload.status === 'APPROVED') return route.fulfill({ status: 409, json: { message: 'Đăng ký vừa thay đổi. Vui lòng tải lại.' } });
      expect(payload).toMatchObject({ status: 'REJECTED', rejection_reason: 'Bảo trì đột xuất' });
      current = { ...current, status: 'REJECTED', rejection_reason: payload.rejection_reason };
      return route.fulfill({ json: current });
    }
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [current] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [{ ...amenity, amenity_code: 'BBQ-QA', active_bookings_count: 1 }], total: 1, page: 1, limit: 10, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Duyệt', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Đăng ký vừa thay đổi');
  await expect(page.getByRole('status').filter({ hasText: 'Đã duyệt đăng ký' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Từ chối', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Từ chối đăng ký tiện ích' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Xác nhận từ chối' })).toBeDisabled();
  await dialog.getByLabel('Lý do từ chối').fill('Bảo trì đột xuất');
  await dialog.getByRole('button', { name: 'Xác nhận từ chối' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(page.getByText('Bị từ chối', { exact: true }).last()).toBeVisible();
  await expect(page.getByRole('status').filter({ hasText: 'Đã từ chối đăng ký tiện ích.' })).toBeVisible();
});

test('quản lý tìm đơn chờ duyệt và duyệt để mở thanh toán QR', async ({ page }) => {
  let current: typeof booking & { resident_name: string; payment: AmenityBookingPayment } = { ...booking, resident_name: 'Cư dân QA', payment: { ...paymentFixture(), status: 'WAITING_APPROVAL', can_pay: false, can_confirm: false, expires_at: null, qr_url: null } };
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' }));
    localStorage.setItem('smart_cassavas_token', 'test-admin-token');
  });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/bookings/booking-test/status')) {
      expect(route.request().postDataJSON()).toMatchObject({ status: 'APPROVED' });
      current = { ...current, status: 'APPROVED', payment: paymentFixture() };
      return route.fulfill({ json: current });
    }
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [current, { ...booking, id: 'approved-booking', booking_code: 'BK-APPROVED', resident_name: 'Cư dân đã duyệt', status: 'APPROVED' }] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [{ ...amenity, amenity_code: 'BBQ-QA', active_bookings_count: 2 }], total: 1, page: 1, limit: 10, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Chờ duyệt (1)', exact: true }).click();
  await expect(page.getByRole('cell', { name: 'BK-APPROVED', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Duyệt', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Đã duyệt đăng ký tiện ích thành công.' })).toBeVisible();
  await expect(page.getByRole('dialog', { name: /Thông tin đặt chỗ/ }).getByRole('button', { name: 'Chờ duyệt (0)', exact: true })).toBeVisible();
  await page.getByLabel('Lọc trạng thái đăng ký').selectOption('APPROVED');
  await expect(page.getByRole('row').filter({ hasText: 'BK-TEST' })).toContainText('Chờ thanh toán');
});

test('quản lý nhận đăng ký mới ở tab khác và mở đúng đơn từ thông báo', async ({ page }) => {
  let arrived = false;
  let read = false;
  let polls = 0;
  const notice = { id: 'notice-test', title: 'Đăng ký tiện ích mới · Vườn BBQ', message: 'Cư dân QA · Căn hộ A-101 · Cần duyệt', category: 'AMENITY_BOOKING', timeAgo: 'Vừa xong', isRead: false, deepLink: '/quan-ly?tab=amenities&amenity_id=amenity-test&booking_code=BK-TEST' };
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' }));
    localStorage.setItem('smart_cassavas_token', 'test-admin-token');
  });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/notice-test/read')) { read = true; return route.fulfill({ json: { success: true } }); }
    if (url.pathname.endsWith('/amenity-booking-notifications')) {
      polls++;
      return route.fulfill({ json: { items: arrived ? [{ ...notice, isRead: read }] : [], unread_count: arrived && !read ? 1 : 0 } });
    }
    if (url.pathname.endsWith('/amenities/amenity-test')) return route.fulfill({ json: amenity });
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [{ ...booking, resident_name: 'Cư dân QA' }, { ...booking, id: 'other-booking', booking_code: 'BK-OTHER', resident_name: 'Người khác' }] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [], total: 0, page: 1, limit: 10, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly');
  await expect.poll(() => polls).toBeGreaterThan(0);
  arrived = true;
  const alert = page.getByRole('status').filter({ hasText: notice.title });
  await expect(alert).toBeVisible({ timeout: 12000 });
  await alert.getByRole('button', { name: 'Xem đăng ký', exact: true }).click();
  await expect(page).toHaveURL(/tab=amenities.*amenity_id=amenity-test.*booking_code=BK-TEST/);
  await expect(page.getByPlaceholder('Tìm mã booking, cư dân, căn hộ...')).toHaveValue('BK-TEST');
  await expect(page.getByRole('cell', { name: 'BK-TEST', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'BK-OTHER', exact: true })).toHaveCount(0);
  await expect.poll(() => read).toBeTruthy();
  await expect(alert).not.toBeVisible();
});

test('quản lý đối soát tiền thực nhận và xác nhận thủ công', async ({ page }) => {
  let current = { ...booking, resident_name: 'Cư dân QA', status: 'APPROVED', payment: { ...paymentFixture(), status: 'REPORTED' as AmenityBookingPayment['status'], can_pay: false } };
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' }));
    localStorage.setItem('smart_cassavas_token', 'test-admin-token');
  });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/payment/confirm')) {
      const payload = route.request().postDataJSON();
      if (payload.received_amount !== 200000) return route.fulfill({ status: 422, json: { message: 'Không thể xác nhận.', errors: { received_amount: ['Số tiền không khớp.'] } } });
      expect(payload.bank_transaction_id).toBe('VCB-QA-001');
      expect(Number.isNaN(Date.parse(payload.received_at))).toBeFalsy();
      current = { ...current, is_paid: true, payment: { ...current.payment, status: 'PAID', can_confirm: false, bank_transaction_id: payload.bank_transaction_id, received_amount: 200000 } };
      return route.fulfill({ json: { payment: current.payment } });
    }
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [current] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [{ ...amenity, amenity_code: 'BBQ-QA', active_bookings_count: 1 }], total: 1, page: 1, limit: 10, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await page.getByLabel('Lọc thanh toán tiện ích').selectOption('REPORTED');
  await page.getByRole('button', { name: 'Đối soát', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Đối soát thanh toán tiện ích' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Xác nhận nhận tiền' })).toBeDisabled();
  await dialog.getByLabel('Mã giao dịch ngân hàng').fill('VCB-QA-001');
  await dialog.getByLabel('Số tiền thực nhận').fill('1');
  await dialog.getByLabel('Thời điểm nhận tiền').fill('2026-10-07T10:00');
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Xác nhận nhận tiền' }).click();
  await expect(dialog.getByText('Số tiền không khớp.', { exact: true })).toBeVisible();
  await dialog.getByLabel('Số tiền thực nhận').fill('200000');
  await dialog.getByRole('button', { name: 'Xác nhận nhận tiền' }).click();
  await expect(dialog.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toBeVisible();
  await dialog.getByRole('button', { name: 'Đóng', exact: true }).click();
  await page.getByLabel('Lọc thanh toán tiện ích').selectOption('PAID');
  await expect(page.getByRole('cell', { name: 'Đã duyệt Đã thanh toán' })).toBeVisible();
});

test('hộp đối soát đang mở cập nhật khi tiền được xác nhận từ tab khác', async ({ page }) => {
  let paid = false;
  let loads = 0;
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' }));
    localStorage.setItem('smart_cassavas_token', 'test-admin-token');
  });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/bookings')) {
      loads++;
      return route.fulfill({ json: [{ ...booking, resident_name: 'Cư dân QA', status: 'APPROVED', is_paid: paid, payment: { ...paymentFixture(), status: paid ? 'PAID' : 'REPORTED', can_confirm: !paid, can_pay: false } }] });
    }
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [{ ...amenity, amenity_code: 'BBQ-QA', active_bookings_count: 1 }], total: 1, page: 1, limit: 10, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Đối soát', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Đối soát thanh toán tiện ích' });
  await expect(dialog.getByRole('status').filter({ hasText: /^Chờ đối soát$/ })).toBeVisible();
  const before = loads;
  paid = true;
  await page.evaluate(() => window.dispatchEvent(new StorageEvent('storage', { key: 'smart_amenity_sync_event', newValue: JSON.stringify({ type: 'AMENITY_BOOKING_CHANGED', amenityId: 'amenity-test', timestamp: Date.now() }) })));
  await expect.poll(() => loads).toBeGreaterThan(before);
  await expect(dialog.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toBeVisible({ timeout: 5000 });
  await expect(dialog.getByRole('button', { name: 'Xác nhận nhận tiền' })).toHaveCount(0);
});

test('khung giờ có tổng phí không hợp lệ bị khóa trước khi cư dân xác nhận', async ({ page }) => {
  await fixture(page);
  const reason = 'Phí và cọc thanh toán QR phải có tổng là số đồng nguyên. Vui lòng liên hệ ban quản lý để kiểm tra cấu hình.';
  await page.route('**/resident/amenities/*/availability?*', (route) => route.fulfill({ json: {
    date: today, amenity_id: amenity.id, slots: [{ ...availableSlot, available: false, reason, total_amount: 1.5, deposit_amount: 0 }],
  } }));
  await page.goto('/cu-dan?tab=amenities');
  await page.getByRole('button', { name: /Vườn BBQ/ }).click();
  const slot = page.getByRole('button', { name: /10:00.*11:30/ });
  await expect(slot).toBeDisabled();
  await expect(slot).toContainText(reason);
  await page.getByRole('checkbox').check();
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeDisabled();
});

for (const width of [1366, 390]) {
test(`cư dân nhận thông báo duyệt và mở đúng QR từ cổng cư dân trên ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  await fixture(page);
  let read = false;
  const notice = { id: 'resident-notice', title: 'Đã duyệt đăng ký · BK-TEST', message: 'Vui lòng thanh toán trong thời hạn.', category: 'AMENITY', isRead: false, timeAgo: 'Vừa xong', deepLink: '/cu-dan?tab=amenities&booking_id=booking-test' };
  await page.route('**/api/v1/resident/amenity-notifications**', (route) => {
    if (route.request().method() === 'POST') { read = true; return route.fulfill({ json: { success: true } }); }
    return route.fulfill({ json: { items: [{ ...notice, isRead: read }], latest_unread: read ? null : notice, unread_count: read ? 0 : 1, page: 1, total_pages: 1 } });
  });
  await page.route('**/api/v1/resident/amenity-bookings/booking-test', (route) => route.fulfill({ json: { ...booking, status: 'APPROVED', payment: paymentFixture() } }));
  await page.route('https://img.vietqr.io/**', (route) => route.abort());
  await page.goto('/cu-dan');
  const alert = page.getByRole('status').filter({ hasText: notice.title });
  await expect(alert).toBeVisible();
  await alert.getByRole('button', { name: 'Xem đăng ký', exact: true }).click();
  await expect(page).toHaveURL(/booking_id=booking-test/);
  await expect(page.getByRole('dialog', { name: 'Thanh toán tiện ích', exact: true })).toBeVisible();
  await expect.poll(() => read).toBeTruthy();
});
}

test('quản lý xem được thông báo cũ và lọc chưa đọc', async ({ page }) => {
  const old = { id: 'old-notice', title: 'Đăng ký cũ chưa đọc', message: 'Cần duyệt', category: 'AMENITY_BOOKING', isRead: false, deepLink: '/quan-ly?tab=amenities', timeAgo: '1 ngày trước' };
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); });
  await page.route('**/api/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/amenity-booking-notifications')) {
      const filtered = url.searchParams.get('unread') === '1';
      const second = url.searchParams.get('page') === '2';
      return route.fulfill({ json: { items: filtered || second ? [old] : Array.from({ length: 20 }, (_, i) => ({ ...old, id: 'new-' + i, title: 'Thông báo đã đọc ' + i, isRead: true })), latest_unread: old, unread_count: 1, page: second ? 2 : 1, total_pages: filtered ? 1 : 2 } });
    }
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly');
  await page.getByTitle('Thông báo hệ thống', { exact: true }).click();
  await page.getByRole('button', { name: 'Thông báo sau', exact: true }).click();
  await expect(page.getByText(old.title, { exact: true }).last()).toBeVisible();
  await page.getByLabel('Chỉ thông báo tiện ích chưa đọc').check();
  await expect(page.getByRole('button', { name: 'Thông báo sau', exact: true })).toBeDisabled();
});

for (const width of [1366, 390]) {
test(`quản lý xác nhận hủy đơn đã trả tiền và thống kê phân biệt phí cọc hoàn tiền trên ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  let cancelled = false;
  const paid = { ...booking, booking_code: 'BK-PAID', resident_name: 'Cư dân QA', status: 'APPROVED', is_paid: true, payment: { ...paymentFixture(), status: 'PAID', can_pay: false, can_confirm: false, received_amount: 200000 } };
  const refunded = { ...paid, id: 'refund-booking', booking_code: 'BK-REFUND', status: 'CANCELLED', payment: { ...paid.payment, status: 'REVIEW', refund_required: true } };
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); });
  await page.route('**/api/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/bookings/booking-test/cancel')) { expect(route.request().postDataJSON()).toEqual({ reason: 'Bảo trì đột xuất' }); cancelled = true; return route.fulfill({ json: { success: true } }); }
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [{ ...paid, ...(cancelled ? { status: 'CANCELLED', payment: { ...paid.payment, status: 'REVIEW', refund_required: true } } : {}) }, refunded, { ...booking, id: 'unpaid', booking_code: 'BK-UNPAID', resident_name: 'Cư dân khác' }] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [{ ...amenity, active_bookings_count: 2 }], total: 1, page: 1, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await expect(page.getByText('Đã nhận phí và cọc', { exact: true }).locator('..')).toContainText('400.000');
  await expect(page.getByText('Phí đã thanh toán, còn hiệu lực', { exact: true }).locator('..')).toContainText('150.000');
  await expect(page.getByText('Tiền cọc đang giữ', { exact: true }).locator('..')).toContainText('50.000');
  await expect(page.getByText('Cần xử lý hoàn tiền', { exact: true }).locator('..')).toContainText('200.000');
  await page.getByRole('row').filter({ hasText: 'BK-PAID' }).getByRole('button', { name: 'Hủy', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Hủy đăng ký tiện ích', exact: true });
  await expect(dialog).toContainText('hoàn tiền');
  expect(cancelled).toBeFalsy();
  await expect(dialog.getByRole('button', { name: 'Xác nhận hủy' })).toBeDisabled();
  await dialog.getByLabel('Lý do hủy của quản lý').fill('Bảo trì đột xuất');
  await dialog.getByRole('button', { name: 'Xác nhận hủy' }).click();
  await expect(dialog).not.toBeVisible();
  await expect.poll(() => cancelled).toBeTruthy();
});
}

test('công việc chung mở đúng đơn ở tiện ích ngoài trang danh mục', async ({ page }) => {
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); Object.defineProperty(document, 'hidden', { configurable: true, value: true }); });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/amenity-booking-worklist')) return route.fulfill({ json: { items: [{ ...booking, resident_name: 'Cư dân QA', amenity_name: amenity.amenity_name, review_overdue: true }], counts: { PENDING: 0, REPORTED: 1, REVIEW: 0 }, page: 1, total_pages: 1 } });
    if (url.pathname.endsWith('/amenities/amenity-test')) { await new Promise((resolve) => setTimeout(resolve, 500)); return route.fulfill({ json: amenity }); }
    if (url.pathname.endsWith('/bookings')) return route.fulfill({ json: [{ ...booking, resident_name: 'Cư dân QA' }] });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [], total: 0, page: 1, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  const worklist = page.getByRole('region', { name: 'Công việc đăng ký tiện ích' });
  await expect(worklist.getByRole('status')).toHaveText('Đang tải công việc…');
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: false }); document.dispatchEvent(new Event('visibilitychange')); });
  await expect(worklist.getByRole('button', { name: 'Mở đăng ký', exact: true })).toBeVisible({ timeout: 5000 });
  await worklist.getByRole('button', { name: 'Chờ duyệt (0)', exact: true }).click();
  await expect(worklist.getByRole('button', { name: 'Mở đăng ký', exact: true })).toBeVisible();
  await worklist.getByRole('button', { name: 'Chờ đối soát (1)', exact: true }).click();
  await expect(worklist).toContainText('Đối soát quá hạn');
  const openButton = worklist.getByRole('button', { name: 'Mở đăng ký', exact: true });
  await openButton.hover();
  await expect(openButton).toHaveCSS('cursor', 'pointer');
  await openButton.focus();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Shift+Tab');
  await expect(openButton).toHaveCSS('outline-style', 'solid');
  await worklist.getByRole('button', { name: 'Mở đăng ký', exact: true }).click();
  await expect(worklist.getByRole('status')).toHaveText('Đang mở đăng ký…');
  await expect(page.getByPlaceholder('Tìm mã booking, cư dân, căn hộ...')).toHaveValue('BK-TEST');
  await expect(page.getByRole('cell', { name: 'BK-TEST', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Từ chối', exact: true })).toHaveCSS('cursor', 'pointer');
});

test('bảng đăng ký cuộn được và mở lại dùng cache rồi cập nhật dữ liệu mới', async ({ page }) => {
  let reads = 0;
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); });
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/bookings')) {
      const read = ++reads;
      if (read > 1) await new Promise((resolve) => setTimeout(resolve, 2000));
      return route.fulfill({ json: [{ ...booking, resident_name: read > 1 ? 'Cư dân mới' : 'Cư dân QA' }] });
    }
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [amenity], total: 1, page: 1, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await expect(page.getByRole('cell').filter({ hasText: 'Cư dân QA' })).toBeVisible();
  const table = page.getByRole('region', { name: 'Bảng đăng ký tiện ích' });
  expect(await table.evaluate((element) => element.scrollWidth > element.clientWidth)).toBeTruthy();
  await table.evaluate((element) => { element.scrollLeft = element.scrollWidth; });
  expect(await table.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
  const picker = page.getByLabel('Lọc thanh toán tiện ích');
  expect(await picker.evaluate((element) => getComputedStyle(element, '::picker(select)').borderRadius)).toBe('16px');
  await picker.selectOption('ALL');
  await page.getByRole('button', { name: 'Đóng danh sách đăng ký' }).click();
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click();
  await expect(page.getByRole('cell').filter({ hasText: 'Cư dân QA' })).toBeVisible({ timeout: 1000 });
  await expect(page.getByText('Đang cập nhật dữ liệu từ máy chủ…')).toBeVisible();
  await expect(page.getByRole('cell').filter({ hasText: 'Cư dân mới' })).toBeVisible();
  await expect(page.getByRole('cell').filter({ hasText: 'Cư dân QA' })).toHaveCount(0);
});

for (const width of [1366, 390]) {
test(`tạm ngưng phân biệt giữ đơn và đóng cửa, bắt xem lại khi xung đột trên ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  let attempts = 0;
  let paused = false;
  let previews = 0;
  const impact = { count: 1, received_amount: 200000, reported_count: 0, items: [{ booking_code: 'BK-TEST', booking_date: today, start_time: '10:00', end_time: '11:30' }], confirmation_token: 'a'.repeat(64) };
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); });
  await page.route('**/api/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/closure-impact')) { previews++; return route.fulfill({ json: impact }); }
    if (url.pathname.endsWith('/status')) {
      const payload = route.request().postDataJSON();
      if (payload.booking_action === 'keep') { paused = true; return route.fulfill({ json: { ...amenity, is_active: false } }); }
      expect(payload.reason).toBe('Sửa hệ thống điện'); expect(payload.confirmation_token).toBe(impact.confirmation_token);
      if (++attempts === 1) return route.fulfill({ status: 409, json: { message: 'Danh sách đăng ký đã thay đổi.' } });
      return route.fulfill({ json: { ...amenity, is_active: false } });
    }
    if (url.pathname.endsWith('/amenities/amenity-test')) return route.fulfill({ json: amenity });
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [amenity], total: 1, page: 1, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByTitle('Đang hoạt động • Bấm để tạm ngưng').click();
  let dialog = page.getByRole('dialog', { name: /Tạm ngưng tiện ích/ });
  await expect(dialog).toContainText('Các đơn cũ tiếp tục có hiệu lực');
  await dialog.getByRole('button', { name: 'Xác nhận ngừng nhận đơn mới' }).click();
  await expect.poll(() => paused).toBeTruthy();
  expect(previews).toBe(0);
  await page.getByTitle('Đang hoạt động • Bấm để tạm ngưng').click();
  dialog = page.getByRole('dialog', { name: /Tạm ngưng tiện ích/ });
  await dialog.getByRole('radio', { name: 'Đóng cửa, hủy các đơn chưa kết thúc' }).check();
  await dialog.getByLabel('Lý do đóng cửa', { exact: true }).fill('Sửa hệ thống điện');
  await expect(dialog.getByRole('button', { name: 'Xác nhận đóng cửa và hủy đơn' })).toBeDisabled();
  await dialog.getByRole('button', { name: 'Xem lại các đơn bị ảnh hưởng' }).click();
  await expect(dialog).toContainText('200.000');
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Xác nhận đóng cửa và hủy đơn' }).click();
  await expect(dialog.getByRole('alert')).toHaveText('Danh sách đăng ký đã thay đổi.');
  await expect(dialog.getByRole('button', { name: 'Xác nhận đóng cửa và hủy đơn' })).toBeDisabled();
  await expect(dialog.getByLabel('Lý do đóng cửa', { exact: true })).toHaveValue('Sửa hệ thống điện');
  await dialog.getByRole('button', { name: 'Xem lại các đơn bị ảnh hưởng' }).click();
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Xác nhận đóng cửa và hủy đơn' }).click();
  await expect(dialog).not.toBeVisible();
  expect(attempts).toBe(2);
});

test(`bảo trì xem trước, đổi giờ bỏ xác nhận cũ và gửi đúng phạm vi trên ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  let saved = false;
  let previews = 0;
  await page.addInitScript(() => { localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'Quản lý QA' })); localStorage.setItem('smart_cassavas_token', 'test-admin-token'); });
  await page.route('**/api/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/closure-impact')) { previews++; expect(url.searchParams.get('blackout_date')).toBe('2026-10-12'); return route.fulfill({ json: { count: 1, received_amount: 0, reported_count: 1, items: [], confirmation_token: 'b'.repeat(64) } }); }
    if (url.pathname.endsWith('/blackouts')) {
      if (route.request().method() === 'POST') {
        const payload = route.request().postDataJSON();
        expect(payload).toMatchObject({ blackout_date: '2026-10-12', start_time: '11:00', end_time: '12:00', reason: 'Thay thiết bị', confirmation_token: 'b'.repeat(64) });
        saved = true; return route.fulfill({ json: { id: 'blackout-test', ...payload } });
      }
      return route.fulfill({ json: [] });
    }
    if (url.pathname.endsWith('/amenities')) return route.fulfill({ json: { items: [amenity], total: 1, page: 1, total_pages: 1 } });
    return route.fulfill({ json: [] });
  });
  await page.goto('/quan-ly?tab=amenities');
  await page.getByTitle('Ngày đóng cửa bảo trì').click();
  const dialog = page.getByRole('dialog', { name: 'Ngày Đóng cửa & Bảo trì', exact: true });
  await dialog.getByLabel('Ngày đóng cửa', { exact: true }).fill('2026-10-12');
  await dialog.getByRole('radio', { name: 'Khoảng giờ' }).check();
  await dialog.getByLabel('Giờ bắt đầu đóng cửa').fill('10:00');
  await dialog.getByLabel('Giờ kết thúc đóng cửa').fill('12:00');
  await dialog.getByLabel('Lý do đóng cửa / bảo trì').fill('Thay thiết bị');
  await dialog.getByRole('button', { name: 'Xem trước các đơn bị ảnh hưởng' }).click();
  await expect(dialog).toContainText('1 đăng ký bị ảnh hưởng');
  expect(saved).toBeFalsy();
  await dialog.getByRole('checkbox').check();
  await dialog.getByLabel('Giờ bắt đầu đóng cửa').fill('11:00');
  await expect(dialog.getByRole('checkbox')).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Xem trước các đơn bị ảnh hưởng' }).click();
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Xác nhận đóng cửa và xử lý đăng ký' }).click();
  await expect.poll(() => saved).toBeTruthy();
  expect(previews).toBe(2);
});
}

test('thanh toán lỗi mạng có thử lại và hết hạn không còn nút báo chuyển tiền', async ({ page }) => {
  await fixture(page, { qrPayment: true });
  let failed = true;
  let expired = false;
  await page.route('**/api/v1/resident/amenity-bookings/booking-test/payment', (route) => {
    if (failed) return route.abort();
    return route.fulfill({ json: { payment: { ...paymentFixture(), ...(expired ? { status: 'EXPIRED', can_pay: false, qr_url: null } : {}) } } });
  });
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Thanh toán tiện ích', exact: true });
  await expect(dialog.getByRole('alert')).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true })).toBeDisabled();
  failed = false;
  await dialog.getByRole('button', { name: 'Tải lại trạng thái' }).click();
  await expect(dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true })).toBeEnabled();
  await dialog.getByRole('button', { name: 'Đóng', exact: true }).click();
  expired = true;
  await page.getByRole('button', { name: 'Thông tin thanh toán' }).click();
  await expect(dialog.getByText('Hết hạn thanh toán', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true })).toHaveCount(0);
  await expect(dialog.getByText(/Không chuyển tiền thêm cho yêu cầu này/)).toBeVisible();
  await expect(dialog.getByText(/Số tiền của đăng ký/)).toBeVisible();
  await expect(dialog.getByRole('button', { name: /Sao chép/ })).toHaveCount(0);
});

test('cư dân tự nhận trạng thái đã thanh toán mà không cần bấm báo chuyển khoản', async ({ page }) => {
  await page.clock.install();
  const backend = await fixture(page, { qrPayment: true });
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Thanh toán tiện ích', exact: true });
  await expect(dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true })).toBeEnabled();
  backend.confirmPayment();
  await page.clock.fastForward(10000);
  await expect(dialog.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true })).toHaveCount(0);
});

test('checkout SePay gửi form POST có chữ ký tới đúng sandbox', async ({ page }) => {
  await fixture(page, { qrPayment: true });
  await page.route('**/resident/amenity-bookings/booking-test/payment', (route) => route.fulfill({ json: { payment: { ...paymentFixture(), checkout_available: true, checkout_environment: 'sandbox' } } }));
  await page.route('**/resident/amenity-bookings/booking-test/payment/checkout', (route) => {
    expect(route.request().method()).toBe('POST');
    return route.fulfill({ json: { environment: 'sandbox', action: 'https://pay-sandbox.sepay.vn/v1/checkout/init', fields: { merchant: 'SP-TEST-QA', currency: 'VND', order_amount: '200000', operation: 'PURCHASE', payment_method: 'BANK_TRANSFER', order_invoice_number: 'SBX-TI-TEST', signature: 'signed-by-server' } } });
  });
  await page.route('https://pay-sandbox.sepay.vn/v1/checkout/init', (route) => {
    expect(route.request().method()).toBe('POST');
    const fields = new URLSearchParams(route.request().postData()!);
    expect(fields.get('order_amount')).toBe('200000');
    expect(fields.get('signature')).toBe('signed-by-server');
    expect(fields.has('secret_key')).toBeFalsy();
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<meta charset="utf-8"><h1>Checkout sandbox kiểm thử</h1>' });
  });
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Thử thanh toán SePay (Sandbox)', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Checkout sandbox kiểm thử' })).toBeVisible();
});

test('checkout SePay bị chặn khi đơn vừa hết hạn vẫn giữ hộp thoại và báo lỗi', async ({ page }) => {
  await fixture(page, { qrPayment: true });
  await page.route('**/resident/amenity-bookings/booking-test/payment', (route) => route.fulfill({ json: { payment: { ...paymentFixture(), checkout_available: true, checkout_environment: 'sandbox' } } }));
  await page.route('**/resident/amenity-bookings/booking-test/payment/checkout', (route) => route.fulfill({ status: 409, json: { message: 'Đăng ký đã hết hạn thanh toán.' } }));
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Thử thanh toán SePay (Sandbox)', exact: true }).click();
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('Đăng ký đã hết hạn thanh toán.');
});

for (const viewport of [{ width: 1366, height: 900 }, { width: 390, height: 844 }]) {
  test(`QR và báo chuyển khoản không tự đánh dấu đã thanh toán trên ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.clock.install();
    await page.route('https://img.vietqr.io/**', (route) => route.abort());
    const backend = await fixture(page, { qrPayment: true });
    await chooseSlot(page);
    await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Thanh toán tiện ích', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('200.000');
    await expect(dialog).toContainText('TI-TEST');
    await expect(dialog.getByText(/Không tải được QR/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Đã chuyển khoản', exact: true }).click();
    await expect(dialog.getByRole('status').filter({ hasText: 'Chờ đối soát' })).toBeVisible();
    await expect(dialog.getByRole('status').filter({ hasText: /^Đã thanh toán$/ })).toHaveCount(0);
    backend.confirmPayment();
    await page.clock.fastForward(10000);
    await expect(dialog.getByRole('status').filter({ hasText: 'Đã thanh toán' })).toBeVisible();
    await dialog.getByRole('button', { name: 'Đóng', exact: true }).click();
    await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
    await expect(page.getByText('Đã thanh toán', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
  });

  test(`phân trang 15 tiện ích ở lưới và bảng trên ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await fixture(page, { amenities: Array.from({ length: 31 }, (_, index) => ({ ...amenity, id: `amenity-${index}`, amenity_name: `Tiện ích ${index + 1}` })) });
    const pagination = page.getByRole('navigation', { name: 'Phân trang tiện ích' });
    const cards = page.getByRole('button', { name: /^Tiện ích \d/ });
    await expect(cards).toHaveCount(15);
    await expect(pagination.getByRole('button', { name: 'Trước' })).toBeDisabled();
    await pagination.getByRole('button', { name: 'Sau' }).click();
    await expect(cards).toHaveCount(15);
    await expect(cards.first()).toContainText('Tiện ích 16');
    await pagination.getByRole('button', { name: 'Sau' }).click();
    await expect(cards).toHaveCount(1);
    await expect(cards.first()).toContainText('Tiện ích 31');
    await expect(pagination.getByRole('button', { name: 'Sau' })).toBeDisabled();
    await page.getByRole('button', { name: 'Chuyển sang dạng bảng' }).click();
    await expect(page.getByRole('rowheader')).toHaveCount(1);
    await expect(pagination).toContainText('Trang 3/3');
    await pagination.getByRole('button', { name: 'Trước' }).click();
    await expect(page.getByRole('rowheader')).toHaveCount(15);
    await expect(page.getByRole('rowheader').first()).toHaveText('Tiện ích 16');
    await page.getByLabel('Danh mục').selectOption('category-test');
    await expect(pagination).toContainText('Trang 1/3');
    await pagination.getByRole('button', { name: 'Sau' }).click();
    await page.getByPlaceholder('Tên tiện ích…').fill('Tiện ích 31');
    await expect(pagination).toContainText('Trang 1/1');
    await expect(page.getByRole('rowheader')).toHaveText('Tiện ích 31');
    await expect(pagination.getByRole('button')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
  });

  test(`chuyển lưới và bảng, giữ bộ lọc và đăng ký trên ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await fixture(page);
    const search = page.getByPlaceholder('Tên tiện ích…');
    await search.fill('BBQ');
    await page.getByLabel('Danh mục').selectOption('category-test');
    const toggle = page.getByRole('button', { name: 'Chuyển sang dạng bảng' });
    await toggle.focus();
    await page.keyboard.press('Enter');
    const table = page.getByRole('table', { name: 'Danh sách tiện ích' });
    await expect(table).toBeVisible();
    await expect(table.getByRole('rowheader')).toHaveText('Vườn BBQ');
    await expect(search).toHaveValue('BBQ');
    await expect(page.getByLabel('Danh mục')).toHaveValue('category-test');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await search.fill('Không tồn tại');
    await expect(page.getByText('Không có tiện ích phù hợp.')).toBeVisible();
    await page.getByRole('button', { name: 'Chuyển sang dạng lưới' }).click();
    await expect(search).toHaveValue('Không tồn tại');
    await expect(page.getByText('Không có tiện ích phù hợp.')).toBeVisible();
    await search.fill('BBQ');
    await expect(page.getByRole('button', { name: /Vườn BBQ/ })).toBeVisible();
    await page.getByRole('button', { name: 'Chuyển sang dạng bảng' }).click();
    await page.getByRole('button', { name: 'Đăng ký Vườn BBQ', exact: true }).click();
    await expect(page.getByRole('dialog', { name: /Đăng ký tiện ích/ })).toBeVisible();
    await page.getByRole('button', { name: /10:00.*11:30/ }).click();
    await page.getByLabel('Số người', { exact: true }).fill('2');
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Đăng ký thành công' })).toBeVisible();
  });

  test(`đăng ký, xem và hủy trên ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await fixture(page);
    await chooseSlot(page);
    await expect(page.getByText('150.000', { exact: false }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Đăng ký thành công' })).toBeVisible();
    await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
    await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
    await expect(page.getByRole('alertdialog', { name: 'Hủy đăng ký tiện ích' })).toBeVisible();
    await page.getByLabel('Lý do hủy (không bắt buộc)').fill('Đổi kế hoạch');
    await page.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('Đã hủy', { exact: true })).toBeVisible();
    await expect(page.getByRole('dialog').getByRole('status')).toContainText('Đã hủy đăng ký BK-TEST thành công.');
    await page.getByRole('button', { name: 'Đóng', exact: true }).click();
    await page.getByRole('button', { name: 'Đăng ký tiện ích', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toBeVisible();
  });
}

test('đăng ký bằng bàn phím và đóng form, chi tiết bằng Escape', async ({ page }) => {
  await fixture(page);
  const amenity = page.getByRole('button', { name: /Vườn BBQ/ });
  await amenity.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog', { name: /Đăng ký tiện ích/ })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await expect(amenity).toBeFocused();
  await page.keyboard.press('Enter');
  const slot = page.getByRole('button', { name: /10:00.*11:30/ });
  await slot.focus();
  await page.keyboard.press('Enter');
  await page.getByLabel('Số người', { exact: true }).focus();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('2');
  await page.getByRole('checkbox').focus();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status').filter({ hasText: 'Đăng ký thành công' })).toBeVisible();
  await page.getByRole('button', { name: 'Xem lịch của tôi' }).focus();
  await page.keyboard.press('Enter');
  const detail = page.getByRole('button', { name: 'Xem chi tiết', exact: true });
  await detail.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await expect(detail).toBeFocused();
});

test('tab cư dân đồng bộ URL và Back/Forward', async ({ page }) => {
  await fixture(page);
  await page.getByRole('button', { name: 'Tổng quan Cư dân', exact: true }).first().click();
  await expect(page).toHaveURL(/tab=overview/);
  await page.goBack();
  await expect(page).toHaveURL(/tab=amenities/);
  await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toBeVisible();
  await page.goForward();
  await expect(page).toHaveURL(/tab=overview/);
  await expect(page.getByRole('heading', { name: 'Tổng quan Cư dân' })).toBeVisible();
});

test('hết chỗ khi gửi giữ số người và ghi chú, tải lại giờ', async ({ page }) => {
  await fixture(page, { conflict: true });
  await chooseSlot(page);
  await page.getByLabel('Ghi chú', { exact: true }).fill('Gia đình tôi');
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Khung giờ vừa hết chỗ');
  await expect(page.getByLabel('Số người', { exact: true })).toHaveValue('2');
  await expect(page.getByLabel('Ghi chú', { exact: true })).toHaveValue('Gia đình tôi');
  await expect(page.getByRole('button', { name: /10:00.*11:30/ })).toBeDisabled();
});

test('hết phiên có đường đăng nhập lại và lỗi mạng có nút thử lại', async ({ page }) => {
  await fixture(page, { unauthorized: true });
  await expect(page.getByRole('alert')).toContainText('Phiên đăng nhập đã hết hạn');
  await expect(page.getByRole('link', { name: 'Đăng nhập lại' })).toBeVisible();
  await page.unroute('**/api/v1/resident/**');
  await fixture(page, { networkError: true });
  await expect(page.getByRole('button', { name: 'Thử lại' })).toBeVisible();
});

test('đổi ngày nhanh không dùng phản hồi ngày cũ, điều hướng Back/Forward giữ tab', async ({ page }) => {
  await fixture(page, { slowFirstDate: true });
  await page.getByRole('button', { name: /Vườn BBQ/ }).click();
  await page.getByRole('button', { name: 'Ngày mai', exact: true }).click();
  await expect(page.getByRole('button', { name: /14:00.*11:30/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /10:00.*11:30/ })).toHaveCount(0);
  await page.getByRole('button', { name: 'Đóng form đăng ký' }).click();
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await page.getByRole('button', { name: 'Tổng quan Cư dân', exact: true }).first().click();
  await page.goBack();
  await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toBeVisible();
  await page.goForward();
  await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toHaveCount(0);
});

test('chỉ tải dữ liệu cần dùng và gộp tải lại khi quay về cửa sổ', async ({ page }) => {
  let catalogRequests = 0;
  let rentalRequests = 0;
  let historyRequests = 0;
  let current = { ...booking };
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname.endsWith('/resident/amenities')) catalogRequests += 1;
    if (url.pathname.endsWith('/auth/rental-listings')) rentalRequests += 1;
  });
  await fixture(page);
  await expect(page.getByRole('button', { name: /Vườn BBQ/ })).toBeVisible();
  expect(rentalRequests).toBe(0);
  await page.route('**/resident/amenity-bookings?**', (route) => {
    historyRequests += 1;
    return route.fulfill({ json: { items: [current], page: 1, total: 1, total_pages: 1 } });
  });
  await page.getByRole('button', { name: 'Lịch của tôi', exact: true }).click();
  await expect(page.getByRole('article').getByText('Chờ duyệt', { exact: true })).toBeVisible();
  const initialCatalogRequests = catalogRequests;
  const initialHistoryRequests = historyRequests;
  current = { ...booking, status: 'APPROVED' };
  await page.evaluate(() => {
    document.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(new Event('focus'));
  });
  await expect(page.getByRole('article').getByText('Đã duyệt', { exact: true })).toBeVisible();
  expect(catalogRequests).toBe(initialCatalogRequests + 1);
  expect(historyRequests).toBe(initialHistoryRequests + 1);
  current = { ...booking, status: 'CANCELLED', can_cancel: false };
  await page.getByRole('button', { name: 'Tải lại lịch', exact: true }).click();
  await expect(page.getByRole('article').getByText('Đã hủy', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Hủy đăng ký', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: /Tòa nhà & Căn hộ cho thuê/ }).first().click();
  await expect.poll(() => rentalRequests).toBe(1);
});

test('tải lại nền giữ lịch và xác nhận hủy khác chi tiết', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
  await expect(page.getByText('BK-TEST', { exact: true })).toBeVisible();
  let release: () => void = () => {};
  const pending = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/api/v1/resident/amenity-bookings?*', async (route) => {
    await pending;
    await route.fulfill({ json: { items: [booking], total: 1, total_pages: 1, page: 1 } });
  });
  await page.getByRole('button', { name: 'Tải lại lịch', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Đang cập nhật lịch' })).toBeVisible();
  await expect(page.getByText('BK-TEST', { exact: true })).toBeVisible();
  release();
  await expect(page.getByRole('button', { name: 'Tải lại lịch', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Xem chi tiết', exact: true }).click();
  const detail = page.getByRole('dialog', { name: 'Chi tiết đăng ký' });
  await expect(detail.getByText('Phí sử dụng · Tiền cọc')).toBeVisible();
  await page.getByRole('button', { name: 'Đóng', exact: true }).click();
  await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
  const cancel = page.getByRole('alertdialog', { name: 'Hủy đăng ký tiện ích' });
  await expect(cancel).toContainText('Chỗ đã đặt sẽ được giải phóng');
  await expect(cancel.getByText('Phí sử dụng · Tiền cọc')).toHaveCount(0);
  await test.info().attach('xac-nhan-huy', { body: await page.screenshot(), contentType: 'image/png' });
  await cancel.getByRole('button', { name: 'Giữ đăng ký' }).click();
  await expect(cancel).not.toBeVisible();
  await expect(page.getByRole('article').getByText('Chờ duyệt', { exact: true })).toBeVisible();
});

test('form trên điện thoại giữ nút đóng khi cuộn và ngày dễ đọc', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page);
  await page.getByRole('button', { name: /Vườn BBQ/ }).click();
  const dialog = page.getByRole('dialog', { name: /Đăng ký tiện ích/ });
  await expect(dialog.getByText('Ngày đã chọn: 05/10/2026')).toBeVisible();
  await dialog.evaluate((element) => { element.scrollTop = element.scrollHeight; });
  const close = dialog.getByRole('button', { name: 'Đóng form đăng ký' });
  await expect(close).toBeInViewport();
  await test.info().attach('form-dien-thoai', { body: await page.screenshot(), contentType: 'image/png' });
  await close.click();
  await expect(dialog).not.toBeVisible();
});
test('tải lại chỗ trống giữ khung giờ và khóa lựa chọn khi chưa có kết quả', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  let release: () => void = () => {};
  const pending = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/availability?*', async (route) => {
    await pending;
    await route.fulfill({ json: { slots: [availableSlot] } });
  });
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.getByRole('status').filter({ hasText: 'Đang cập nhật chỗ trống' })).toBeVisible();
  const slot = page.getByRole('button', { name: /10:00.*11:30/ });
  await expect(slot).toBeVisible();
  await expect(slot).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeDisabled();
  release();
  await expect(slot).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeEnabled();
});
test('catalog lỗi tải nền giữ form, ghi chú và cho thử lại', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  await page.getByLabel('Ghi chú', { exact: true }).fill('Giữ ghi chú');
  let fail = true;
  await page.route('**/resident/amenities', (route) => fail ? route.fulfill({ status: 503, json: { message: 'Catalog tạm thời không khả dụng' } }) : route.fulfill({ json: { apartments: [{ id: 'apartment-test', apartment_number: 'A-101', block_id: 'block-test' }], amenities: [amenity], categories: [], today, timezone: 'Asia/Ho_Chi_Minh' } }));
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  const dialog = page.getByRole('dialog', { name: /Đăng ký tiện ích/ });
  await expect(dialog.getByRole('alert')).toContainText('Catalog tạm thời');
  await expect(page.getByLabel('Ghi chú', { exact: true })).toHaveValue('Giữ ghi chú');
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeDisabled();
  fail = false;
  await dialog.getByRole('button', { name: 'Thử lại' }).click();
  await expect(dialog.getByRole('alert')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeEnabled();
});

test('giờ slot thay đổi cùng mức phí yêu cầu chọn và xác nhận lại', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  await page.getByLabel('Ghi chú').fill('Giữ thông tin đã nhập');
  let posts = 0;
  page.on('request', (request) => { if (request.method() === 'POST' && request.url().endsWith('/amenity-bookings')) posts += 1; });
  await page.route('**/availability?**', (route) => route.fulfill({ json: { slots: [{ ...availableSlot, start_time: '14:00', end_time: '15:30' }] } }));
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Giờ sử dụng vừa thay đổi' })).toBeVisible();
  expect(posts).toBe(0);
  await expect(page.getByRole('checkbox')).not.toBeChecked();
  await expect(page.getByLabel('Số người', { exact: true })).toHaveValue('2');
  await expect(page.getByLabel('Ghi chú')).toHaveValue('Giữ thông tin đã nhập');
  await page.getByRole('button', { name: /14:00.*15:30/ }).click();
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Đăng ký thành công' })).toBeVisible();
  expect(posts).toBe(1);
});

test('nội quy thay đổi trước xác nhận yêu cầu đọc lại, không gửi POST', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  let posts = 0;
  page.on('request', (request) => { if (request.method() === 'POST') posts += 1; });
  await page.route('**/resident/amenities', (route) => route.fulfill({ json: { apartments: [{ id: 'apartment-test', block_id: 'block-test' }], amenities: [{ ...amenity, rules_and_regulations: 'Nội quy vừa cập nhật.' }], categories: [], today, timezone: 'Asia/Ho_Chi_Minh' } }));
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'nội quy vừa thay đổi' })).toBeVisible();
  await expect(page.getByRole('checkbox')).not.toBeChecked();
  await expect(page.getByText('Nội quy vừa cập nhật.', { exact: true })).toBeVisible();
  expect(posts).toBe(0);
});

test('mất phản hồi đăng ký khóa gửi lại và dẫn đến lịch thật đã được tạo', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  let posts = 0;
  await page.route('**/resident/amenity-bookings', (route) => {
    if (route.request().method() === 'POST') { posts += 1; return route.abort('failed'); }
    return route.fallback();
  });
  await page.route('**/resident/amenity-bookings?*', (route) => route.fulfill({ json: { items: [booking], total: 1, total_pages: 1 } }));
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Chưa xác định được kết quả đăng ký');
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Kiểm tra Lịch của tôi' }).click();
  await expect(page.getByRole('article')).toContainText('BK-TEST');
  expect(posts).toBe(1);
});

test('mất phản hồi hủy kiểm tra máy chủ trước khi báo kết quả', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
  await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Xác nhận hủy', exact: true })).toBeEnabled();
  await page.route('**/resident/amenity-bookings/booking-test/cancel', (route) => route.abort('failed'));
  await page.route('**/resident/amenity-bookings/booking-test', (route) => route.fulfill({ json: { ...booking, status: 'CANCELLED', can_cancel: false } }));
  await page.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Chi tiết đăng ký' }).getByRole('status')).toContainText('Lượt đăng ký BK-TEST đã được hủy');
});

test('history lỗi tải nền giữ dữ liệu và chỉ nhận bộ lọc mới nhất', async ({ page }) => {
  await fixture(page);
  let fail = false;
  await page.route('**/resident/amenity-bookings?*', async (route) => {
    if (fail) return route.fulfill({ status: 503, json: { message: 'Lịch tạm thời không khả dụng' } });
    const status = new URL(route.request().url()).searchParams.get('status');
    if (status === 'APPROVED') await new Promise((resolve) => setTimeout(resolve, 400));
    return route.fulfill({ json: { items: [{ ...booking, status: status || 'PENDING' }], total: 1, total_pages: 1 } });
  });
  await page.getByRole('button', { name: 'Lịch của tôi', exact: true }).click();
  await expect(page.getByRole('article')).toContainText('BK-TEST');
  fail = true;
  await page.getByRole('button', { name: 'Tải lại lịch', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Lịch tạm thời');
  await expect(page.getByRole('article')).toContainText('BK-TEST');
  fail = false;
  await page.getByLabel('Lọc trạng thái').selectOption('APPROVED');
  await page.getByLabel('Lọc trạng thái').selectOption('CANCELLED');
  await expect(page.getByRole('article').getByText('Đã hủy', { exact: true })).toBeVisible();
  await expect(page.getByRole('article').getByText('Đã duyệt', { exact: true })).toHaveCount(0);
});

test('phân trang không dùng kết quả trang cũ và về trang một khi lọc', async ({ page }) => {
  await fixture(page);
  await page.route('**/resident/amenity-bookings?*', (route) => {
    const url = new URL(route.request().url());
    const number = Number(url.searchParams.get('page'));
    const status = url.searchParams.get('status');
    return route.fulfill({ json: { items: [{ ...booking, id: `booking-${number}`, booking_code: `BK-PAGE-${number}`, status: status || 'PENDING' }], total: 25, total_pages: 3 } });
  });
  await page.getByRole('button', { name: 'Lịch của tôi', exact: true }).click();
  await expect(page.getByRole('article')).toContainText('BK-PAGE-1');
  await page.getByRole('button', { name: 'Sau', exact: true }).click();
  await expect(page.getByRole('article')).toContainText('BK-PAGE-2');
  await page.getByLabel('Lọc trạng thái').selectOption('CANCELLED');
  await expect(page.getByRole('article')).toContainText('BK-PAGE-1');
  await expect(page.getByRole('button', { name: 'Trước', exact: true })).toBeDisabled();
});

test('thay đổi tiện ích khác cập nhật catalog nhưng không tải lại giờ đang chọn', async ({ page }) => {
  let catalogs = 0;
  let availability = 0;
  page.on('request', (request) => {
    if (request.url().endsWith('/resident/amenities')) catalogs += 1;
    if (request.url().includes('/availability?')) availability += 1;
  });
  await fixture(page);
  await chooseSlot(page);
  const initialCatalogs = catalogs;
  const initialAvailability = availability;
  await page.evaluate(() => window.dispatchEvent(new StorageEvent('storage', {
    key: 'smart_amenity_sync_event',
    newValue: JSON.stringify({ type: 'AMENITY_UPDATED', amenityId: 'another-amenity', timestamp: Date.now() }),
  })));
  await expect.poll(() => catalogs).toBe(initialCatalogs + 1);
  await expect(page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true })).toBeEnabled();
  expect(availability).toBe(initialAvailability);
  await page.evaluate(() => window.dispatchEvent(new StorageEvent('storage', {
    key: 'smart_amenity_sync_event',
    newValue: JSON.stringify({ type: 'AMENITY_BOOKING_CHANGED', amenityId: 'amenity-test', timestamp: Date.now() }),
  })));
  await expect.poll(() => availability).toBe(initialAvailability + 1);
});

test('hủy chưa xác định kết quả khóa xác nhận và giữ lý do khi tải lại chi tiết', async ({ page }) => {
  await fixture(page);
  await chooseSlot(page);
  await page.getByRole('button', { name: 'Xác nhận đăng ký', exact: true }).click();
  await page.getByRole('button', { name: 'Xem lịch của tôi' }).click();
  await page.getByRole('button', { name: 'Hủy đăng ký', exact: true }).click();
  const confirmation = page.getByRole('alertdialog');
  await expect(confirmation.getByRole('button', { name: 'Xác nhận hủy', exact: true })).toBeEnabled();
  await confirmation.getByLabel('Lý do hủy (không bắt buộc)').fill('Giữ lý do khi thử lại');
  await page.route('**/resident/amenity-bookings/booking-test/cancel', (route) => route.abort('failed'));
  await confirmation.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
  await expect(confirmation.getByRole('alert')).toContainText('Chưa xác định được kết quả hủy');
  await expect(confirmation.getByRole('button', { name: 'Xác nhận hủy', exact: true })).toBeDisabled();
  await confirmation.getByRole('button', { name: 'Thử lại', exact: true }).click();
  await expect(confirmation.getByRole('button', { name: 'Xác nhận hủy', exact: true })).toBeEnabled();
  await expect(confirmation.getByLabel('Lý do hủy (không bắt buộc)')).toHaveValue('Giữ lý do khi thử lại');
});
