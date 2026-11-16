<?php

use App\Http\Middleware\ValidatePublicMcpRequest;
use App\Mcp\PublicGuideServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware([ValidatePublicMcpRequest::class, 'throttle:60,1'])->group(function (): void {
    Mcp::web('/mcp/public', PublicGuideServer::class);
});
