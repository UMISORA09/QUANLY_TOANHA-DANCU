import { test, expect } from '@playwright/test';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

for (const width of [375, 1280]) {
  test(`Astryx F1 actions preserve forms and do not theme other portals at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.addInitScript(() => {
      localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'manager', name: 'UI QA', email: 'qa@example.test' }));
      localStorage.setItem('smart_cassavas_token', 'ui-qa-token');
      localStorage.setItem('smart_cassavas_user', JSON.stringify({ id: 'qa-manager', full_name: 'UI QA', roles: ['BUILDING_MANAGER'] }));
    });
    let reloads = 0;
    const category = { id: 'category-qa', version: 1, category_name: 'Thể thao', category_code: 'SPORT', amenities_count: 0 };
    await page.route('**/api/**', route => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/amenities')) { reloads++; return route.fulfill({ json: { items: [], total: 0, page: 1, limit: 15, total_pages: 1 } }); }
      if (path.includes('/amenity-categories')) return route.fulfill({ json: [category] });
      if (path.endsWith('/blocks')) return route.fulfill({ json: [{ id: 'block-qa', block_name: 'Tòa A', block_code: 'A' }] });
      if (path.endsWith('/amenity-booking-worklist')) return route.fulfill({ json: { items: [], counts: { PENDING: 0, REPORTED: 0, REVIEW: 0 }, page: 1, total_pages: 1 } });
      return route.fulfill({ json: { items: [], data: [], user: { id: 'qa-manager', full_name: 'UI QA', roles: ['BUILDING_MANAGER'] } } });
    });
    await page.goto('/quan-ly?tab=amenities');
    await expect(page.getByText('Astryx · Bản thử', { exact: true })).toBeVisible();
    const create = page.getByRole('button', { name: 'Thêm tiện ích', exact: true });
    await expect(create).toBeVisible();
    expect(await create.evaluate(element => element.classList.contains('astryx-button'))).toBe(true);
    const appearance = await create.evaluate(element => { const style = getComputedStyle(element); return { background: style.backgroundColor, color: style.color, height: element.getBoundingClientRect().height }; });
    expect(appearance.height).toBeGreaterThanOrEqual(44);
    expect(appearance.background).toBe('rgb(30, 64, 175)');
    expect(appearance.color).toBe('rgb(255, 255, 255)');
    expect(await page.locator('html').evaluate(element => getComputedStyle(element).getPropertyValue('--color-accent'))).toBe('');
    expect(await page.locator('html').getAttribute('data-astryx-theme')).toBeNull();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const before = reloads;
    await page.getByRole('button', { name: 'Tải lại danh sách tiện ích', exact: true }).click();
    await expect.poll(() => reloads).toBeGreaterThan(before);
    await create.click();
    await expect(page.getByRole('dialog', { name: 'Cấu hình tiện ích' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Cấu hình tiện ích' })).not.toBeVisible();
    await expect(create).toBeFocused();
    await page.getByRole('button', { name: 'Quản lý danh mục', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Quản lý danh mục tiện ích' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await page.goto('/cu-dan?tab=tickets');
    expect(await page.locator('html').getAttribute('data-astryx-theme')).toBeNull();
    await expect(page.locator('.amenity-astryx-trial')).toHaveCount(0);
  });
}
