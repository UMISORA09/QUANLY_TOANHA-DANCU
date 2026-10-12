import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import type { PipelineItem } from '../../resources/js/Services/cicdApi';

async function getSyncedAppHtml(): Promise<string> {
  let appHtml = await readFile('public/index.html', 'utf8');
  try {
    const manifestRaw = await readFile('public/build/manifest.json', 'utf8');
    const manifest = JSON.parse(manifestRaw);
    if (manifest['resources/css/app.css']?.file) {
      appHtml = appHtml.replace(/\/build\/assets\/app-[^"]+\.css/, `/build/${manifest['resources/css/app.css'].file}`);
    }
    if (manifest['resources/js/app.tsx']?.file) {
      appHtml = appHtml.replace(/\/build\/assets\/app-[^"]+\.js/, `/build/${manifest['resources/js/app.tsx'].file}`);
    }
  } catch {}
  return appHtml;
}

test('pipeline pages contain at most 15 rows and reset after filtering', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-10-12T10:00:00Z') });
  let pipelines: PipelineItem[] = Array.from({ length: 32 }, (_, index) => ({
    id: String(index + 1), run_number: index + 1, name: `Pipeline ${index + 1}`,
    workflow_file: index === 1 ? 'vercel.yml' : 'ci.yml', status: 'success', branch: index < 20 ? 'master' : 'feature/test',
    commit_sha: 'abc1234', commit_message: `Commit ${index + 1}`, author: index < 20 ? 'QA' : 'Other', author_login: index < 20 ? 'qa' : 'other',
    trigger: 'push', duration: '1m', created_at: index === 2 ? '2026-09-01T10:00:00Z' : '2026-10-11T10:00:00Z',
  }));
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-token');
  });
  await page.route('**/api/**', route => route.fulfill({ json: { data: {} } }));
  await page.route('**/api/admin/cicd/history?*', route => {
    const historyPage = Number(new URL(route.request().url()).searchParams.get('page'));
    return route.fulfill({ json: { data: { pipelines: pipelines.slice((historyPage - 1) * 16, historyPage * 16), activities: [], has_more: historyPage * 16 < pipelines.length, total_pipelines: pipelines.length } } });
  });
  await page.route('**/api/admin/cicd/pipelines/*/jobs', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/admin/cicd/pipelines/*/logs', route => route.fulfill({ json: { data: { logs: 'QA pipeline log' } } }));
  await page.route('**/api/admin/cicd/bundle?*', route => route.fulfill({ json: { data: {
    overview: { total_pipelines: pipelines.length, success_count: pipelines.length, failed_count: 0, running_count: 0,
      success_rate: 100, latest_pipeline: pipelines[0], production_status: 'healthy', production_version: 'prod-abc1234',
      staging_status: 'not_configured', staging_version: 'N/A', system_health: 'unknown', is_live_github: true },
    pipelines, deployments: [{ id: 'deploy-1', environment: 'production', version: 'prod-abc1234', image_tag: '',
      commit_sha: 'abc1234', status: 'healthy', deployed_by: 'QA', deployed_at: '2026-10-11T10:00:00Z',
      response_time_ms: 0, release_notes: 'Actual deployment' }], environments: [], health: null,
    activities: [], branches: ['master', 'feature/test', 'DangNguyen/a-very-long-feature-branch-name-for-layout-regression'],
  } } }));
  const appHtml = await getSyncedAppHtml();
  await page.route('**/admin/cicd', route => route.fulfill({ contentType: 'text/html', body: appHtml }));
  await page.goto('/admin/cicd');
  await expect(page.getByText('Trạng thái workflow từ GitHub')).toBeVisible();
  await expect(page.getByText('26/26 passed', { exact: false })).toHaveCount(0);
  await page.getByRole('button', { name: /Triển khai & Bản phát hành/ }).click();
  await expect(page.getByText('Actual deployment')).toBeVisible();
  await expect(page.getByText('61bc9af', { exact: false })).toHaveCount(0);
  await page.getByRole('button', { name: /Danh sách Pipelines/ }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  const branchFilter = page.getByRole('combobox', { name: 'Lọc nhánh pipeline' });
  const branchBounds = await branchFilter.boundingBox();
  const filterBounds = await branchFilter.locator('..').boundingBox();
  expect(branchBounds!.width).toBeLessThanOrEqual(filterBounds!.width);
  await page.getByRole('button', { name: 'Chi tiết log', exact: true }).click();
  await expect(page.getByText('QA pipeline log', { exact: true })).toBeVisible();
  const drawer = page.locator('.fixed.inset-y-0.right-0 > div');
  const drawerBounds = await drawer.boundingBox();
  expect(drawerBounds!.x + drawerBounds!.width).toBeLessThanOrEqual(390);
  expect(await drawer.evaluate(el => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
  await page.screenshot({ path: 'test-results/cicd-mobile-drawer.png' });
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', { name: 'Chi tiết log', exact: true }).click();
  await page.getByRole('button', { name: 'Đóng chi tiết pipeline' }).click();
  await page.setViewportSize({ width: 1280, height: 720 });
  const rows = page.locator('table tbody tr');
  const pagination = page.getByRole('navigation', { name: 'Phân trang pipelines' });
  await expect(pagination).toContainText('Trang 1/3');
  await expect(rows).toHaveCount(15);
  await expect(pagination.getByRole('button', { name: 'Trước', exact: true })).toBeDisabled();
  await pagination.getByRole('button', { name: 'Sau', exact: true }).click();
  await expect(rows).toHaveCount(15);
  await expect(rows.first()).toContainText('Pipeline 17');
  await pagination.getByRole('button', { name: 'Sau', exact: true }).click();
  await expect(rows).toHaveCount(2);
  await expect(pagination.getByRole('button', { name: 'Sau', exact: true })).toBeDisabled();
  await page.getByRole('combobox', { name: 'Lọc tác giả pipeline' }).selectOption('other');
  await expect(rows).toHaveCount(12);
  await expect(pagination).toContainText('Trang 1/1');
  await page.getByRole('combobox', { name: 'Lọc tác giả pipeline' }).selectOption('all');
  await page.getByPlaceholder('Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp...').fill('feature/test');
  await expect(rows).toHaveCount(12);
  await expect(pagination).toContainText('Trang 1/1');
  await page.getByPlaceholder('Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp...').fill('no-matching-pipeline');
  await expect(rows).toHaveCount(0);
  await expect(pagination).toHaveCount(0);
  await page.getByPlaceholder('Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp...').fill('');
  await expect(rows).toHaveCount(15);
  await pagination.getByRole('button', { name: 'Sau', exact: true }).click();
  pipelines = pipelines.slice(0, 3);
  await page.getByRole('button', { name: 'Làm mới', exact: true }).click();
  await expect(rows).toHaveCount(3);
  await expect(pagination).toContainText('Trang 1/1');
  await page.getByPlaceholder('Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp...').fill('');
  await page.getByRole('combobox', { name: 'Lọc thời gian pipeline' }).selectOption('today');
  await expect(rows).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Lọc thời gian pipeline' }).selectOption('7days');
  await expect(rows).toHaveCount(2);
  await page.getByRole('combobox', { name: 'Lọc thời gian pipeline' }).selectOption('30days');
  await expect(rows).toHaveCount(2);
  await page.getByRole('combobox', { name: 'Lọc thời gian pipeline' }).selectOption('all');
  await expect(rows).toHaveCount(3);
  await page.getByRole('combobox', { name: 'Lọc workflow' }).selectOption('ci.yml');
  await expect(rows).toHaveCount(2);
  await page.getByRole('combobox', { name: 'Lọc workflow' }).selectOption('vercel.yml');
  await expect(rows).toHaveCount(1);
  await page.getByRole('combobox', { name: 'Lọc workflow' }).selectOption('all');
  await page.screenshot({ path: 'test-results/cicd-desktop.png' });
});

test('full GitHub history, author filtering, sliders and live updates preserve 15 items per page', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-10-12T10:00:00Z') });
  const pipelines = Array.from({ length: 150 }, (_, index) => ({
    id: String(index + 1), run_number: index + 1, name: `History pipeline ${index + 1}`,
    workflow_file: 'ci.yml', status: 'success', branch: 'master', commit_sha: 'abcdef1',
    commit_message: 'Old history', author: index % 2 ? 'Other' : 'QA', author_login: index % 2 ? 'other' : 'qa',
    trigger: 'push', duration: '1m', created_at: '2025-01-01T10:00:00Z',
  }));
  const activities = pipelines.map(item => ({ id: `act-${item.id}`, title: `Activity ${item.id}`, description: 'Old GitHub activity',
    actor: item.author, timestamp: item.created_at, status: 'success', type: 'test' }));
  let unavailable = false;
  let failOlderPages = false;
  let headRequests = 0;
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-token');
  });
  await page.route('**/api/**', route => route.fulfill({ json: { data: {} } }));
  await page.route('**/api/monitoring/freshness*', route => route.fulfill({ status: 503, json: { message: 'Not available in QA' } }));
  await page.route('**/api/admin/cicd/bundle?*', route => route.fulfill({ json: { data: {
    overview: { total_pipelines: 25, success_count: 25, failed_count: 0, running_count: 0, success_rate: 100,
      latest_pipeline: null, production_status: 'not_configured', staging_status: 'not_configured', system_health: 'unknown', is_live_github: true },
    pipelines: [], activities: [], deployments: [], environments: [], health: null, branches: ['master'],
  } } }));
  await page.route('**/api/admin/cicd/history?*', route => {
    const number = Number(new URL(route.request().url()).searchParams.get('page'));
    if (number === 1) headRequests++;
    if (number > 1 && failOlderPages) return route.fulfill({ status: 502, json: { message: 'Older GitHub page unavailable in QA' } });
    if (unavailable) return route.fulfill({ status: 502, json: { message: 'GitHub unavailable in QA' } });
    return route.fulfill({ json: { data: { pipelines: pipelines.slice((number - 1) * 100, number * 100),
      activities: activities.slice((number - 1) * 100, number * 100), total_pipelines: pipelines.length, has_more: number * 100 < pipelines.length } } });
  });
  const appHtml = await getSyncedAppHtml();
  await page.route('**/admin/cicd', route => route.fulfill({ contentType: 'text/html', body: appHtml }));
  await page.goto('/admin/cicd');
  const nav = page.getByRole('navigation', { name: 'Phân trang pipelines' });
  const rows = page.locator('table tbody tr');
  await expect(nav).toContainText('Trang 1/10');
  await expect(rows).toHaveCount(15);
  await page.getByRole('slider', { name: 'Chuyển trang pipelines' }).focus();
  await page.keyboard.press('End');
  await expect(nav).toContainText('Trang 10/10');
  await expect(rows.first()).toContainText('History pipeline 136');
  await page.getByRole('combobox', { name: 'Lọc tác giả pipeline' }).selectOption('qa');
  await expect(nav).toContainText('Trang 1/5');
  await expect(rows).toHaveCount(15);
  await expect(rows.filter({ hasText: '@other' })).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Lọc tác giả pipeline' }).selectOption('all');
  await page.getByRole('button', { name: 'Nhật ký Hoạt động', exact: true }).click();
  const logNav = page.getByRole('navigation', { name: 'Phân trang nhật ký' });
  const items = page.getByRole('list', { name: 'Lịch sử hoạt động GitHub' }).getByRole('listitem');
  await expect(items).toHaveCount(15);
  await page.getByRole('button', { name: 'Trang cuối' }).click();
  await expect(logNav).toContainText('Trang 10/10');
  await expect(items.first()).toContainText('Activity 136');
  pipelines.unshift({ ...pipelines[0], id: '151', run_number: 151, name: 'Realtime pipeline', created_at: '2026-10-12T10:00:00Z' });
  activities.unshift({ ...activities[0], id: 'act-151', title: 'Realtime event', timestamp: '2026-10-12T10:00:00Z' });
  await page.clock.runFor(16000);
  await expect(logNav).toContainText('/ 151 hoạt động');
  await page.getByRole('button', { name: 'Trang đầu' }).click();
  await expect(items.first()).toContainText('Realtime event');
  await page.clock.runFor(16000);
  await expect(logNav).toContainText('/ 151 hoạt động');
  expect(headRequests).toBeGreaterThanOrEqual(3);
  unavailable = true;
  await page.clock.runFor(16000);
  await expect(page.getByRole('alert')).toContainText('GitHub unavailable in QA');
  await expect(items).toHaveCount(15);
  unavailable = false;
  await page.getByRole('button', { name: 'Thử đồng bộ lại' }).click();
  await expect(page.getByRole('alert')).toHaveCount(0);
  await page.getByRole('button', { name: /Danh sách Pipelines/ }).click();
  await expect(rows.first()).toContainText('Realtime pipeline');
  await nav.scrollIntoViewIfNeeded();
  await page.screenshot({ path: 'test-results/cicd-history-pagination.png' });
  failOlderPages = true;
  await page.getByRole('button', { name: 'Làm mới', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Older GitHub page unavailable in QA');
  await expect(nav).toContainText('/ 151 pipelines');
  await expect(rows).toHaveCount(15);
  failOlderPages = false;
  await page.getByRole('button', { name: 'Thử đồng bộ lại' }).click();
  await expect(page.getByRole('alert')).toHaveCount(0);
});

