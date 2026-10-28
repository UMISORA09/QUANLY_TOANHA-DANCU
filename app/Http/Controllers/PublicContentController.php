<?php

namespace App\Http\Controllers;

use App\Services\PublicContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function markdown(string $page): Response
    {
        return response($this->content->markdown($page), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
    }

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (PublicContent::PAGES as $page) {
            $xml .= '<url><loc>'.htmlspecialchars(config('public_content.url').'/'.$page, ENT_XML1).'</loc><lastmod>'.config('public_content.updated_at').'</lastmod></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function mcpDiscovery(): JsonResponse
    {
        return response()->json([
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
