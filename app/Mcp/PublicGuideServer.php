<?php

namespace App\Mcp;

use Laravel\Mcp\Server;

class PublicGuideServer extends Server
{
    protected string $name = 'SMART CASSAVAS public guides';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-only public product documentation. No account data, bookings, payments or administrative actions. Do not request credentials.';

    protected array $supportedProtocolVersion = ['2025-11-25'];

    protected array $capabilities = ['tools' => ['listChanged' => false]];

    protected array $tools = [ReadPublicGuide::class];
}
