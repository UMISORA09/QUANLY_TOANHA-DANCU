<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Xóa index cũ nếu tồn tại
            $indexes = DB::select("SHOW INDEX FROM amenities WHERE Key_name = 'idx_amenities_fulltext'");
            if (! empty($indexes)) {
                DB::statement('ALTER TABLE amenities DROP INDEX idx_amenities_fulltext');
            }

            // Tạo FULLTEXT index mới chỉ tập trung vào 2 cột: amenity_name và amenity_code
            $newIndexes = DB::select("SHOW INDEX FROM amenities WHERE Key_name = 'idx_amenities_name_code_ft'");
            if (empty($newIndexes)) {
                DB::statement('ALTER TABLE amenities ADD FULLTEXT INDEX idx_amenities_name_code_ft (amenity_name, amenity_code)');
            }

            // Đảm bảo có B-Tree index trên amenity_code để tìm kiếm prefix / exact code siêu tốc O(log N)
            $codeIndexes = DB::select("SHOW INDEX FROM amenities WHERE Key_name = 'idx_amenities_code_btree'");
            if (empty($codeIndexes)) {
                DB::statement('ALTER TABLE amenities ADD INDEX idx_amenities_code_btree (amenity_code)');
            }
        } elseif ($driver === 'sqlite') {
            Schema::table('amenities', function (Blueprint $table) {
                $table->index(['amenity_name', 'amenity_code'], 'idx_amenities_name_code_fallback');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $newIndexes = DB::select("SHOW INDEX FROM amenities WHERE Key_name = 'idx_amenities_name_code_ft'");
            if (! empty($newIndexes)) {
                DB::statement('ALTER TABLE amenities DROP INDEX idx_amenities_name_code_ft');
            }

            $codeIndexes = DB::select("SHOW INDEX FROM amenities WHERE Key_name = 'idx_amenities_code_btree'");
            if (! empty($codeIndexes)) {
                DB::statement('ALTER TABLE amenities DROP INDEX idx_amenities_code_btree');
            }
        } elseif ($driver === 'sqlite') {
            Schema::table('amenities', function (Blueprint $table) {
                $table->dropIndex('idx_amenities_name_code_fallback');
            });
        }
    }
};
