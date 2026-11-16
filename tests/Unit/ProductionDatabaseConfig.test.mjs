import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

const workflow = readFileSync(new URL('../../.github/workflows/vercel.yml', import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const step = workflow.split('      - name: Validate Production Database Configuration')[1].split('      - name: Setup PHP for Migration')[0];
const validation = step.split('        run: |\n')[1].split('\n').map(line => line.replace(/^          /, '')).join('\n');
const bash = process.platform === 'win32' ? join(process.env.ProgramFiles, 'Git', 'bin', 'bash.exe') : 'bash';
const password = 'private-test-password';

function validate(overrides) {
  const directory = mkdtempSync(join(tmpdir(), 'production-db-config-'));
  try {
    const output = join(directory, 'output');
    const result = spawnSync(bash, ['-e', '-c', validation], {
      encoding: 'utf8',
      env: {
        ...process.env, DATABASE_URL: '', PROD_DB_HOST: 'db.example', PROD_DB_PORT: '26759',
        PROD_DB_DATABASE: 'qa', PROD_DB_USERNAME: 'qa-app', PROD_DB_PASSWORD: password,
        ...overrides, GITHUB_OUTPUT: output,
      },
    });
    assert.ifError(result.error);
    assert.ok(!`${result.stdout}${result.stderr}`.includes(password));
    return { status: result.status, output: existsSync(output) ? readFileSync(output, 'utf8') : '' };
  } finally {
    assert.equal(dirname(directory), tmpdir());
    rmSync(directory, { recursive: true, force: true });
  }
}

test('manual migration requires all database settings and a valid explicit port', () => {
  assert.equal(validate({}).status, 0);
  for (const field of ['PROD_DB_HOST', 'PROD_DB_DATABASE', 'PROD_DB_USERNAME', 'PROD_DB_PASSWORD']) {
    for (const value of ['', 'not_configured']) {
      assert.notEqual(validate({ [field]: value }).status, 0);
    }
  }
  for (const port of ['', '0', '65536', '26759;echo invalid', 'invalid']) {
    assert.notEqual(validate({ PROD_DB_PORT: port }).status, 0);
  }
  assert.match(validate({ PROD_DB_PORT: '3306' }).output, /configured=true/);
});

test('URL configuration accepts MySQL and rejects PostgreSQL without printing credentials', () => {
  assert.equal(validate({ DATABASE_URL: `mysql://qa:${password}@db.example:26759/qa`, PROD_DB_HOST: '' }).status, 0);
  assert.notEqual(validate({ DATABASE_URL: `postgres://qa:${password}@db.example/qa` }).status, 0);
});

test('the migration path validates TLS and reads migration status before executing changes', () => {
  const migration = workflow.split('      - name: Execute Incremental Migrations')[1].split('\n  deploy-vercel:')[0];
  assert.match(migration, /MYSQL_ATTR_SSL_CA: \$\{\{ github.workspace \}\}\/config\/aiven-ca.pem/);
  assert.match(migration, /test -r "\$MYSQL_ATTR_SSL_CA"/);
  assert.ok(migration.indexOf('php artisan migrate:status') < migration.indexOf('php artisan migrate --force'));
  assert.match(workflow, /github.event_name == 'workflow_dispatch' && github.event.inputs.target_env == 'production' && github.ref == 'refs\/heads\/master'/);
});
