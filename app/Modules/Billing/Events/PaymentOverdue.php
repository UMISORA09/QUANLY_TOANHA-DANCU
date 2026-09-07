<?php

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Hóa đơn quá hạn thanh toán.
 * Module khác (ví dụ ResidentService) có thể lắng nghe để gửi thông báo nhắc nợ.
 */
class PaymentOverdue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice
    ) {}
}
