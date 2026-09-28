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
    await expect(page.locator('text=TRUNG TÂM QUẢN TRỊ ADMIN').first()).toBeVisible({ timeout: 10000 });

    // 4. Mở Phân hệ Quản lý tiện ích
    await page.goto('/admin/amenities');
    await expect(page.locator('text=Danh mục Tiện ích Tòa nhà').first()).toBeVisible({ timeout: 10000 });

    // 5. Mở Modal tạo mới tiện ích
    const createButton = page.locator('button:has-text("Thêm tiện ích")').first();
    await expect(createButton).toBeVisible();
    await createButton.click();

    // Xác nhận Modal form đã hiển thị
    await expect(page.locator('text=Thêm tiện ích mới').first()).toBeVisible({ timeout: 5000 });

    // 6. Điền dữ liệu tiện ích thực tế với mã duy nhất (không bị duplicate code)
    const uniqueSuffix = Date.now().toString().slice(-6);
    const testName = `Sân Bóng Bàn VIP ${uniqueSuffix}`;
    const testCode = `PONG_${uniqueSuffix}`;
    const testLocation = `Tầng 4 Tháp B - Phòng ${uniqueSuffix}`;

    // Tên tiện ích
    const nameInput = page.locator('input[placeholder*="Sân Tennis"]').first();
    await nameInput.fill(testName);

    // Mã tiện ích (editable sau khi sửa readonly)
    const codeInput = page.locator('input[placeholder*="TENNIS_ROOF"]').first();
    await codeInput.fill(testCode);

    // Vị trí chi tiết
    const locationInput = page.locator('input[placeholder*="Tầng thượng"]').first();
    await locationInput.fill(testLocation);

    // Sức chứa tối đa
    const capacityInput = page.locator('input[type="number"]').first();
    await capacityInput.fill('4');

    // 7. Bấm Lưu tiện ích (Trước đây bị lỗi không lưu do trường code bị readonly và trùng)
    const saveButton = page.locator('button[type="submit"]:has-text("Lưu")').first();
    await expect(saveButton).toBeVisible();
    await saveButton.click();

    // 8. Xác minh tiện ích mới lập tức hiển thị trên danh sách
    await expect(page.locator(`text=${testName}`).first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator(`text=${testCode}`).first()).toBeVisible();

    // 9. Reload lại trang để kiểm tra lưu trữ thật trong CSDL
    await page.reload();

    // 10. Xác minh tiện ích vẫn tồn tại bền vững sau reload
    await expect(page.locator(`text=${testName}`).first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator(`text=${testCode}`).first()).toBeVisible();
  });
});
