<?php

namespace App\Http\Controllers;

use App\Services\PublicContent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class PublicContentController extends Controller
{
    public function __construct(private PublicContent $content) {}

    public function show(Request $request, string $page = 'home'): Response
    {
        $format = $this->content->format($request);
        $headers = ['Vary' => 'Accept', 'Cache-Control' => 'public, max-age=0, must-revalidate'];

        if ($format === null) {
            return response('Supported representations: text/html, text/markdown.', 406, $headers);
        }

        if ($format === 'markdown') {
            return response($this->content->markdown($page), 200, $headers + ['Content-Type' => 'text/markdown; charset=utf-8']);
        }

        return response()->view($page === 'home' ? 'welcome' : 'public.page', $this->content->page($page), 200, $headers);
    }

    public function guide(Request $request, string $page): JsonResponse
    {
        if (! in_array($page, PublicContent::PAGES, true)) {
            return $this->publicJson($request, ['code' => 'guide_not_found', 'message' => 'Public guide not found.'], 404);
        }

        return $this->publicJson($request, [
            'page' => $page, 'markdown' => $this->content->markdown($page),
            'title' => $this->content->page($page)['title'], 'updated_at' => config('public_content.updated_at'),
        ]);
    }

    public function readCliInfo(Request $request): JsonResponse
    {
        return $this->publicJson($request, [
            'name' => 'SMART CASSAVAS CLI', 'command' => 'smart-cassavas', 'version' => config('public_content.cli_version'),
            'description' => 'Read-only public documentation tooling; does not access accounts or business data.',
            'installation' => ['repository' => 'https://github.com/UMISORA09/QUANLY_TOANHA-DANCU', 'local' => 'node bin/smart-cassavas.cjs --help', 'npm' => null, 'npx' => null],
            'commands' => [
                ['name' => 'docs', 'description' => 'Read a public Markdown guide.', 'usage' => 'smart-cassavas docs [page] [--json]'],
                ['name' => 'status', 'description' => 'Check public documentation availability, not database health.', 'usage' => 'smart-cassavas status [--json]'],
                ['name' => 'mcp-info', 'description' => 'Read public MCP discovery metadata.', 'usage' => 'smart-cassavas mcp-info [--json]'],
            ],
            'pages' => PublicContent::PAGES,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function publicJson(Request $request, array $data, int $status = 200): JsonResponse
    {
        $limit = max(1, (int) config('public_content.rate_limit', 60));
        $window = max(1, (int) config('public_content.rate_limit_window', 60));
        $key = 'public-guide:'.hash('sha256', (string) $request->ip());
        foreach (array_unique([config('public_content.rate_limit_store', 'file'), 'file']) as $storeName) {
            $driver = config('cache.stores.'.$storeName.'.driver');
            if ($driver === 'database' || ($driver === 'array' && ! app()->environment('testing'))) {
                continue;
            }
            try {
                $store = Cache::store($storeName);
                $consume = function () use ($store, $key, $window): array {
                    $limiter = new RateLimiter($store);
                    $attempt = $limiter->increment($key, $window);

                    return [$attempt, max(1, $limiter->availableIn($key))];
                };
                if ($driver === 'file') {
                    $lockStore = $store instanceof Repository ? $store->getStore() : null;
                    if (! $lockStore instanceof LockProvider) {
                        continue;
                    }
                    [$attempt, $reset] = $lockStore->lock($key.':lock', 5)->block(2, $consume);
                } else {
                    [$attempt, $reset] = $consume();
                }
                $remaining = max(0, $limit - $attempt);
                $headers = [
                    'Cache-Control' => 'no-store',
                    'RateLimit-Policy' => '"public-guide";q='.$limit.';w='.$window,
                    'RateLimit' => '"public-guide";r='.$remaining.';t='.$reset,
                    'RateLimit-Limit' => (string) $limit, 'RateLimit-Remaining' => (string) $remaining, 'RateLimit-Reset' => (string) $reset,
                    'X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $remaining, 'X-RateLimit-Reset' => (string) (time() + $reset),
                ];
                if ($attempt > $limit) {
                    return response()->json(['code' => 'rate_limit_exceeded', 'message' => 'Too many requests.'], 429, $headers + ['Retry-After' => (string) $reset]);
                }

                return response()->json($data, $status, $headers);
            } catch (\Throwable) {
                continue;
            }
        }

        return response()->json(['code' => 'public_cache_unavailable', 'message' => 'Public request limits are temporarily unavailable.'], 503, ['Cache-Control' => 'no-store', 'Retry-After' => (string) $window]);
    }

    public function markdown(string $page): Response
    {
        return response($this->content->markdown($page), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
    }

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_merge(PublicContent::PAGES, ['cli.md']) as $page) {
            $xml .= '<url><loc>'.htmlspecialchars(config('public_content.url').'/'.$page, ENT_XML1).'</loc><lastmod>'.config('public_content.updated_at').'</lastmod></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function mcpDiscovery(Request $request): JsonResponse
    {
        return $this->publicJson($request, [
            'name' => 'SMART CASSAVAS public guides',
            'description' => 'Site-specific discovery document; not an official MCP registry registration or verification.',
            'url' => config('public_content.url').'/mcp/public',
            'transport' => 'streamable-http',
            'protocolVersions' => ['2025-11-25'],
            'documentation' => config('public_content.url').'/docs',
            'authentication' => 'none; public documentation only',
        ]);
    }

    public function missing(Request $request): Response|JsonResponse
    {
        if (! $request->is('api/*') && $this->content->format($request) === 'markdown') {
            return response("# Không tìm thấy trang\n\nĐường dẫn này không tồn tại. Hãy xem [tài liệu SMART CASSAVAS](/docs) hoặc [llms.txt](/llms.txt).\n", 404, [
                'Content-Type' => 'text/markdown; charset=utf-8', 'Vary' => 'Accept',
            ]);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Not Found'], 404)->header('Vary', 'Accept');
        }

        return response()->view('errors.404', [], 404, ['Vary' => 'Accept']);
    }
}
