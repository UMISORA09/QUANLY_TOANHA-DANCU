<?php

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Hóa đơn mới được tạo.
 * Các module khác có thể lắng nghe event này để xử lý logic liên quan.
 */
class InvoiceCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice
    ) {}
}
