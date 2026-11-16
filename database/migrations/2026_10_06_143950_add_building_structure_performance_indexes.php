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
        if (Schema::hasTable('apartments')) {
            $existing = collect(Schema::getIndexes('apartments'))->pluck('name')->all();

            Schema::table('apartments', function (Blueprint $table) use ($existing) {
                if (! in_array('idx_apartments_floor_del', $existing, true)) {
                    $table->index(['floor_id', 'deleted_at'], 'idx_apartments_floor_del');
                }
                if (! in_array('idx_apartments_status_del', $existing, true)) {
                    $table->index(['status', 'deleted_at'], 'idx_apartments_status_del');
                }
                if (! in_array('idx_apartments_number_del', $existing, true)) {
                    $table->index(['apartment_number', 'deleted_at'], 'idx_apartments_number_del');
                }
                if (! in_array('idx_apartments_block_status_del', $existing, true)) {
                    $table->index(['block_id', 'status', 'deleted_at'], 'idx_apartments_block_status_del');
                }
                if (! in_array('idx_apartments_block_floor_del', $existing, true)) {
                    $table->index(['block_id', 'floor_id', 'deleted_at'], 'idx_apartments_block_floor_del');
                }
            });
        }

        if (Schema::hasTable('floors')) {
            $existingFloors = collect(Schema::getIndexes('floors'))->pluck('name')->all();

            Schema::table('floors', function (Blueprint $table) use ($existingFloors) {
                if (! in_array('idx_floors_block_del', $existingFloors, true)) {
                    $table->index(['block_id', 'deleted_at'], 'idx_floors_block_del');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('apartments')) {
            $existing = collect(Schema::getIndexes('apartments'))->pluck('name')->all();

            Schema::table('apartments', function (Blueprint $table) use ($existing) {
                if (in_array('idx_apartments_floor_del', $existing, true)) {
                    $table->dropIndex('idx_apartments_floor_del');
                }
                if (in_array('idx_apartments_status_del', $existing, true)) {
                    $table->dropIndex('idx_apartments_status_del');
                }
                if (in_array('idx_apartments_number_del', $existing, true)) {
                    $table->dropIndex('idx_apartments_number_del');
                }
                if (in_array('idx_apartments_block_status_del', $existing, true)) {
                    $table->dropIndex('idx_apartments_block_status_del');
                }
                if (in_array('idx_apartments_block_floor_del', $existing, true)) {
                    $table->dropIndex('idx_apartments_block_floor_del');
                }
            });
        }

        if (Schema::hasTable('floors')) {
            $existingFloors = collect(Schema::getIndexes('floors'))->pluck('name')->all();

            Schema::table('floors', function (Blueprint $table) use ($existingFloors) {
                if (in_array('idx_floors_block_del', $existingFloors, true)) {
                    $table->dropIndex('idx_floors_block_del');
                }
            });
        }
    }
};
