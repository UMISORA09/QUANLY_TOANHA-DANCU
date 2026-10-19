import { test, expect } from '@playwright/test';

test.describe('Resident Incident Ticket Feature E2E Tests', () => {
  for (const width of [375, 768, 1440]) {
    test(`Danh mục dài không bị che trong form cư dân ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      await page.route('**/api/v1/resident/tickets/categories', (route) => route.fulfill({ json: {
        success: true,
        data: Array.from({ length: 30 }, (_, i) => ({ id: `category-${i}`, category_name: `Danh mục QA ${i}`, default_priority: 'MEDIUM' })),
      } }));
      await page.route('**/api/v1/resident/tickets?*', (route) => route.fulfill({ json: {
        success: true,
        data: ['NEW', 'IN_PROGRESS'].map((status, i) => ({ id: `ticket-qa-${i}`, ticket_number: `TCK-QA-00${i + 1}`, title: i ? 'Đèn hành lang tầng 5 không sáng' : 'Rò nước dưới bồn rửa bếp', description: 'Yêu cầu hỗ trợ kiểm tra.', location: i ? 'Hành lang tầng 5' : 'Phòng bếp', priority: 'HIGH', category: { category_name: 'Điện nước' }, status, created_at: '2026-10-16T08:00:00Z' })),
        pagination: { current_page: 1, last_page: 1, total: 2, per_page: 15 },
        counters: { all: 2, new: 1, in_progress: 1, resolved: 0, closed: 0, cancelled: 0 },
      } }));
      await page.goto('/cu-dan?tab=tickets');
      await expect(page.locator('#ticket-open-create-btn')).toBeVisible();
      await expect(page.getByRole('button', { name: 'Xem chi tiết', exact: true }).first()).toBeVisible();
      await page.locator('#ticket-open-create-btn').click();
      const category = page.locator('#ticket-category-select');
      await category.click();
      const menu = page.getByRole('listbox');
      await expect.poll(() => menu.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight
          && document.elementFromPoint(rect.left + rect.width / 2, rect.bottom - 8)?.closest('[role="listbox"]') === element;
      })).toBe(true);
      await category.press('End');
      await page.getByRole('option', { name: 'Danh mục QA 29', exact: true }).click();
      await expect(category).toContainText('Danh mục QA 29');
      await category.click();
      await category.press('Escape');
      await expect(menu).toHaveCount(0);
      await expect(page.getByRole('dialog', { name: 'Gửi yêu cầu báo cáo sự cố', exact: true })).toBeVisible();
      await expect(category).toBeFocused();
      expect(await category.evaluate((element) => {
        const footer = element.closest('dialog')?.querySelector('#ticket-submit-btn')?.parentElement?.getBoundingClientRect();
        return Boolean(footer && element.getBoundingClientRect().bottom <= footer.top);
      })).toBe(true);
    });
  }

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

    // Các bộ lọc nhanh giữ nhãn và số lượng yêu cầu.
    for (const label of ['Tất cả yêu cầu', 'Mới gửi', 'Đang xử lý', 'Chờ nghiệm thu', 'Đã hoàn tất', 'Đã hủy']) {
      await expect(page.getByRole('button', { name: `${label} 0`, exact: true })).toBeVisible();
    }
  });

  for (const width of [375, 1440]) {
    test(`Số và nhãn luôn tương phản khi đổi trạng thái ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/cu-dan?tab=tickets');
      const filters = page.getByLabel('Lọc nhanh yêu cầu', { exact: true });
      for (const label of ['Tất cả yêu cầu', 'Mới gửi', 'Đang xử lý', 'Chờ nghiệm thu', 'Đã hoàn tất', 'Đã hủy']) {
        const button = filters.getByRole('button', { name: `${label} 0`, exact: true });
        await button.click();
        await expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(await button.evaluate((element) => {
          const context = document.createElement('canvas').getContext('2d')!;
          const rgb = (color: string) => {
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            return Array.from(context.getImageData(0, 0, 1, 1).data.slice(0, 3));
          };
          return {
            background: rgb(getComputedStyle(element).backgroundColor),
            label: rgb(getComputedStyle(element.children[0]).color),
            count: rgb(getComputedStyle(element.children[1]).color),
          };
        })).toEqual({ background: [10, 10, 10], label: [255, 255, 255], count: [255, 255, 255] });
        await expect(filters.locator('button[aria-pressed="true"]')).toHaveCount(1);
      }
    });
  }

  test('Chuyển sang form tạo Ticket và kiểm tra validation form', async ({ page }) => {
    await page.goto('/cu-dan?tab=tickets');
    await page.waitForLoadState('networkidle');

    // Bấm nút mở form tạo mới
    await page.click('#ticket-open-create-btn');

    // Kiểm tra các trường form hiển thị
    await expect(page.getByRole('dialog', { name: 'Gửi yêu cầu báo cáo sự cố', exact: true })).toBeVisible();
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

    // Nút Đóng form tạo mới hoạt động đúng
    await page.click('#ticket-close-create-btn');
    await expect(page.locator('#ticket-search-input')).toBeVisible();
    expect(await page.evaluate(() => document.body.style.overflow)).not.toBe('hidden');
  });

  for (const width of [375, 1440]) {
  test(`Ảnh đã chọn có thao tác rõ và đóng phóng to bằng Escape ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/cu-dan?tab=tickets');
    await page.locator('#ticket-open-create-btn').click();
    const photo = await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 360;
      const context = canvas.getContext('2d')!;
      context.fillStyle = '#e0f2fe'; context.fillRect(0, 0, 640, 360);
      context.fillStyle = '#0c4a6e'; context.font = '32px sans-serif'; context.fillText('Ảnh hiện trường QA', 40, 100);
      context.fillStyle = '#0284c7'; context.fillRect(40, 150, 260, 140);
      context.fillStyle = '#fbbf24'; context.fillRect(330, 150, 260, 140);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    await page.getByLabel('Chọn ảnh hiện trường', { exact: true }).setInputFiles({ name: 'anh-qa.png', mimeType: 'image/png', buffer: Buffer.from(photo, 'base64') });
    await page.getByRole('button', { name: 'Xem ảnh đã chọn 1', exact: true }).click();
    const preview = page.getByRole('dialog', { name: 'Xem ảnh sự cố', exact: true });
    await expect(preview.getByRole('img')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(preview).toHaveCount(0);
    await expect(page.getByRole('dialog', { name: 'Gửi yêu cầu báo cáo sự cố', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Bỏ ảnh đã chọn 1', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Xem ảnh đã chọn 1', exact: true })).toHaveCount(0);
  });
  }

  test('Danh sách cư dân trên điện thoại hiển thị đúng trạng thái gửi, tiếp nhận và nghiệm thu', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.route('**/api/v1/resident/tickets?*', (route) => route.fulfill({ json: {
      success: true,
      data: ['NEW', 'RECEIVED', 'RESOLVED'].map((status, i) => ({ id: `ticket-${i}`, ticket_number: `TCK-QA-${i}`, title: `Yêu cầu QA ${i}`, location: 'Bếp', priority: 'HIGH', status, created_at: '2026-10-16T08:00:00Z' })),
      pagination: { current_page: 1, last_page: 1, total: 3, per_page: 15 },
      counters: { all: 3, new: 1, in_progress: 0, resolved: 1, closed: 0, cancelled: 0 },
    } }));
    await page.goto('/cu-dan?tab=tickets');
    const list = page.getByRole('list', { name: 'Danh sách yêu cầu của tôi', exact: true });
    for (const label of ['Mới gửi', 'Đã tiếp nhận', 'Chờ nghiệm thu']) await expect(list.getByText(label, { exact: true })).toBeVisible();
    await expect(list.getByRole('button', { name: 'Xem chi tiết', exact: true })).toHaveCount(3);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
});
