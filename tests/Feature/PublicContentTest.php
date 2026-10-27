<?php

namespace Tests\Feature;

use App\Services\PublicContent;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class PublicContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array', 'cache.default' => 'array',
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::listen(fn () => $this->fail('Public guides must not query the database.'));
    }

    public function test_public_pages_are_meaningful_html_with_correct_identity_and_markdown(): void
    {
        foreach (array_merge(['/'], array_map(fn ($page) => '/'.$page, PublicContent::PAGES)) as $path) {
            $response = $this->get($path, ['Accept' => 'text/html'])->assertOk()->assertHeader('Vary', 'Accept');
            $html = $response->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//h1')->length, $path);
            $this->assertGreaterThanOrEqual(500, mb_strlen($xpath->query('//main')->item(0)->textContent));
            $this->assertSame('vi', $document->documentElement->getAttribute('lang'));
            $canonical = $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href');
            $this->assertStringStartsWith(config('public_content.url'), $canonical);
            $this->assertSame('website', $xpath->query('//meta[@property="og:type"]')->item(0)->getAttribute('content'));
            $this->assertStringEndsWith('/social-card.png', $xpath->query('//meta[@property="og:image"]')->item(0)->getAttribute('content'));
            $schema = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('SoftwareApplication', $schema['@type']);
            $this->assertSame('SMART CASSAVAS', $schema['name']);
            $this->get($path, ['Accept' => 'text/markdown'])->assertOk()->assertHeader('Vary', 'Accept')
                ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertSee('SMART CASSAVAS');
        }
    }

    #[DataProvider('acceptHeaders')]
    public function test_accept_quality_values_are_honored(string $accept, int $status, string $type): void
    {
        $this->get('/', ['Accept' => $accept])->assertStatus($status)->assertHeader('Vary', 'Accept')->assertHeader('Content-Type', $type);
    }

    public static function acceptHeaders(): array
    {
        return [
            ['*/*', 200, 'text/html; charset=utf-8'],
            ['text/markdown,*/*', 200, 'text/markdown; charset=utf-8'],
            ['text/markdown;q=0.8,text/html;q=0.2', 200, 'text/markdown; charset=utf-8'],
            ['text/html;q=0.8,text/markdown;q=0.2', 200, 'text/html; charset=utf-8'],
            ['text/markdown;q=0,text/html', 200, 'text/html; charset=utf-8'],
            ['text/html;q=0,text/*;q=0.8', 200, 'text/markdown; charset=utf-8'],
            ['text/html;q=0,text/markdown;q=0,*/*;q=1', 406, 'text/html; charset=utf-8'],
            ['application/json', 406, 'text/html; charset=utf-8'],
        ];
    }

    public function test_markdown_404_retains_status_and_api_errors_remain_json(): void
    {
        $this->get('/missing-public-guide', ['Accept' => 'text/markdown'])->assertNotFound()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertHeader('Vary', 'Accept')->assertSee('/docs');
        $this->get('/missing-public-guide')->assertNotFound();
        $this->get('/api/not-an-api', ['Accept' => 'text/markdown'])->assertNotFound()->assertJsonPath('message', 'Not Found');
        $this->get('/api/v1/resident/amenities', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_docs_links_sitemap_and_static_discovery_are_consistent(): void
    {
        foreach (PublicContent::PAGES as $page) {
            $this->get('/'.$page.'.md')->assertOk()->assertSee(app(PublicContent::class)->markdown($page), false);
        }
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertCount(5, $xml->url);
        foreach ($xml->url as $url) {
            $this->assertContains(basename((string) $url->loc), PublicContent::PAGES);
            $this->assertSame('2026-10-27', (string) $url->lastmod);
        }
        $spec = json_decode(file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayNotHasKey('/api/v1/resident/amenities', $spec['paths']);
        $manifest = $this->get('/.well-known/mcp')->assertOk()->assertHeader('Content-Type', 'application/json')->json();
        $this->assertSame(config('public_content.url').'/mcp/public', $manifest['url']);
        $this->assertStringContainsString('/docs.md', file_get_contents(public_path('llms.txt')));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('social-card.png')), 0, 2));
        $routes = json_decode(file_get_contents(base_path('vercel.json')), true, flags: JSON_THROW_ON_ERROR)['routes'];
        $this->assertSame('/api/index.php', $routes[0]['dest']);
        $this->assertSame('filesystem', $routes[1]['handle']);
        $this->get('/index.html')->assertRedirect('/home');
    }

    public function test_private_spa_shell_has_no_public_index_content(): void
    {
        $this->get('/login')->assertOk()->assertSee('noindex, nofollow')->assertDontSee('application/ld+json');
    }

    public function test_mcp_handshake_tools_and_notification_use_real_json_rpc(): void
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25'];
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'qa', 'version' => '1'],
        ]], $headers)->assertOk()->assertJsonPath('result.protocolVersion', '2025-11-25')->assertJsonPath('result.serverInfo.name', 'SMART CASSAVAS public guides');
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers)->assertStatus(202)->assertContent('');
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)
            ->assertOk()->assertJsonCount(1, 'result.tools')->assertJsonPath('result.tools.0.name', 'read-public-guide')->assertJsonPath('result.tools.0.annotations.readOnlyHint', true);
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'read-public-guide', 'arguments' => ['page' => 'home'],
        ]], $headers)->assertOk()->assertJsonPath('result.content.0.text', app(PublicContent::class)->markdown('home'));
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
            'name' => 'read-public-guide', 'arguments' => ['page' => '../../.env'],
        ]], $headers)->assertOk()->assertJsonPath('result.isError', true);
        $this->postJson('/mcp/public', ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'payments/create'], $headers)
            ->assertOk()->assertJsonPath('error.code', -32601);
        $this->get('/mcp/public')->assertStatus(405);
    }

    public function test_mcp_rejects_untrusted_origin_and_invalid_transport_input(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $accept = ['Accept' => 'application/json, text/event-stream'];
        $this->postJson('/mcp/public', $body, $accept + ['Origin' => 'https://attacker.example'])->assertForbidden();
        $this->get('/mcp/public', ['Origin' => 'null'])->assertForbidden();
        $this->postJson('/mcp/public', $body, $accept + ['Origin' => config('public_content.url')])->assertOk();
        $this->postJson('/mcp/public', $body, $accept + ['MCP-Protocol-Version' => '2099-01-01'])->assertStatus(400);
        $this->postJson('/mcp/public', $body, ['Accept' => 'application/json'])->assertStatus(406);
        $this->post('/mcp/public', $body, $accept)->assertStatus(415);
        $this->call('POST', '/mcp/public', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], '{')
            ->assertStatus(400)->assertJsonPath('error.code', -32700);
        $this->postJson('/mcp/public', [$body], $accept)->assertStatus(400)->assertJsonPath('error.code', -32600);
        $this->postJson('/mcp/public', $body + ['params' => ['padding' => str_repeat('x', 17000)]], $accept)->assertStatus(413);
    }
}
