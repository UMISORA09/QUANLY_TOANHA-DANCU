import { pathToFileURL } from 'node:url';

export function deploymentUrl(deployments, expectedSha) {
  const deployment = deployments.find(item => item.meta?.githubCommitSha === expectedSha);
  const host = deployment?.url;
  if (deployment?.readyState !== 'READY' || typeof host !== 'string' || host.trim() !== host || !/^[a-z0-9-]+\.vercel\.app$/i.test(host)) return null;
  return `https://${host}`;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const { VERCEL_TOKEN, VERCEL_PROJECT_ID, VERCEL_ORG_ID, EXPECTED_SHA } = process.env;
    if (!VERCEL_TOKEN || !VERCEL_PROJECT_ID || !VERCEL_ORG_ID || !EXPECTED_SHA) throw new Error('Missing configuration');
    const query = new URLSearchParams({ projectId: VERCEL_PROJECT_ID, teamId: VERCEL_ORG_ID, limit: '100' });
    const response = await fetch(`https://api.vercel.com/v6/deployments?${query}`, {
      headers: { Authorization: `Bearer ${VERCEL_TOKEN}` }, signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) throw new Error('Vercel unavailable');
    const data = await response.json();
    // shortcut: inspect 100 newest deployments; paginate if a release falls behind 100 newer deployments.
    const url = deploymentUrl(data.deployments, EXPECTED_SHA);
    if (url) process.stdout.write(url);
  } catch {
    process.stderr.write('Vercel deployment lookup unavailable.\n');
    process.exitCode = 1;
  }
}
