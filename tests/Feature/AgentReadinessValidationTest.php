<?php

namespace Tests\Feature;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AgentReadinessValidationTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->cacheDirectory = sys_get_temp_dir().'/cassavas-agent-'.bin2hex(random_bytes(8));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array', 'cache.default' => 'array',
            'public_content.rate_limit_store' => 'array', 'public_content.rate_limit' => 60, 'public_content.rate_limit_window' => 60,
            'cache.stores.file.path' => $this->cacheDirectory, 'cache.stores.file.lock_path' => $this->cacheDirectory,
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::listen(fn () => $this->fail('Public documentation must not query the database.'));
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory)) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    public function test_developer_resource_and_brand_discoverability(): void
    {
        $this->assertStringContainsString('Allow: /api/v1/public/', file_get_contents(public_path('robots.txt')));
        $links = ['/docs', '/mcp', '/cli', 'https://github.com/UMISORA09/QUANLY_TOANHA-DANCU'];
        foreach ([$this->get('/home')->assertOk()->getContent(), file_get_contents(public_path('index.html'))] as $html) {
            $this->assertStringContainsString('name="keywords"', $html);
            foreach ($links as $link) {
                $this->assertStringContainsString('href="'.$link.'"', $html);
            }
            $this->assertStringContainsString('knowsAbout', $html);
            $this->assertStringContainsString('serviceType', $html);
        }
        foreach (['docs' => 'SMART CASSAVAS API', 'mcp' => 'SMART CASSAVAS MCP Server', 'cli' => 'SMART CASSAVAS CLI'] as $page => $title) {
            $response = $this->get('/'.$page)->assertOk();
            $this->assertStringContainsString('<title>'.$title, $response->getContent());
            $this->get('/'.$page.'.md')->assertOk()->assertSee($title);
            $this->assertStringContainsString('/'.$page, file_get_contents(public_path('llms.txt')));
            $this->get('/sitemap.xml')->assertOk()->assertSee('/'.$page, false);
        }
    }

    public function test_cli_execution_and_output_formats(): void
    {
        $script = <<<'JS'
const assert = require('node:assert/strict');
const http = require('node:http');
const { promisify } = require('node:util');
const exec = promisify(require('node:child_process').execFile);
const cli = process.cwd() + '/bin/smart-cassavas.cjs';
let requests = 0;
let failure = '';
const server = http.createServer((req, res) => {
  requests++;
  if (failure === 'status') { res.writeHead(429, {'Retry-After': '30'}); return res.end(JSON.stringify({message: 'QA quota exceeded'})); }
  if (failure === 'json') return res.end('invalid JSON');
  res.setHeader('Content-Type', 'application/json');
  if (req.url === '/api/v1/public/cli') return res.end(JSON.stringify({name: 'SMART CASSAVAS CLI', version: '1.0.0', pages: ['home', 'cli']}));
  if (req.url === '/.well-known/mcp') return res.end(JSON.stringify({name: 'QA MCP', url: 'https://example.test/mcp/public', transport: 'streamable-http'}));
  return res.end(JSON.stringify({page: 'home', markdown: '# QA documentation', title: 'QA', updated_at: '2026-11-10'}));
});
server.listen(0, '127.0.0.1', async () => {
  const base = 'http://127.0.0.1:' + server.address().port;
  const run = (...args) => exec(process.execPath, [cli, ...args, '--base-url', base], {timeout: 12000});
  try {
    assert.match((await run('--help')).stdout, /docs.*status.*mcp-info/s);
    assert.match((await run('--version')).stdout.trim(), /^1\.0\.0$/);
    assert.equal(requests, 0);
    assert.equal(JSON.parse((await run('status', '--json')).stdout).status, 'ok');
    assert.equal(JSON.parse((await run('docs', 'home', '--json')).stdout).page, 'home');
    assert.match((await run('docs', 'home')).stdout, /# QA documentation/);
    assert.equal(JSON.parse((await run('mcp-info', '--json')).stdout).transport, 'streamable-http');
    const before = requests;
    await assert.rejects(run('unknown'), error => error.code === 1 && /unknown|không hợp lệ/i.test(error.stderr));
    await assert.rejects(run('docs', '../../.env'), error => error.code === 1);
    assert.equal(requests, before);
    failure = 'status';
    await assert.rejects(run('status'), error => error.code === 1 && /429/.test(error.stderr) && /30/.test(error.stderr));
    failure = 'json';
    await assert.rejects(run('status'), error => error.code === 1 && /JSON/i.test(error.stderr));
    console.log('CLI checks passed');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
  finally { server.close(); }
});
JS;
        $process = new Process(['node', '-e', $script], base_path());
        $process->setTimeout(45)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('CLI checks passed', $process->getOutput());
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('./bin/smart-cassavas.cjs', $package['bin']['smart-cassavas']);
        $this->assertTrue($package['private']);
    }

    public function test_organization_schema_completeness(): void
    {
        foreach ([$this->get('/home')->assertOk()->getContent(), file_get_contents(public_path('index.html'))] as $html) {
            $this->assertStringContainsString('Organization', $html);
            foreach (['support@smartcassavas.vn', '+84-28-7300-8888', 'Tòa nhà SMART CASSAVAS, Khu đô thị mới'] as $example) {
                $this->assertStringNotContainsString($example, $html);
            }
        }
        $this->markTestIncomplete('ContactPoint/PostalAddress require verified public data; user requested no sample information.');
    }

    public function test_standard_rate_limit_headers(): void
    {
        config(['public_content.rate_limit' => 2]);
        $first = $this->getJson('/api/v1/public/guides/home')->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1')->assertHeader('X-RateLimit-Remaining', '1');
        $this->assertMatchesRegularExpression('/^"public-guide";q=2;w=60$/', $first->headers->get('RateLimit-Policy'));
        $this->assertGreaterThan(0, (int) $first->headers->get('RateLimit-Reset'));
        $this->assertGreaterThan(time() - 1, (int) $first->headers->get('X-RateLimit-Reset'));
        $this->getJson('/api/v1/public/cli')->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $this->getJson('/api/v1/public/guides/home')->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('RateLimit-Remaining', '0')->assertJsonPath('code', 'rate_limit_exceeded');
    }

    public function test_database_cache_falls_back_without_querying_database_and_preserves_quota(): void
    {
        config(['public_content.rate_limit_store' => 'database', 'public_content.rate_limit' => 1]);
        $this->getJson('/api/v1/public/guides/home')->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $this->getJson('/api/v1/public/guides/home')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_file_cache_without_lock_support_fails_closed(): void
    {
        config(['public_content.rate_limit_store' => 'file']);
        $repository = new Repository(\Mockery::mock(Store::class));
        Cache::shouldReceive('store')->once()->with('file')->andReturn($repository);
        $this->getJson('/api/v1/public/cli')->assertStatus(503)->assertJsonPath('code', 'public_cache_unavailable')->assertHeader('Retry-After');
    }

    public function test_unusable_cache_fails_safely_instead_of_disabling_rate_limits(): void
    {
        config(['public_content.rate_limit_store' => 'missing', 'cache.stores.file.path' => __FILE__]);
        $this->getJson('/api/v1/public/guides/home')->assertStatus(503)->assertHeader('Retry-After')->assertJsonPath('code', 'public_cache_unavailable');
    }

    public function test_openapi_response_schemas_and_function_calling(): void
    {
        $spec = json_decode(file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $spec['openapi']);
        $ids = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $operation) {
                $this->assertNotEmpty($operation['description'] ?? null, $path);
                $this->assertNotContains($operation['operationId'], $ids);
                $ids[] = $operation['operationId'];
                $functionParameters = ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $functionParameters['properties'][$parameter['name']] = $parameter['schema'];
                    if ($parameter['required']) {
                        $functionParameters['required'][] = $parameter['name'];
                    }
                    $this->assertArrayHasKey('type', $parameter['schema']);
                    $this->assertNotEmpty($parameter['description']);
                }
                $this->assertSame(array_keys($functionParameters['properties']), $functionParameters['required']);
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $operation['operationId']);
                foreach ($operation['responses'] as $response) {
                    if (isset($response['$ref'])) {
                        $response = $spec['components']['responses'][basename($response['$ref'])];
                    }
                    foreach ($response['content'] as $media => $definition) {
                        $schema = $definition['schema'];
                        if (isset($schema['$ref'])) {
                            $schema = $spec['components']['schemas'][basename($schema['$ref'])];
                        }
                        $this->assertArrayHasKey('type', $schema, $path);
                        if ($media === 'application/json') {
                            $this->assertSame('object', $schema['type']);
                            $this->assertArrayHasKey('properties', $schema);
                            $this->assertArrayHasKey('required', $schema);
                            $this->assertFalse($schema['additionalProperties']);
                        }
                    }
                }
            }
        }
        foreach (['/api/v1/public/guides/home' => 'Guide', '/api/v1/public/cli' => 'CliToolInfo', '/.well-known/mcp' => 'McpDiscovery', '/api/v1/public/guides/unknown' => 'Error'] as $path => $schemaName) {
            $response = $this->getJson($path)->assertStatus($schemaName === 'Error' ? 404 : 200)->assertHeader('Content-Type', 'application/json');
            $this->assertContractValue($response->json(), $spec['components']['schemas'][$schemaName]);
        }
        $this->getJson('/api/v1/public/guides/cli')->assertOk()->assertJsonStructure(['page', 'title', 'markdown', 'updated_at']);
        $this->getJson('/api/v1/public/cli')->assertOk()->assertJsonStructure(['name', 'version', 'command', 'description', 'installation', 'commands', 'pages']);
    }

    /** @param array<string, mixed> $schema */
    private function assertContractValue(mixed $value, array $schema): void
    {
        switch ($schema['type']) {
            case 'object':
                $this->assertIsArray($value);
                foreach ($schema['required'] as $key) {
                    $this->assertArrayHasKey($key, $value);
                }
                $this->assertSame([], array_values(array_diff(array_keys($value), array_keys($schema['properties']))));
                foreach ($value as $key => $item) {
                    $this->assertContractValue($item, $schema['properties'][$key]);
                }
                break;
            case 'array':
                $this->assertIsArray($value);
                $this->assertTrue(array_is_list($value));
                foreach ($value as $item) {
                    $this->assertContractValue($item, $schema['items']);
                }
                break;
            case 'string':
                $this->assertIsString($value);
                if (isset($schema['enum'])) {
                    $this->assertContains($value, $schema['enum']);
                }
                break;
            case 'null':
                $this->assertNull($value);
                break;
            default:
                $this->fail('Unhandled public contract type: '.$schema['type']);
        }
    }
}
