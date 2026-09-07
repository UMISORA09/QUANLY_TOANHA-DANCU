<?php

namespace App\Modules\Reception\Events;

use App\Modules\Reception\Models\Resident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Cư dân mới được tạo hồ sơ.
 */
class ResidentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Resident $resident
    ) {}
}
