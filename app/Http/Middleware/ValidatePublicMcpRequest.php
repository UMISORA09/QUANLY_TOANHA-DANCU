<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

class ValidatePublicMcpRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowed = [config('public_content.url')];
        if (app()->environment(['local', 'testing']) && in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            $allowed[] = $request->getSchemeAndHttpHost();
        }
        abort_if($origin !== null && ! in_array($origin, $allowed, true), 403);

        if ($request->isMethod('POST')) {
            abort_if(strlen($request->getContent()) > 16384, 413);
            abort_unless($request->isJson(), 415);
            $accept = AcceptHeader::fromString($request->header('Accept', ''));
            abort_unless(($accept->get('application/json')?->getQuality() ?? 0) > 0 && ($accept->get('text/event-stream')?->getQuality() ?? 0) > 0, 406);
            abort_if($request->hasHeader('MCP-Protocol-Version') && $request->header('MCP-Protocol-Version') !== '2025-11-25', 400);

            $message = json_decode($request->getContent());
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
            }
            if (! $message instanceof \stdClass || ($message->jsonrpc ?? null) !== '2.0' || ! is_string($message->method ?? null)
                || (isset($message->params) && ! $message->params instanceof \stdClass)
                || (property_exists($message, 'id') && ! is_int($message->id) && ! is_string($message->id))) {
                return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']], 400);
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
