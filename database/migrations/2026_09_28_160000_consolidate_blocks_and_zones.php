<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Củng cố bảng blocks làm nguồn sự thật duy nhất (Single Source of Truth) cho Khối/Tòa nhà/Zone.
     * Bổ sung các trường status, description, version (cho Optimistic Locking CAS).
     * Di chuyển an toàn dữ liệu từ bảng zones (nếu có) sang blocks mà không làm mất dữ liệu.
     */
    public function up(): void
    {
        // 1. Nâng cấp bảng blocks với các cột cần thiết
        if (Schema::hasTable('blocks')) {
            Schema::table('blocks', function (Blueprint $table) {
                if (! Schema::hasColumn('blocks', 'status')) {
                    $table->string('status', 30)->default('ACTIVE')->after('total_apartments')->comment('Trạng thái: ACTIVE, MAINTENANCE, INACTIVE');
                    $table->index('status');
                }
                if (! Schema::hasColumn('blocks', 'description')) {
                    $table->text('description')->nullable()->after('hotline_phone')->comment('Mô tả chi tiết kỹ thuật/vận hành');
                }
                if (! Schema::hasColumn('blocks', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('metadata')->comment('Version dùng cho Optimistic Locking Compare-And-Swap');
                }
            });
        }

        // 2. Di chuyển dữ liệu từ bảng zones sang blocks (nếu bảng zones tồn tại và có dữ liệu)
        if (Schema::hasTable('zones')) {
            $existingZones = DB::table('zones')->get();

            foreach ($existingZones as $zone) {
                $code = strtoupper(trim($zone->zone_code));
                $existingBlock = DB::table('blocks')->where('block_code', $code)->first();

                if (! $existingBlock) {
                    DB::table('blocks')->insert([
                        'id' => (string) Str::uuid(),
                        'block_code' => $code,
                        'block_name' => $zone->zone_name,
                        'total_floors' => (int) ($zone->floor_count ?? 1),
                        'total_basements' => (int) ($zone->basement_count ?? 1),
                        'total_apartments' => (int) ($zone->total_apartments ?? 0),
                        'status' => strtoupper($zone->status ?? 'ACTIVE'),
                        'address_line' => $zone->address_line ?? null,
                        'hotline_phone' => $zone->hotline_phone ?? null,
                        'description' => $zone->description ?? null,
                        'version' => 1,
                        'created_at' => $zone->created_at ?? now(),
                        'updated_at' => $zone->updated_at ?? now(),
                        'deleted_at' => $zone->deleted_at ?? null,
                    ]);
                } else {
                    // Cập nhật bổ sung description hoặc status nếu block chưa có
                    $updatePayload = [];
                    if (empty($existingBlock->description) && ! empty($zone->description)) {
                        $updatePayload['description'] = $zone->description;
                    }
                    if (isset($zone->status) && (! isset($existingBlock->status) || $existingBlock->status === 'ACTIVE')) {
                        $updatePayload['status'] = strtoupper($zone->status);
                    }
                    if (! empty($updatePayload)) {
                        DB::table('blocks')->where('id', $existingBlock->id)->update($updatePayload);
                    }
                }
            }

            // 3. Xóa an toàn bảng zones sau khi đã di chuyển dữ liệu sang blocks
            Schema::dropIfExists('zones');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('zones')) {
            Schema::create('zones', function (Blueprint $table) {
                $table->id();
                $table->string('zone_code', 50)->unique();
                $table->string('zone_name', 150);
                $table->unsignedInteger('floor_count');
                $table->unsignedInteger('basement_count')->default(1);
                $table->unsignedInteger('total_apartments')->default(0);
                $table->string('status', 30)->default('ACTIVE');
                $table->text('address_line')->nullable();
                $table->string('hotline_phone', 30)->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('blocks')) {
            Schema::table('blocks', function (Blueprint $table) {
                if (Schema::hasColumn('blocks', 'status')) {
                    $table->dropColumn('status');
                }
                if (Schema::hasColumn('blocks', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::hasColumn('blocks', 'version')) {
                    $table->dropColumn('version');
                }
            });
        }
    }
};
