import { pathToFileURL } from 'node:url';

export function deploymentUrl(deployments, expectedSha, environment = 'Preview') {
  const deployment = deployments.find(item => item.meta?.githubCommitSha === expectedSha &&
    (environment === 'Production' ? item.target === 'production' : item.target == null || item.target === 'preview'));
  const host = deployment?.url;
  const state = deployment?.state ?? deployment?.readyState;
  if (['ERROR', 'CANCELED', 'BLOCKED'].includes(state)) throw new Error('Deployment failed');
  if (state !== 'READY' || typeof host !== 'string' || host.trim() !== host || !/^[a-z0-9-]+\.vercel\.app$/i.test(host)) return null;
  return `https://${host}`;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  let failure = 'Vercel network request failed';
  let exitCode = 1;
  try {
    const { VERCEL_TOKEN, VERCEL_PROJECT_ID, VERCEL_ORG_ID, EXPECTED_SHA, EXPECTED_ENVIRONMENT = 'Preview' } = process.env;
    const missing = Object.entries({ VERCEL_TOKEN, VERCEL_PROJECT_ID, VERCEL_ORG_ID, EXPECTED_SHA })
      .filter(([, value]) => !value?.trim() || value.trim() === 'not_configured').map(([name]) => name);
    if (missing.length) {
      failure = `Vercel lookup configuration missing: ${missing.join(', ')}`;
      exitCode = 3;
      throw new Error();
    }
    const query = new URLSearchParams({ projectId: VERCEL_PROJECT_ID, teamId: VERCEL_ORG_ID, sha: EXPECTED_SHA, limit: '100' });
    if (EXPECTED_ENVIRONMENT === 'Production') query.set('target', 'production');
    const response = await fetch(`https://api.vercel.com/v7/deployments?${query}`, {
      headers: { Authorization: `Bearer ${VERCEL_TOKEN}` }, signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) {
      failure = `Vercel API HTTP ${response.status}`;
      if ([401, 403].includes(response.status)) failure += ': check token permissions and team/project access';
      if (response.status === 404) failure += ': check team/project configuration';
      if (response.status === 429) failure += ': rate limited; retrying';
      if ([400, 401, 403, 404].includes(response.status)) exitCode = 3;
      throw new Error();
    }
    failure = 'Vercel API returned an invalid response';
    const data = await response.json();
    if (!Array.isArray(data.deployments)) throw new Error();
    const url = deploymentUrl(data.deployments, EXPECTED_SHA, EXPECTED_ENVIRONMENT);
    if (url) process.stdout.write(url);
  } catch (error) {
    if (error.name === 'TimeoutError' || error.name === 'AbortError') failure = 'Vercel API request timed out';
    if (error.message === 'Deployment failed') {
      failure = 'Vercel deployment failed for the expected commit and environment';
      exitCode = 2;
    }
    process.stderr.write(`${failure}.\n`);
    process.exitCode = exitCode;
  }
}
