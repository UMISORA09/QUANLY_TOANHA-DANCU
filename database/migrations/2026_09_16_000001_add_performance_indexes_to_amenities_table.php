<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('amenities')) {
            $existing = collect(Schema::getIndexes('amenities'))->pluck('name')->all();
            if (! in_array('idx_amenities_perf', $existing, true)) {
                Schema::table('amenities', function (Blueprint $table) {
                    $table->index(['deleted_at', 'is_active', 'category_id', 'block_id', 'created_at'], 'idx_amenities_perf');
                });
            }
        }

        if (Schema::hasTable('amenity_time_slots')) {
            $existing = collect(Schema::getIndexes('amenity_time_slots'))->pluck('name')->all();
            if (! in_array('idx_amenity_slots_perf', $existing, true)) {
                Schema::table('amenity_time_slots', function (Blueprint $table) {
                    $table->index(['amenity_id', 'is_active'], 'idx_amenity_slots_perf');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('amenities')) {
            $existing = collect(Schema::getIndexes('amenities'))->pluck('name')->all();
            if (in_array('idx_amenities_perf', $existing, true)) {
                Schema::table('amenities', function (Blueprint $table) {
                    $table->dropIndex('idx_amenities_perf');
                });
            }
        }

        if (Schema::hasTable('amenity_time_slots')) {
            $existing = collect(Schema::getIndexes('amenity_time_slots'))->pluck('name')->all();
            if (in_array('idx_amenity_slots_perf', $existing, true)) {
                Schema::table('amenity_time_slots', function (Blueprint $table) {
                    $table->dropIndex('idx_amenity_slots_perf');
                });
            }
        }
    }
};
