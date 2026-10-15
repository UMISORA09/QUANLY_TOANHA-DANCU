import { test, expect } from '@playwright/test';

test.describe('Resident Incident Ticket Feature E2E Tests', () => {
  test('Gửi lại sau lỗi mạng giữ cùng khóa chống trùng', async ({ page }) => {
    const keys: string[] = [];
    await page.route('**/api/**', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      if (path.endsWith('/resident/tickets') && request.method() === 'POST') {
        keys.push(request.headers()['x-idempotency-key']);
        if (keys.length === 1) {
          await route.abort('failed');
          return;
        }
        await route.fulfill({ status: 201, json: { success: true, data: { id: 'qa-ticket', ticket_number: 'TCK-QA-RETRY' } } });
        return;
      }
      const apartment = { id: 'qa-apartment', apartment_number: 'QA-01' };
      const data = path.endsWith('/ticket-notifications') ? { items: [], unread_count: 0, page: 1, total_pages: 1 } : path.endsWith('/tickets/categories')
        ? { success: true, data: [{ id: 'qa-category', category_name: 'Điện nước', default_priority: 'MEDIUM' }] }
        : path.includes('/resident/tickets')
          ? { success: true, data: [], pagination: { current_page: 1, last_page: 1, total: 0, per_page: 10 }, counters: { all: 0, new: 0, in_progress: 0, resolved: 0, closed: 0, cancelled: 0 } }
          : { user: { full_name: 'Cư dân QA' }, apartment, apartments: [apartment] };
      await route.fulfill({ json: data });
    });
    await page.goto('/cu-dan?tab=tickets');
    await page.click('#ticket-open-create-btn');
    await page.locator('#ticket-category-select').click();
    await page.getByRole('option', { name: 'Điện nước', exact: true }).click();
    await page.fill('#ticket-title-input', 'Kiểm tra vòi nước');
    await page.fill('#ticket-location-input', 'Phòng bếp');
    await page.fill('#ticket-description-textarea', 'Vòi nước bị rò rỉ cần hỗ trợ.');
    await page.click('#ticket-submit-btn');
    await expect.poll(() => keys.length).toBe(1);
    await expect(page.locator('#ticket-submit-btn')).toBeEnabled();
    await page.click('#ticket-submit-btn');
    await expect.poll(() => keys.length).toBe(2);
    expect(keys[0]).toBeTruthy();
    expect(keys[1]).toBe(keys[0]);
    await expect(page.getByText(/Gửi báo cáo sự cố thành công! Mã yêu cầu: TCK-QA-RETRY/)).toBeVisible();
  });

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      const apartment = { id: 'qa-apartment', apartment_number: 'QA-01' };
      const data = path.endsWith('/ticket-notifications') ? { items: [], unread_count: 0, page: 1, total_pages: 1 }
        : path.endsWith('/tickets/categories') ? { success: true, data: [{ id: 'qa-category', category_name: 'Điện nước', default_priority: 'MEDIUM' }] }
        : path.includes('/resident/tickets') ? { success: true, data: [], pagination: { current_page: 1, last_page: 1, total: 0, per_page: 15 }, counters: { all: 0, new: 0, in_progress: 0, resolved: 0, closed: 0, cancelled: 0 } }
        : { user: { full_name: 'Cư dân QA' }, apartment, apartments: [apartment] };
      await route.fulfill({ json: data });
    });
    // Giả lập phiên cư dân trong localStorage trước khi mount app
    await page.addInitScript(() => {
      const mockSession = {
        role: 'resident',
        resident_type: 'OWNER',
        email: 'nguyenvanan@cassavas.vn',
        name: 'Nguyễn Văn An',
      };
      localStorage.setItem('smartcassavas_session', JSON.stringify(mockSession));
      localStorage.setItem('smart_cassavas_token', 'smart_token_resident_demo');
    });
  });

  test('Cư dân có thể truy cập trang Báo cáo sự cố từ URL /cu-dan?tab=tickets', async ({ page }) => {
    await page.goto('/cu-dan?tab=tickets');
    await page.waitForLoadState('networkidle');

    // Tiêu đề phân hệ phải hiển thị rõ ràng
    await expect(page.locator('h1')).toContainText('Báo cáo Sự cố & Sửa chữa');
    await expect(page.locator('body')).toContainText('HỖ TRỢ KỸ THUẬT & VẬN HÀNH');

    // Nút tạo sự cố mới phải hiển thị
    const createBtn = page.locator('#ticket-open-create-btn');
    await expect(createBtn).toBeVisible();
    await expect(createBtn).toContainText('Gửi báo cáo sự cố mới');
  });

  test('Giao diện hiển thị thanh tìm kiếm, bộ lọc và các thẻ thống kê counters', async ({ page }) => {
    await page.goto('/cu-dan?tab=tickets');
    await page.waitForLoadState('networkidle');

    // Kiểm tra search box & filters
    await expect(page.locator('#ticket-search-input')).toBeVisible();
    await expect(page.locator('#ticket-status-filter')).toBeVisible();
    await expect(page.locator('#ticket-category-filter')).toBeVisible();

    // Kiểm tra 6 thẻ thống kê
    await expect(page.locator('text=Tất cả yêu cầu')).toBeVisible();
    await expect(page.locator('text=Mới gửi')).toBeVisible();
    await expect(page.locator('text=Đang xử lý')).toBeVisible();
    await expect(page.locator('text=Chờ nghiệm thu')).toBeVisible();
    await expect(page.locator('text=Đã hoàn tất')).toBeVisible();
    await expect(page.locator('text=Đã hủy')).toBeVisible();
  });

  test('Chuyển sang form tạo Ticket và kiểm tra validation form', async ({ page }) => {
    await page.goto('/cu-dan?tab=tickets');
    await page.waitForLoadState('networkidle');

    // Bấm nút mở form tạo mới
    await page.click('#ticket-open-create-btn');

    // Kiểm tra các trường form hiển thị
    await expect(page.locator('text=Gửi yêu cầu Báo cáo Sự cố Kỹ thuật')).toBeVisible();
    await expect(page.locator('#ticket-title-input')).toBeVisible();
    await expect(page.locator('#ticket-category-select')).toBeVisible();
    await expect(page.locator('#ticket-location-input')).toBeVisible();
    await expect(page.locator('#ticket-priority-select')).toBeVisible();
    await expect(page.locator('#ticket-description-textarea')).toBeVisible();
    await expect(page.locator('#ticket-submit-btn')).toBeVisible();

    // Bấm submit khi chưa điền dữ liệu để kiểm tra validation
    await page.click('#ticket-submit-btn');

    // Phải hiển thị lỗi validation cho các trường bắt buộc
    await expect(page.locator('text=Vui lòng nhập tiêu đề sự cố')).toBeVisible();
  });

  test('Điền form tạo mới sự cố và tương tác với các trường nhập', async ({ page }) => {
    await page.goto('/cu-dan?tab=tickets');
    await page.waitForLoadState('networkidle');

    await page.click('#ticket-open-create-btn');

    // Nhập tiêu đề
    await page.fill('#ticket-title-input', 'Chập điện ổ cắm khu vực bếp');

    // Chọn vị trí bằng quick chip
    await page.click('button:has-text("Khu vực bếp")');
    await expect(page.locator('#ticket-location-input')).toHaveValue('Khu vực bếp');

    // Chọn mức độ ưu tiên
    await page.locator('#ticket-priority-select').click();
    await page.getByRole('option', { name: 'Ưu tiên cao', exact: true }).click();

    // Nhập mô tả
    await page.fill(
      '#ticket-description-textarea',
      'Ổ cắm phát ra tiếng xẹt điện khi cắm lò vi sóng, có mùi khét nhẹ cần kiểm tra gấp.'
    );

    // Nút quay lại danh sách hoạt động đúng
    await page.click('#ticket-back-to-list-btn');
    await expect(page.locator('#ticket-search-input')).toBeVisible();
  });
});
