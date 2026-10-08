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
        // 1. temporary_registrations: Index on (registration_type, police_status) for fast filtering
        if (Schema::hasTable('temporary_registrations')) {
            $existing = collect(Schema::getIndexes('temporary_registrations'))->pluck('name')->all();
            if (! in_array('idx_tempreg_type_status', $existing, true)) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->index(['registration_type', 'police_status'], 'idx_tempreg_type_status');
                });
            }
        }

        // 2. vehicles: Index on (vehicle_category, is_active) for fast category filtering
        if (Schema::hasTable('vehicles')) {
            $existing = collect(Schema::getIndexes('vehicles'))->pluck('name')->all();
            if (! in_array('idx_vehicles_cat_active', $existing, true)) {
                Schema::table('vehicles', function (Blueprint $table) {
                    $table->index(['vehicle_category', 'is_active'], 'idx_vehicles_cat_active');
                });
            }
        }

        // 3. residents: Index on (resident_type, is_active) for fast resident type filtering
        if (Schema::hasTable('residents')) {
            $existing = collect(Schema::getIndexes('residents'))->pluck('name')->all();
            if (! in_array('idx_residents_type_active', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->index(['resident_type', 'is_active'], 'idx_residents_type_active');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('residents')) {
            $existing = collect(Schema::getIndexes('residents'))->pluck('name')->all();
            if (in_array('idx_residents_type_active', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->dropIndex('idx_residents_type_active');
                });
            }
        }

        if (Schema::hasTable('vehicles')) {
            $existing = collect(Schema::getIndexes('vehicles'))->pluck('name')->all();
            if (in_array('idx_vehicles_cat_active', $existing, true)) {
                Schema::table('vehicles', function (Blueprint $table) {
                    $table->dropIndex('idx_vehicles_cat_active');
                });
            }
        }

        if (Schema::hasTable('temporary_registrations')) {
            $existing = collect(Schema::getIndexes('temporary_registrations'))->pluck('name')->all();
            if (in_array('idx_tempreg_type_status', $existing, true)) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->dropIndex('idx_tempreg_type_status');
                });
            }
        }
    }
};
