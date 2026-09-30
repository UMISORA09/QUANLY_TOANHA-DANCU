import { test, expect } from '@playwright/test';

/**
 * E2E Smoke Tests — Smart Cassavas Building Management System
 *
 * Mục tiêu: Xác minh ứng dụng khởi động đúng, React SPA render được,
 * và các API endpoint công khai hoạt động bình thường trong môi trường CI.
 *
 * Lưu ý: Flow test phức tạp cần auth thật (đăng nhập, tạo dữ liệu, v.v.)
 * nên được chạy ở môi trường staging với DB được seed đầy đủ.
 */

test.describe('Smoke Tests — App Availability & Health', () => {
    test('Trang chủ (/) redirect và React SPA load thành công', async ({ page }) => {
        const response = await page.goto('/');
        // Redirect từ / → /home phải thành công
        expect(response?.status()).toBeLessThan(400);
        // URL cuối cùng phải là /home
        await expect(page).toHaveURL(/\/home/);
        // Trang phải có title hợp lệ (không phải trắng)
        const title = await page.title();
        expect(title.length).toBeGreaterThan(0);
    });

    test('React SPA render được trên route /home', async ({ page }) => {
        await page.goto('/home');
        // Chờ React bundle load xong — tìm thẻ root #app hoặc body có nội dung
        await page.waitForLoadState('networkidle');
        // Body không được trống sau khi React mount
        const bodyText = await page.locator('body').innerText();
        expect(bodyText.length).toBeGreaterThan(10);
        // Không được có lỗi JavaScript nghiêm trọng (app crash)
        const hasErrorBoundary = await page.locator('text=Application Error').count();
        expect(hasErrorBoundary).toBe(0);
    });

    test('Route /login trả về React SPA (không phải 404)', async ({ page }) => {
        const response = await page.goto('/login');
        expect(response?.status()).toBeLessThan(400);
        await page.waitForLoadState('networkidle');
        // Không có lỗi crash
        const errorCount = await page.locator('text=500').count();
        expect(errorCount).toBe(0);
    });

    test('Route /admin trả về React SPA (không phải 404)', async ({ page }) => {
        const response = await page.goto('/admin');
        expect(response?.status()).toBeLessThan(400);
        await page.waitForLoadState('networkidle');
    });

    test('Route /admin/amenities trả về React SPA (không phải 404)', async ({ page }) => {
        const response = await page.goto('/admin/amenities');
        expect(response?.status()).toBeLessThan(400);
        await page.waitForLoadState('networkidle');
    });
});

test.describe('Smoke Tests — Public API Health Endpoints', () => {
    test('GET /health trả về status healthy', async ({ request }) => {
        const response = await request.get('/health');
        expect(response.status()).toBe(200);
        const body = await response.json();
        // Status phải là healthy hoặc ok
        expect(['healthy', 'ok']).toContain(body.status);
    });

    test('GET /api/health trả về HTTP 200', async ({ request }) => {
        const response = await request.get('/api/health');
        expect(response.status()).toBe(200);
    });

    test('GET /api/db-health trả về HTTP 200 — kết nối DB hoạt động', async ({ request }) => {
        const response = await request.get('/api/db-health');
        expect(response.status()).toBe(200);
    });

    test('GET /metrics trả về Prometheus metrics (HTTP 200)', async ({ request }) => {
        const response = await request.get('/metrics');
        expect(response.status()).toBe(200);
        const text = await response.text();
        // Phải có ít nhất một Prometheus metric
        expect(text.length).toBeGreaterThan(0);
    });

    test('GET /api/monitoring/freshness trả về HTTP 200', async ({ request }) => {
        const response = await request.get('/api/monitoring/freshness');
        expect(response.status()).toBe(200);
    });
});
