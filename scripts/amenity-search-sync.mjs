import { pathToFileURL } from 'node:url';

const databaseErrors = new Set([
  'connection_lost', 'connection_unavailable', 'authentication_failed',
  'database_missing', 'schema_missing', 'tls_failed', 'database_operation_failed',
  'database_inspection_failed',
]);
const requiredTables = ['amenities', 'amenity_bookings', 'amenity_booking_payments', 'jobs', 'failed_jobs'];
const failureCodes = ['algolia_task_pending', 'algolia_write_failed', 'algolia_task_status_failed', 'worker_failed'];

async function requestJson(baseUrl, path, secret, label, timeout, method = 'GET') {
  let response;
  try {
    response = await fetch(`${baseUrl}${path}`, {
      method,
      headers: secret ? { Authorization: `Bearer ${secret}`, Accept: 'application/json' } : { Accept: 'application/json' },
      redirect: 'error',
      cache: 'no-store',
      signal: AbortSignal.timeout(timeout),
    });
  } catch {
    throw new Error(`${label}: network_error_or_timeout`);
  }

  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error(`${label}: HTTP ${response.status}, invalid_json`);
  }
  if (!response.ok) {
    const code = databaseErrors.has(data?.error_code) ? data.error_code : 'request_failed';
    throw new Error(`${label}: HTTP ${response.status}, ${code}`);
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new Error(`${label}: invalid_response`);
  }
  return data;
}

export async function runAmenitySearchSync({ baseUrl, secret, reindex = false }) {
  let url;
  try {
    url = new URL(baseUrl);
  } catch {
    throw new Error('Invalid Production URL');
  }
  if (url.protocol !== 'https:' || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw new Error('Production URL must be an HTTPS origin');
  }
  if (typeof secret !== 'string' || secret.length < 32) {
    throw new Error('Amenity search synchronization secret is missing or invalid');
  }
  baseUrl = url.origin;

  const health = await requestJson(baseUrl, '/health', null, 'Health preflight', 15_000);
  if (health.status !== 'healthy' || health.database !== 'healthy') {
    throw new Error('Health preflight: database_unhealthy');
  }
  const schema = await requestJson(baseUrl, '/api/v1/internal/amenity-search/process?inspect=schema', secret, 'Schema preflight', 25_000);
  if (requiredTables.some(table => schema.tables?.[table] !== true)) {
    throw new Error('Schema preflight: schema_missing');
  }

  if (reindex) {
    await requestJson(baseUrl, '/api/v1/internal/amenity-search/reindex', secret, 'Reindex', 65_000, 'POST');
  }

  let pending = 0;
  for (let batch = 0; batch < 45; batch++) {
    // A lost response may already have processed a job; only the next scheduled run resumes work.
    const settled = await Promise.allSettled(Array.from({ length: 3 }, () =>
      requestJson(baseUrl, '/api/v1/internal/amenity-search/process', secret, 'Worker', 55_000)));
    const rejected = settled.find(result => result.status === 'rejected');
    if (rejected) {
      throw rejected.reason;
    }
    const results = settled.map(result => result.value);
    if (results.some(result => !Number.isSafeInteger(result.pending) || result.pending < 0
      || !Number.isSafeInteger(result.failed) || result.failed < 0)) {
      throw new Error('Worker: invalid_response');
    }
    if (results.some(result => result.failed > 0)) {
      const codes = failureCodes.filter(code => results.some(result => Number.isSafeInteger(result.failure_codes?.[code]) && result.failure_codes[code] > 0));
      throw new Error(`Worker: failed_jobs (${codes.join(',') || 'worker_failed'})`);
    }
    pending = Math.max(...results.map(result => result.pending));
    if (pending === 0) {
      return { status: 'completed', pending: 0, failed: 0 };
    }
    if (batch < 44) {
      // Leave room for schema inspection and reindex within the endpoint's 30 requests/minute limit.
      await new Promise(resolve => setTimeout(resolve, 7_000));
    }
  }
  return { status: 'deferred', pending, failed: 0 };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    console.log(JSON.stringify(await runAmenitySearchSync({
      baseUrl: process.env.BASE_URL,
      secret: process.env.SYNC_SECRET,
      reindex: process.env.REINDEX === 'true',
    })));
  } catch (error) {
    console.error(`::error::${error.message}`);
    process.exitCode = 1;
  }
}
