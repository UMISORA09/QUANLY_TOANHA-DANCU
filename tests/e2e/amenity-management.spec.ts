import { test, expect } from '@playwright/test';

test.describe('Utility & Amenity Management Flow', () => {
  test('Complete flow: Open app -> Login -> Admin -> Amenity Management -> Create record -> Save -> Verify -> Reload -> Verify persistence', async ({ page }) => {
    // 1. Mở ứng dụng
    await page.goto('/home');
    await expect(page).toHaveTitle(/.*Cassavas|Quản lý.*/i);

    // 2. Thiết lập phiên đăng nhập Quản Trị Viên (Admin)
    await page.evaluate(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({
        role: 'admin',
        email: 'admin@cassavas.vn',
        name: 'Admin Cassavas'
      }));
    });

    // 3. Mở Trung tâm Quản trị Admin
    await page.goto('/admin');
    await expect(page.locator('text=TRUNG TÂM QUẢN TRỊ').first()).toBeVisible({ timeout: 15000 });

    // 4. Mở Phân hệ Quản lý tiện ích
    await page.goto('/admin/amenities');
    await expect(page.locator('text=Danh mục Tiện ích Tòa nhà').first()).toBeVisible({ timeout: 10000 });

    // 5. Mở Modal tạo mới tiện ích
    const createButton = page.locator('button:has-text("Thêm tiện ích")').first();
    await expect(createButton).toBeVisible();
    await createButton.click();

    // Xác nhận Modal form đã hiển thị
    await expect(page.locator('text=Thêm Tiện ích Mới').first()).toBeVisible({ timeout: 5000 });

    // 6. Điền dữ liệu tiện ích thực tế với mã duy nhất (không bị duplicate code)
    const uniqueSuffix = Date.now().toString().slice(-6);
    const testName = `Sân Bóng Bàn VIP ${uniqueSuffix}`;
    const testCode = `PONG_${uniqueSuffix}`;
    const testLocation = `Tầng 4 Tháp B - Phòng ${uniqueSuffix}`;

    // Chọn danh mục tiện ích
    const categorySelect = page.locator('form select').first();
    await categorySelect.selectOption({ index: 1 });

    // Tên tiện ích
    const nameInput = page.locator('form input[placeholder*="Nhập tên tiện ích"]').first();
    await nameInput.fill(testName);

    // Mã tiện ích
    const codeInput = page.locator('form input[placeholder*="Mã tiện ích"]').first();
    await codeInput.fill(testCode);

    // Vị trí chi tiết
    const locationInput = page.locator('form input[placeholder*="Nhập vị trí chi tiết"]').first();
    await locationInput.fill(testLocation);

    // Sức chứa tối đa
    const capacityInput = page.locator('form input[type="number"]').first();
    await capacityInput.fill('4');

    // 7. Bấm Tạo tiện ích
    const saveButton = page.locator('form button[type="submit"]:has-text("Tạo tiện ích")').first();
    await expect(saveButton).toBeVisible();
    await saveButton.click();

    // Chờ modal đóng lại sau khi tạo thành công
    await expect(page.locator('text=Thêm Tiện ích Mới').first()).toBeHidden({ timeout: 10000 });

    // 8. Tìm kiếm tiện ích vừa tạo để hiển thị chính xác
    const searchInput = page.locator('input[placeholder*="Tìm theo tên tiện ích"]').first();
    await searchInput.fill(testCode);

    // Xác minh tiện ích mới lập tức hiển thị trên danh sách
    await expect(page.locator(`text=${testName}`).first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator(`text=${testCode}`).first()).toBeVisible();

    // 9. Reload lại trang để kiểm tra lưu trữ thật trong CSDL
    await page.reload();

    // 10. Tìm lại tiện ích sau khi reload và xác minh tồn tại bền vững
    const searchAfterReload = page.locator('input[placeholder*="Tìm theo tên tiện ích"]').first();
    await searchAfterReload.fill(testCode);
    await expect(page.locator(`text=${testName}`).first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator(`text=${testCode}`).first()).toBeVisible();
  });
});
