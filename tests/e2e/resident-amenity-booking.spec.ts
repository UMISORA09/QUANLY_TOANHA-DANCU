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
  await page.getByRole('button', { name: /Vườn BBQ/ }).click();
  await expect(page.getByRole('button', { name: 'Đặt tiện ích', exact: true, includeHidden: true }).first()).toBeAttached();
  await page.getByRole('button', { name: /10:00.*11:30/ }).click();
  await page.getByLabel('Số người', { exact: true }).fill('2');
  await page.getByRole('checkbox').check();
}

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
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByLabel('Lý do hủy (không bắt buộc)').fill('Đổi kế hoạch');
    await page.getByRole('button', { name: 'Xác nhận hủy', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('Đã hủy', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Đóng', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toBeVisible();
  });
}

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
  await page.getByRole('button', { name: 'Tổng quan Cư dân', exact: true }).first().click();
  await page.goBack();
  await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toBeVisible();
  await page.goForward();
  await expect(page.getByRole('heading', { name: 'Đăng ký sử dụng tiện ích' })).toHaveCount(0);
});
