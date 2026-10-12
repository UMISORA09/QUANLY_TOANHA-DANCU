import test from 'node:test';
import assert from 'node:assert/strict';
import { deploymentUrl } from '../../scripts/resolve-vercel-deployment.mjs';

test('accepts only a READY deployment of the exact commit on Vercel', () => {
  const ready = { meta: { githubCommitSha: 'expected' }, readyState: 'READY', url: 'app-123.vercel.app' };
  assert.equal(deploymentUrl([ready], 'expected'), 'https://app-123.vercel.app');
  assert.equal(deploymentUrl([ready], 'other'), null);
  for (const readyState of ['BUILDING', 'ERROR', 'CANCELED']) {
    assert.equal(deploymentUrl([{ ...ready, readyState }], 'expected'), null);
  }
  assert.equal(deploymentUrl([{ ...ready, readyState: 'BUILDING' }, ready], 'expected'), null);
  for (const url of ['app.vercel.app.evil.test', 'app.vercel.app/path', 'app.vercel.app\n', 'localhost']) {
    assert.equal(deploymentUrl([{ ...ready, url }], 'expected'), null);
  }
});
