<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AmenityDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $amenityId
    ) {}
}
