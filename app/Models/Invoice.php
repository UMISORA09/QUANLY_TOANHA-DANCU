<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'invoice_number',
        'batch_id',
        'apartment_id',
        'resident_user_id',
        'billing_period',
        'issue_date',
        'due_date',
        'subtotal_amount',
        'tax_amount',
        'discount_amount',
        'previous_debt_amount',
        'total_amount',
        'paid_amount',
        'remaining_balance',
        'status',
        'notes',
        'e_invoice_provider',
        'e_invoice_template_code',
        'e_invoice_series',
        'e_invoice_number',
        'e_invoice_tax_auth_code',
        'e_invoice_lookup_url',
        'e_invoice_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal_amount' => 'float',
            'tax_amount' => 'float',
            'discount_amount' => 'float',
            'previous_debt_amount' => 'float',
            'total_amount' => 'float',
            'paid_amount' => 'float',
            'remaining_balance' => 'float',
            'e_invoice_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Đợt sinh hóa đơn hàng loạt tạo ra hóa đơn này (nếu có)
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceGenerationBatch::class, 'batch_id');
    }

    /**
     * Căn hộ nhận hóa đơn
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class, 'apartment_id');
    }

    /**
     * Cư dân / Chủ hộ nhận hóa đơn
     */
    public function residentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_user_id');
    }

    /**
     * Danh sách các khoản mục chi tiết của hóa đơn
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }
}
