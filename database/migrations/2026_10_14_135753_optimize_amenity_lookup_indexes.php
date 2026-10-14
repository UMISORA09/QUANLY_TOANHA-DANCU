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
        if (! Schema::hasIndex('amenity_blackouts', 'idx_amenity_blackouts_lookup')) {
            Schema::table('amenity_blackouts', function (Blueprint $table): void {
                $table->index(['amenity_id', 'blackout_date'], 'idx_amenity_blackouts_lookup');
            });
        }
        if (Schema::hasIndex('amenities', 'amenity_code', 'unique') && Schema::hasIndex('amenities', 'idx_amenities_code_btree')) {
            Schema::table('amenities', function (Blueprint $table): void {
                $table->dropIndex('idx_amenities_code_btree');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('amenities', 'idx_amenities_code_btree')) {
            Schema::table('amenities', function (Blueprint $table): void {
                $table->index('amenity_code', 'idx_amenities_code_btree');
            });
        }
        if (Schema::hasIndex('amenity_blackouts', 'idx_amenity_blackouts_lookup')) {
            Schema::table('amenity_blackouts', function (Blueprint $table): void {
                $table->dropIndex('idx_amenity_blackouts_lookup');
            });
        }
    }
};
