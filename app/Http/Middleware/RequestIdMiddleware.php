<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    /**
     * Gán và theo dõi mã định danh duy nhất (Request ID / Correlation ID) cho mọi request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Sử dụng Request ID do Client/Reverse Proxy (Nginx, Cloudflare) gửi lên nếu có,
        // hoặc tự động tạo một UUID v4 mới.
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        // Gắn vào request header để các Controller và Service dễ dàng truy xuất
        $request->headers->set('X-Request-ID', $requestId);

        // Đưa Request ID vào ngữ cảnh Logging của Laravel để mọi log sinh ra đều chứa request_id
        Log::withContext([
            'request_id' => $requestId,
            'client_ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        $response = $next($request);

        // Đính kèm Header vào phản hồi HTTP để Client/DevOps Dashboard đối soát
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
