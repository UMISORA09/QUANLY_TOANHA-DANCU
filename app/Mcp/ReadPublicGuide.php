<?php

namespace App\Mcp;

use App\Services\PublicContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ReadPublicGuide extends Tool
{
    protected string $description = 'Read a public SMART CASSAVAS guide. No private data or business actions.';

    public function handle(Request $request, PublicContent $content): Response
    {
        $validated = $request->validate(['page' => ['required', 'string', Rule::in(PublicContent::PAGES)]]);

        return Response::text($content->markdown($validated['page']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['page' => $schema->string()->enum(PublicContent::PAGES)->required()];
    }
}
