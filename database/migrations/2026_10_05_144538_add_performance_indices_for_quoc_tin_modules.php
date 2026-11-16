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
        // 1. RBAC: user_roles - index on role_id
        Schema::table('user_roles', function (Blueprint $table) {
            $table->index('role_id', 'idx_user_roles_role_id');
        });

        // 2. RBAC: role_permissions - index on permission_id
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->index('permission_id', 'idx_role_permissions_permission_id');
        });

        // 3. Temporary Registrations: indices for status, apartment, resident, dates
        Schema::table('temporary_registrations', function (Blueprint $table) {
            $table->index(['police_status', 'created_at'], 'idx_tempreg_status_created');
            $table->index(['apartment_id', 'police_status'], 'idx_tempreg_apt_status');
            $table->index('resident_id', 'idx_tempreg_resident_id');
        });

        // 4. Vehicles: indices for owner lookup and active status with sorting
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index(['owner_user_id', 'is_active'], 'idx_vehicles_owner_active');
            $table->index(['is_active', 'created_at'], 'idx_vehicles_active_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('idx_vehicles_active_created');
            $table->dropIndex('idx_vehicles_owner_active');
        });

        Schema::table('temporary_registrations', function (Blueprint $table) {
            $table->dropIndex('idx_tempreg_resident_id');
            $table->dropIndex('idx_tempreg_apt_status');
            $table->dropIndex('idx_tempreg_status_created');
        });

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropIndex('idx_role_permissions_permission_id');
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropIndex('idx_user_roles_role_id');
        });
    }
};
