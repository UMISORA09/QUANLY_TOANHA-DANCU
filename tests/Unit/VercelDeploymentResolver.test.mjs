import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { deploymentUrl } from '../../scripts/resolve-vercel-deployment.mjs';

test('accepts only a READY deployment of the exact commit on Vercel', () => {
  const ready = { meta: { githubCommitSha: 'expected' }, readyState: 'READY', url: 'app-123.vercel.app' };
  assert.equal(deploymentUrl([ready], 'expected'), 'https://app-123.vercel.app');
  assert.equal(deploymentUrl([ready], 'other'), null);
  for (const readyState of ['BUILDING', 'QUEUED', 'INITIALIZING']) {
    assert.equal(deploymentUrl([{ ...ready, readyState }], 'expected'), null);
  }
  for (const readyState of ['ERROR', 'CANCELED', 'BLOCKED']) {
    assert.throws(() => deploymentUrl([{ ...ready, readyState }], 'expected'), /Deployment failed/);
  }
  assert.equal(deploymentUrl([{ ...ready, readyState: 'BUILDING' }, ready], 'expected'), null);
  for (const url of ['app.vercel.app.evil.test', 'app.vercel.app/path', 'app.vercel.app\n', 'localhost']) {
    assert.equal(deploymentUrl([{ ...ready, url }], 'expected'), null);
  }
});

test('requires the exact environment and accepts the v7 state field', () => {
  const preview = { meta: { githubCommitSha: 'expected' }, state: 'ERROR', target: null, url: 'preview.vercel.app' };
  const production = { ...preview, state: 'READY', target: 'production', url: 'production.vercel.app' };
  assert.equal(deploymentUrl([preview, production], 'expected', 'Production'), 'https://production.vercel.app');
  assert.equal(deploymentUrl([production], 'expected', 'Preview'), null);
  assert.equal(deploymentUrl([preview], 'expected', 'Production'), null);
  assert.equal(deploymentUrl([{ ...production, state: 'BUILDING' }, production], 'expected', 'Production'), null);
});

function lookup(fetchBody, overrides = {}) {
  const scriptUrl = new URL('../../scripts/resolve-vercel-deployment.mjs', import.meta.url);
  return spawnSync(process.execPath, ['--input-type=module', '--eval', `
    process.argv[1] = ${JSON.stringify(fileURLToPath(scriptUrl))};
    globalThis.fetch = async (url, options) => { ${fetchBody} };
    await import(${JSON.stringify(scriptUrl.href)});
  `], { encoding: 'utf8', env: { ...process.env, VERCEL_TOKEN: 'test-sensitive-token', VERCEL_PROJECT_ID: 'test-sensitive-project', VERCEL_ORG_ID: 'test-sensitive-team', EXPECTED_SHA: 'expected', EXPECTED_ENVIRONMENT: 'Production', ...overrides } });
}

test('reports HTTP errors without leaking response bodies or credentials', () => {
  for (const status of [400, 401, 403, 404, 429, 500, 503]) {
    const result = lookup(`return { ok: false, status: ${status}, json: async () => ({ message: 'test-sensitive-token' }) };`);
    assert.equal(result.status, [400, 401, 403, 404].includes(status) ? 3 : 1);
    assert.match(result.stderr, new RegExp(`HTTP ${status}`));
    assert.equal(result.stdout, '');
    assert.doesNotMatch(result.stderr, /test-sensitive/);
  }
});

test('filters the API by SHA and environment, then validates the returned deployment', () => {
  const result = lookup(`
    const query = new URL(url);
    if (query.pathname !== '/v7/deployments' || query.searchParams.get('sha') !== 'expected' || query.searchParams.get('target') !== 'production' || options.headers.Authorization !== 'Bearer test-sensitive-token') throw new Error('Wrong query');
    return { ok: true, json: async () => ({ deployments: [{ meta: { githubCommitSha: 'expected' }, target: 'production', state: 'READY', url: 'app.vercel.app' }] }) };
  `);
  assert.equal(result.status, 0);
  assert.equal(result.stdout, 'https://app.vercel.app');
  assert.equal(result.stderr, '');
  const wrong = lookup(`return { ok: true, json: async () => ({ deployments: [{ meta: { githubCommitSha: 'other' }, target: 'production', state: 'READY', url: 'app.vercel.app' }] }) };`);
  assert.equal(wrong.status, 0);
  assert.equal(wrong.stdout, '');
});

test('distinguishes configuration, network, timeout, invalid responses and deployment failure', () => {
  const missing = lookup(`throw new Error('Must not call fetch');`, { VERCEL_TOKEN: 'not_configured' });
  assert.equal(missing.status, 3);
  assert.match(missing.stderr, /configuration missing: VERCEL_TOKEN/);
  const network = lookup(`throw new TypeError('test-sensitive-token');`);
  assert.equal(network.status, 1);
  assert.match(network.stderr, /network request failed/);
  assert.doesNotMatch(network.stderr, /test-sensitive-token/);
  const timeout = lookup(`throw new DOMException('test-sensitive-token', 'TimeoutError');`);
  assert.equal(timeout.status, 1);
  assert.match(timeout.stderr, /timed out/);
  const invalid = lookup(`return { ok: true, json: async () => ({ deployments: null }) };`);
  assert.equal(invalid.status, 1);
  assert.match(invalid.stderr, /invalid response/);
  const failed = lookup(`return { ok: true, json: async () => ({ deployments: [{ meta: { githubCommitSha: 'expected' }, target: 'production', state: 'ERROR' }] }) };`);
  assert.equal(failed.status, 2);
  assert.match(failed.stderr, /deployment failed/);
});

test('workflow retries quickly, backs off after five minutes and selects the correct Production deployment', { skip: process.platform === 'win32' }, () => {
  const workflow = readFileSync(new URL('../../.github/workflows/vercel.yml', import.meta.url), 'utf8');
  const step = workflow.split('      - name: Resolve deployment from Git Integration')[1].split('      - name: Verify deployed frontend and API')[0];
  const body = step.split('        run: |\n')[1].replace(/^          /gm, '');
  const directory = mkdtempSync(join(tmpdir(), 'vercel-retry-test-'));
  const setup = `
    gh() {
      if [[ "$2" == */statuses ]]; then
        if [ "$(cat "$TEST_DIR/count")" = 2 ]; then return 1; fi
        echo '[{"state":"success","environment_url":"https://production.vercel.app"}]'
      else
        count=$(cat "$TEST_DIR/count" 2>/dev/null || echo 0)
        count=$((count + 1)); echo "$count" > "$TEST_DIR/count"
        if [ "$count" = 1 ]; then return 1; fi
        echo '[{"id":999,"environment":"Preview"},{"id":123,"environment":"Production"}]'
      fi
    }
    node() { return 3; }
    sleep() {
      echo "$1" >> "$TEST_DIR/sleeps"
      SECONDS=300
    }
  `;
  try {
    const result = spawnSync('bash', ['-e', '-c', setup + body], { encoding: 'utf8', timeout: 10000, env: {
      ...process.env, TEST_DIR: directory, REPOSITORY: 'test/repo', EXPECTED_SHA: 'expected', EXPECTED_ENVIRONMENT: 'Production',
      GITHUB_OUTPUT: join(directory, 'output'), GITHUB_STEP_SUMMARY: join(directory, 'summary'),
    } });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(readFileSync(join(directory, 'count'), 'utf8').trim(), '3');
    assert.equal(readFileSync(join(directory, 'sleeps'), 'utf8').trim(), '5\n30');
    assert.match(result.stdout, /GitHub deployment API unavailable/);
    assert.match(result.stdout, /GitHub deployment status API unavailable/);
    assert.match(result.stdout, /fallback disabled/);
    assert.equal(readFileSync(join(directory, 'output'), 'utf8').trim(), 'url=https://production.vercel.app');
    assert.match(readFileSync(join(directory, 'summary'), 'utf8'), /deployment 123 matches expected/);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
