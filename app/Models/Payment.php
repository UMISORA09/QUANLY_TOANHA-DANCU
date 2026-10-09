<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'payment_reference_code',
        'invoice_id',
        'apartment_id',
        'payer_user_id',
        'amount_paid',
        'payment_gateway',
        'gateway_transaction_id',
        'idempotency_key',
        'payment_status',
        'payment_time',
        'qr_code_content',
        'bank_account_number',
        'bank_bin',
        'gateway_callback_payload',
        'failure_reason',
        'recorded_by_staff_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_paid' => 'float',
            'payment_time' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Hóa đơn được thanh toán
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Căn hộ liên kết
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Cư dân thực hiện thanh toán
     */
    public function payerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    /**
     * Nhân viên thu ngân / lễ tân ghi nhận (nếu thu tiền mặt/chuyển khoản tay)
     */
    public function recordedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_staff_id');
    }

    /**
     * Biên lai thu tiền tương ứng
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class, 'payment_id');
    }
}
