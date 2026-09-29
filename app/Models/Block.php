<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Model đại diện cho Khối Tòa nhà / Khu vực (Block/Zone).
 * Nguồn sự thật duy nhất (Single Source of Truth) trong hệ thống quản lý tòa nhà và căn hộ.
 *
 * @property string $id Khóa chính UUID
 * @property string $block_code Mã khối tòa nhà (ví dụ: BLOCK_A)
 * @property string $block_name Tên khối tòa nhà (ví dụ: Tòa Nhà A - Ruby Tower)
 * @property int $total_floors Số tầng nổi quy hoạch/dự kiến
 * @property int $total_basements Số tầng hầm
 * @property int $total_apartments Số căn hộ quy hoạch/dự kiến
 * @property string $status Trạng thái vận hành: ACTIVE, MAINTENANCE, INACTIVE
 * @property string|null $address_line Vị trí/Địa chỉ khối nhà
 * @property string|null $hotline_phone Hotline quản lý/lễ tân khối
 * @property string|null $description Mô tả chi tiết kỹ thuật/vận hành
 * @property string|null $building_manager_user_id ID người quản lý tòa nhà
 * @property bool $ai_features_enabled Kích hoạt tính năng AI
 * @property array<string, mixed>|null $metadata Thông tin mở rộng JSON
 * @property int $version Phiên bản dùng cho Optimistic Locking Compare-And-Swap
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * Virtual Compatibility Attributes (Zone alias):
 * @property string $zone_code
 * @property string $zone_name
 * @property int $floor_count
 * @property int $basement_count
 * @property int $actual_floors_count
 * @property int $actual_apartments_count
 */
class Block extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Bảng cơ sở dữ liệu đại diện cho Khối Tòa nhà.
     */
    protected $table = 'blocks';

    /**
     * Kiểu dữ liệu của khóa chính UUID.
     */
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Các trường được phép gán hàng loạt an toàn.
     */
    protected $fillable = [
        'block_code',
        'block_name',
        'total_floors',
        'total_basements',
        'total_apartments',
        'status',
        'address_line',
        'hotline_phone',
        'description',
        'building_manager_user_id',
        'ai_features_enabled',
        'metadata',
        'version',
    ];

    /**
     * Các trường ảo bổ sung phục vụ tương thích với API / UI Zone hiện hành.
     */
    protected $appends = [
        'zone_code',
        'zone_name',
        'floor_count',
        'basement_count',
    ];

    /**
     * Ép kiểu các trường dữ liệu.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_floors' => 'integer',
            'total_basements' => 'integer',
            'total_apartments' => 'integer',
            'ai_features_enabled' => 'boolean',
            'version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // ==========================================
    // CÁC QUAN HỆ THỰC THỂ (RELATIONSHIPS)
    // ==========================================

    /**
     * Danh sách các tầng thuộc tòa nhà.
     */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class, 'block_id');
    }

    /**
     * Danh sách tất cả căn hộ trực thuộc khối tòa nhà này.
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class, 'block_id');
    }

    /**
     * Người quản lý khối tòa nhà.
     */
    public function buildingManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'building_manager_user_id');
    }

    // ==========================================
    // ACCESSORS VÀ MUTATORS ĐẢM BẢO TƯƠNG THÍCH ZONE
    // ==========================================

    protected function zoneCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->block_code,
            set: fn ($value) => ['block_code' => strtoupper(trim((string) $value))],
        );
    }

    protected function zoneName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->block_name,
            set: fn ($value) => ['block_name' => (string) $value],
        );
    }

    protected function floorCount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->total_floors,
            set: fn ($value) => ['total_floors' => (int) $value],
        );
    }

    protected function basementCount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->total_basements,
            set: fn ($value) => ['total_basements' => (int) $value],
        );
    }

    // ==========================================
    // QUERY SCOPES
    // ==========================================

    /**
     * Lọc theo trạng thái hoạt động: ACTIVE, MAINTENANCE, INACTIVE.
     */
    public function scopeFilterStatus(Builder $query, ?string $status): Builder
    {
        if ($status && in_array(strtoupper(trim($status)), ['ACTIVE', 'MAINTENANCE', 'INACTIVE'], true)) {
            return $query->where('status', strtoupper(trim($status)));
        }

        return $query;
    }

    /**
     * Tìm kiếm nhanh theo mã khối hoặc tên khối tòa nhà.
     */
    public function scopeSearchKeyword(Builder $query, ?string $keyword): Builder
    {
        if ($keyword && trim($keyword) !== '') {
            $term = '%'.trim($keyword).'%';

            return $query->where(function (Builder $q) use ($term) {
                $q->where('block_code', 'LIKE', $term)
                    ->orWhere('block_name', 'LIKE', $term)
                    ->orWhere('address_line', 'LIKE', $term)
                    ->orWhere('description', 'LIKE', $term);
            });
        }

        return $query;
    }
}
