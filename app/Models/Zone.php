<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Model đại diện cho Khối Tòa nhà / Khu vực (Block/Zone).
 *
 * @property int $id
 * @property string $zone_code Mã khối (ví dụ: BLOCK_A)
 * @property string $zone_name Tên khối nhà
 * @property int $floor_count Số tầng nổi (> 0)
 * @property int $basement_count Số tầng hầm
 * @property int $total_apartments Tổng số căn hộ
 * @property string $status Trạng thái: ACTIVE, MAINTENANCE, INACTIVE
 * @property string|null $address_line Địa chỉ/Vị trí
 * @property string|null $hotline_phone Hotline quản lý khối
 * @property string|null $description Mô tả chi tiết
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Zone extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Bảng tương ứng trong cơ sở dữ liệu.
     *
     * @var string
     */
    protected $table = 'zones';

    /**
     * Danh sách các trường được phép gán dữ liệu hàng loạt (tránh Mass Assignment).
     *
     * @var list<string>
     */
    protected $fillable = [
        'zone_code',
        'zone_name',
        'floor_count',
        'basement_count',
        'total_apartments',
        'status',
        'address_line',
        'hotline_phone',
        'description',
    ];

    /**
     * Ép kiểu dữ liệu các trường khi truy vấn.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floor_count' => 'integer',
            'basement_count' => 'integer',
            'total_apartments' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Scope lọc theo trạng thái hoạt động.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFilterStatus($query, ?string $status)
    {
        if ($status && in_array(strtoupper($status), ['ACTIVE', 'MAINTENANCE', 'INACTIVE'], true)) {
            return $query->where('status', strtoupper($status));
        }

        return $query;
    }

    /**
     * Scope tìm kiếm theo mã hoặc tên khối nhà.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearchKeyword($query, ?string $keyword)
    {
        if ($keyword && trim($keyword) !== '') {
            $term = '%'.trim($keyword).'%';

            return $query->where(function ($q) use ($term) {
                $q->where('zone_code', 'LIKE', $term)
                    ->orWhere('zone_name', 'LIKE', $term)
                    ->orWhere('address_line', 'LIKE', $term);
            });
        }

        return $query;
    }
}
