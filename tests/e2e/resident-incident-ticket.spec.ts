import { test, expect } from '@playwright/test';

test.describe('Resident Incident Ticket Feature E2E Tests', () => {
  test.beforeEach(async ({ page }) => {
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
    await page.selectOption('#ticket-priority-select', 'HIGH');

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
