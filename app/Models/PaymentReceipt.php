<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_receipts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'receipt_number',
        'payment_id',
        'receipt_date',
        'amount',
        'amount_in_words',
        'received_from_name',
        'pdf_receipt_url',
        'digital_signature_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'receipt_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Giao dịch thanh toán liên kết
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
