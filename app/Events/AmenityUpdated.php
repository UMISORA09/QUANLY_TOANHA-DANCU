<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AmenityUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $amenityData
     */
    public function __construct(
        public string $amenityId,
        public array $amenityData
    ) {}
}
