<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\AcceptHeader;

class PublicContent
{
    public const PAGES = ['home', 'about', 'contact', 'privacy', 'docs'];

    public function markdown(string $page): string
    {
        abort_unless(in_array($page, self::PAGES, true), 404);

        return file_get_contents(resource_path('views/public/'.$page.'.md'));
    }

    /** @return array{title: string, description: string, canonical: string, html: string} */
    public function page(string $page): array
    {
        $markdown = $this->markdown($page);
        $paragraphs = preg_split('/\R\s*\R/', trim($markdown));

        return [
            'title' => ltrim($paragraphs[0], '# ').($page === 'home' ? '' : ' | SMART CASSAVAS'),
            'description' => Str::limit($paragraphs[1], 250),
            'canonical' => config('public_content.url').'/'.$page,
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ];
    }

    public function format(Request $request): ?string
    {
        $accept = AcceptHeader::fromString($request->header('Accept', '*/*'));
        $html = $accept->get('text/html')?->getQuality() ?? 0;
        $markdown = $accept->get('text/markdown')?->getQuality() ?? 0;

        if (max($html, $markdown) <= 0) {
            return null;
        }

        $explicitMarkdown = $accept->has('text/markdown');
        $explicitHtml = $accept->has('text/html');

        return $markdown > $html || ($markdown === $html && $explicitMarkdown && ! $explicitHtml) ? 'markdown' : 'html';
    }
}
