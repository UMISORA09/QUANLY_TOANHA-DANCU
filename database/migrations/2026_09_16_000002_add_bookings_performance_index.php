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
        if (Schema::hasTable('amenity_bookings')) {
            $existing = collect(Schema::getIndexes('amenity_bookings'))->pluck('name')->all();
            if (! in_array('idx_amenity_bookings_perf', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->index(['amenity_id', 'status', 'deleted_at'], 'idx_amenity_bookings_perf');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('amenity_bookings')) {
            $existing = collect(Schema::getIndexes('amenity_bookings'))->pluck('name')->all();
            if (in_array('idx_amenity_bookings_perf', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->dropIndex('idx_amenity_bookings_perf');
                });
            }
        }
    }
};
