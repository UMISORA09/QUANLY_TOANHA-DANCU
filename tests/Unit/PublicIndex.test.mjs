import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { execFileSync } from 'node:child_process';

test('build keeps meaningful fallback content and correct metadata in the static entry', () => {
  execFileSync(process.execPath, ['scripts/sync-index-html.js']);
  const html = readFileSync('public/index.html', 'utf8');
  const main = html.match(/<main[^>]*>([\s\S]*?)<\/main>/)[1];
  assert.ok(main.replace(/<[^>]*>/g, '').length > 500);
  assert.equal((main.match(/<h1>/g) || []).length, 1);
  assert.ok(html.includes('rel="canonical" href="https://quanly-toanha-dancu.vercel.app/home"'));
  assert.ok(html.includes('property="og:image"'));
  const data = JSON.parse(html.match(/<script type="application\/ld\+json">(.*?)<\/script>/)[1]);
  assert.equal(data.name, 'SMART CASSAVAS');
  assert.equal(data['@type'], 'SoftwareApplication');
  assert.ok(main.includes('Việc đặt chỗ thành công không đồng nghĩa với đã thanh toán.'));
  const routes = JSON.parse(readFileSync('vercel.json', 'utf8')).routes;
  const homepageRoute = new RegExp(routes[0].src);
  assert.ok(homepageRoute.test('/'));
  assert.ok(homepageRoute.test('/index.html'));
  assert.ok(!homepageRoute.test('/api/v1/auth/login'));
  assert.ok(!homepageRoute.test('/build/assets/app.js'));
});
