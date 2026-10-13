<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceGenerationBatch extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'invoice_generation_batches';

    public $timestamps = false;

    protected $fillable = [
        'batch_number',
        'billing_period',
        'block_id',
        'executed_by_user_id',
        'total_apartments_processed',
        'total_invoices_created',
        'total_amount_calculated',
        'status',
        'error_logs',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_apartments_processed' => 'integer',
            'total_invoices_created' => 'integer',
            'total_amount_calculated' => 'float',
            'error_logs' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Khối tòa nhà được áp dụng (NULL nếu toàn bộ tòa nhà)
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    /**
     * Người dùng (Quản trị viên / Kế toán) thực thi đợt sinh hóa đơn
     */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    /**
     * Danh sách hóa đơn được tạo ra trong đợt này
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'batch_id');
    }
}
