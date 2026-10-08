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
        if (Schema::hasTable('temporary_registrations') && ! Schema::hasIndex('temporary_registrations', 'idx_tempreg_type_status')) {
            Schema::table('temporary_registrations', function (Blueprint $table) {
                $table->index(['registration_type', 'police_status'], 'idx_tempreg_type_status');
            });
        }

        // 2. vehicles: Index on (vehicle_category, is_active) for fast category filtering
        if (Schema::hasTable('vehicles') && ! Schema::hasIndex('vehicles', 'idx_vehicles_cat_active')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->index(['vehicle_category', 'is_active'], 'idx_vehicles_cat_active');
            });
        }

        // 3. residents: Index on (resident_type, is_active) for fast resident type filtering
        if (Schema::hasTable('residents') && ! Schema::hasIndex('residents', 'idx_residents_type_active')) {
            Schema::table('residents', function (Blueprint $table) {
                $table->index(['resident_type', 'is_active'], 'idx_residents_type_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('residents') && Schema::hasIndex('residents', 'idx_residents_type_active')) {
            Schema::table('residents', function (Blueprint $table) {
                $table->dropIndex('idx_residents_type_active');
            });
        }

        if (Schema::hasTable('vehicles') && Schema::hasIndex('vehicles', 'idx_vehicles_cat_active')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropIndex('idx_vehicles_cat_active');
            });
        }

        if (Schema::hasTable('temporary_registrations') && Schema::hasIndex('temporary_registrations', 'idx_tempreg_type_status')) {
            Schema::table('temporary_registrations', function (Blueprint $table) {
                $table->dropIndex('idx_tempreg_type_status');
            });
        }
    }
};
