<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AmenityCreated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $amenityData
     */
    public function __construct(
        public array $amenityData
    ) {}
}
