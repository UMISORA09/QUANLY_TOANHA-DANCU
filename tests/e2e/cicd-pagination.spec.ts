import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import type { PipelineItem } from '../../resources/js/Services/cicdApi';

test('pipeline pages contain at most 15 rows and reset after filtering', async ({ page }) => {
  let pipelines: PipelineItem[] = Array.from({ length: 32 }, (_, index) => ({
    id: String(index + 1), run_number: index + 1, name: `Pipeline ${index + 1}`,
    workflow_file: 'ci.yml', status: 'success', branch: index < 20 ? 'master' : 'feature/test',
    commit_sha: 'abc1234', commit_message: `Commit ${index + 1}`, author: 'QA',
    trigger: 'push', duration: '1m', created_at: '2026-10-11T10:00:00Z',
  }));
  await page.addInitScript(() => {
    localStorage.setItem('smartcassavas_session', JSON.stringify({ role: 'admin', name: 'QA', email: 'qa@example.test' }));
    localStorage.setItem('smart_cassavas_token', 'qa-token');
  });
  await page.route('**/api/**', route => route.fulfill({ json: { data: {} } }));
  await page.route('**/api/admin/cicd/bundle?*', route => route.fulfill({ json: { data: {
    overview: { total_pipelines: pipelines.length, success_count: pipelines.length, failed_count: 0, running_count: 0,
      success_rate: 100, latest_pipeline: pipelines[0], production_status: 'healthy', production_version: 'prod-abc1234',
      staging_status: 'not_configured', staging_version: 'N/A', system_health: 'unknown', is_live_github: true },
    pipelines, deployments: [{ id: 'deploy-1', environment: 'production', version: 'prod-abc1234', image_tag: '',
      commit_sha: 'abc1234', status: 'healthy', deployed_by: 'QA', deployed_at: '2026-10-11T10:00:00Z',
      response_time_ms: 0, release_notes: 'Actual deployment' }], environments: [], health: null,
    activities: [], branches: ['master', 'feature/test'],
  } } }));
  const appHtml = await readFile('public/index.html', 'utf8');
  await page.route('**/admin/cicd', route => route.fulfill({ contentType: 'text/html', body: appHtml }));
  await page.goto('/admin/cicd');
  await expect(page.getByText('Trạng thái workflow từ GitHub')).toBeVisible();
  await expect(page.getByText('26/26 tests passed', { exact: false })).toHaveCount(0);
  await page.getByRole('button', { name: /Triển khai & Bản phát hành/ }).click();
  await expect(page.getByText('Actual deployment')).toBeVisible();
  await expect(page.getByText('61bc9af', { exact: false })).toHaveCount(0);
  await page.getByRole('button', { name: /Danh sách Pipelines/ }).click();
  const rows = page.locator('table tbody tr');
  const pagination = page.getByRole('navigation', { name: 'Phân trang pipelines' });
  await expect(rows).toHaveCount(15);
  await expect(pagination.getByRole('button', { name: 'Trước', exact: true })).toBeDisabled();
  await pagination.getByRole('button', { name: 'Sau', exact: true }).click();
  await expect(rows).toHaveCount(15);
  await expect(rows.first()).toContainText('Pipeline 16');
  await pagination.getByRole('button', { name: 'Sau', exact: true }).click();
  await expect(rows).toHaveCount(2);
  await expect(pagination.getByRole('button', { name: 'Sau', exact: true })).toBeDisabled();
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
});

