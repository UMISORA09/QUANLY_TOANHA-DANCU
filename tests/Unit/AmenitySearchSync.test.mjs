import test, { afterEach, mock } from 'node:test';
import assert from 'node:assert/strict';
import { runAmenitySearchSync } from '../../scripts/amenity-search-sync.mjs';

const secret = 'private-test-secret-must-never-appear-in-errors';
const options = { baseUrl: 'https://production.example', secret };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status });
const tables = { amenities: true, amenity_bookings: true, amenity_booking_payments: true, jobs: true, failed_jobs: true };

afterEach(() => mock.restoreAll());

function fixture({ health, schema, worker, reindex } = {}) {
  const calls = [];
  mock.method(globalThis, 'fetch', async (url, init) => {
    calls.push({ url, init });
    const parsed = new URL(url);
    if (parsed.pathname === '/health') {
      return health ? health() : json({ status: 'healthy', database: 'healthy' });
    }
    if (parsed.search === '?inspect=schema') {
      return schema ? schema() : json({ tables });
    }
    if (parsed.pathname.endsWith('/reindex')) {
      return reindex ? reindex() : json({ pending: 1 }, 202);
    }
    return worker ? worker() : json({ pending: 0, failed: 0 });
  });
  return calls;
}

test('checks readiness before processing, authenticates only internal APIs and never reindexes by default', async () => {
  const calls = fixture();
  assert.deepEqual(await runAmenitySearchSync(options), { status: 'completed', pending: 0, failed: 0 });
  assert.equal(calls.length, 5);
  assert.equal(calls[0].init.headers.Authorization, undefined);
  assert.match(calls[1].url, /process\?inspect=schema$/);
  assert.equal(calls[1].init.headers.Authorization, `Bearer ${secret}`);
  assert.ok(calls.every(call => call.init.method === 'GET' && call.init.redirect === 'error'));
  assert.equal(calls.filter(call => call.url.endsWith('/process')).length, 3);
});

test('HTTP failures, invalid JSON and unhealthy databases stop before any worker or reindex', async () => {
  for (const health of [
    () => json({ error_code: secret }, 401),
    () => json({ error_code: 'connection_lost', password: secret }, 503),
    () => new Response(secret, { status: 500 }),
    () => new Response(secret),
    () => json({ status: 'healthy', database: 'unhealthy' }),
    () => { throw new DOMException(secret, 'TimeoutError'); },
  ]) {
    const calls = fixture({ health });
    await assert.rejects(runAmenitySearchSync({ ...options, reindex: true }), error => {
      assert.ok(!error.message.includes(secret));
      assert.match(error.message, /Health preflight/);
      return true;
    });
    assert.equal(calls.length, 1);
    mock.restoreAll();
  }
});

test('missing schema and invalid machine authentication stop before side effects', async () => {
  for (const schema of [
    () => json({ tables: { ...tables, jobs: false } }),
    () => json({ tables: { ...tables, failed_jobs: 'true' } }),
    () => json({ password: secret }, 401),
    () => json({ error_code: 'tls_failed', password: secret }, 503),
  ]) {
    const calls = fixture({ schema });
    await assert.rejects(runAmenitySearchSync({ ...options, reindex: true }), error => {
      assert.match(error.message, /Schema preflight/);
      assert.ok(!error.message.includes(secret));
      return true;
    });
    assert.equal(calls.length, 2);
    mock.restoreAll();
  }
});

test('does not retry a worker after an HTTP error, a lost response or invalid JSON', async () => {
  for (const worker of [
    () => json({ error_code: 'connection_lost', password: secret }, 503),
    () => new Response(secret, { status: 500 }),
    () => json({ exception: secret }, 429),
    () => { throw new Error(secret); },
    () => new Response(secret),
  ]) {
    const calls = fixture({ worker });
    await assert.rejects(runAmenitySearchSync(options), error => {
      assert.match(error.message, /Worker/);
      assert.ok(!error.message.includes(secret));
      return true;
    });
    assert.equal(calls.length, 5);
    assert.equal(calls.filter(call => call.url.endsWith('/process')).length, 3);
    mock.restoreAll();
  }
});

test('waits for all dispatched workers before reporting one failed response', async () => {
  let completed = 0;
  let started = 0;
  const calls = fixture({ worker: async () => {
    const index = started++;
    if (index === 0) {
      throw new Error(secret);
    }
    await new Promise(resolve => setTimeout(resolve, 5));
    completed++;
    return json({ pending: 0, failed: 0 });
  } });
  await assert.rejects(runAmenitySearchSync(options), /network_error_or_timeout/);
  assert.equal(completed, 2);
  assert.equal(calls.length, 5);
});

test('rejects invalid queue counts and exposes only known failure codes', async () => {
  for (const response of [
    { pending: -1, failed: 0 },
    { pending: '0', failed: 0 },
    { pending: 0, failed: 1, failure_codes: { algolia_write_failed: 1, [secret]: 1 } },
  ]) {
    fixture({ worker: () => json(response) });
    await assert.rejects(runAmenitySearchSync(options), error => {
      assert.ok(!error.message.includes(secret));
      assert.match(error.message, /invalid_response|failed_jobs \(algolia_write_failed\)/);
      return true;
    });
    mock.restoreAll();
  }
});

test('reindexes only when requested and only once after readiness succeeds', async () => {
  const calls = fixture();
  await runAmenitySearchSync({ ...options, reindex: true });
  assert.equal(calls.length, 6);
  assert.ok(calls[2].url.endsWith('/reindex'));
  assert.equal(calls[2].init.method, 'POST');
  assert.equal(calls.filter(call => call.init.method === 'POST').length, 1);
});

test('paces pending batches below the shared machine endpoint rate limit', async () => {
  let processed = 0;
  const pauses = [];
  const originalTimeout = globalThis.setTimeout;
  mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
    pauses.push(milliseconds);
    return originalTimeout(callback, 0);
  });
  const calls = fixture({ worker: () => json({ pending: processed++ < 3 ? 1 : 0, failed: 0 }) });
  assert.deepEqual(await runAmenitySearchSync(options), { status: 'completed', pending: 0, failed: 0 });
  assert.equal(calls.filter(call => call.url.endsWith('/process')).length, 6);
  assert.equal(pauses.length, 1);
  assert.ok(Math.ceil(60_000 / pauses[0]) * 3 + 2 <= 30);
});

test('a lost reindex response is not retried and no workers are dispatched', async () => {
  const calls = fixture({ reindex: () => { throw new Error(secret); } });
  await assert.rejects(runAmenitySearchSync({ ...options, reindex: true }), /Reindex: network_error_or_timeout/);
  assert.equal(calls.length, 3);
});

test('rejects invalid URL and secret configuration without any requests', async () => {
  const calls = fixture();
  for (const baseUrl of ['bad', 'http://production.example', 'https://user:password@production.example', 'https://production.example/path', 'https://production.example?secret=value']) {
    await assert.rejects(runAmenitySearchSync({ ...options, baseUrl }), /Production URL/);
  }
  await assert.rejects(runAmenitySearchSync({ ...options, secret: '' }), /secret is missing or invalid/);
  assert.equal(calls.length, 0);
});
