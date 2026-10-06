import { test, expect, Page } from '@playwright/test';

const today = '2026-10-05';
const amenity = {
  id: 'amenity-test', category_id: 'category-test', block_id: 'block-test', amenity_name: 'Vườn BBQ',
  location_detail: 'Tầng thượng', max_capacity_per_slot: 4, hourly_rate: 100000, security_deposit_required: 50000,
  advance_booking_days_limit: 7, min_cancel_hours_before: 12, requires_admin_approval: true,
  rules_and_regulations: 'Giữ gìn vệ sinh chung.', is_active: true,
};
const availableSlot = { slot_id: 'slot-test', start_time: '10:00', end_time: '11:30', slot_label: 'Buổi sáng', available: true, reason: null, remaining_bookings: 2, remaining_attendees: 4, total_amount: 150000, deposit_amount: 50000 };
const booking = { id: 'booking-test', booking_code: 'BK-TEST', amenity_id: amenity.id, apartment_id: 'apartment-test', apartment_number: 'A-101', resident_user_id: 'user-test', amenity_name: amenity.amenity_name, location_detail: amenity.location_detail, booking_date: today, start_time: '10:00', end_time: '11:30', attendee_count: 2, total_amount: 150000, deposit_amount: 50000, is_paid: false, status: 'PENDING', can_cancel: true, cancel_deadline: '2026-10-04T22:00:00+07:00' };

async function fixture(page: Page, options: { conflict?: boolean; unauthorized?: boolean; slowFirstDate?: boolean; networkError?: boolean } = {}) {
  let saved: typeof booking | null = null;
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
    if (url.pathname.endsWith('/overview')) return route.fulfill({ json: {} });
    if (url.pathname.endsWith('/amenities')) {
      if (options.networkError) return route.abort('failed');
      return route.fulfill({ json: { apartments: [{ id: 'apartment-test', apartment_number: 'A-101', block_id: 'block-test', block_name: 'Tòa A' }], amenities: [amenity], categories: [{ id: 'category-test', category_name: 'BBQ' }], today, timezone: 'Asia/Ho_Chi_Minh' } });
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
      saved = { ...booking };
      return route.fulfill({ status: 201, json: { success: true, booking: saved } });
    }
    if (url.pathname.endsWith('/amenity-bookings')) return route.fulfill({ json: { items: saved ? [saved] : [], page: 1, total: saved ? 1 : 0, total_pages: 1 } });
    return route.fulfill({ json: saved || booking });
  });
  await page.goto('/cu-dan?tab=amenities');
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
  await page.getByTitle('Xem thông tin đặt chỗ của cư dân', { exact: true }).click();
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

for (const viewport of [{ width: 1366, height: 900 }, { width: 390, height: 844 }]) {
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
