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
        if (Schema::hasTable('service_pricing_configs') && ! Schema::hasIndex('service_pricing_configs', ['service_code'])) {
            Schema::table('service_pricing_configs', function (Blueprint $table) {
                $table->unique('service_code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_pricing_configs') && Schema::hasIndex('service_pricing_configs', ['service_code'])) {
            Schema::table('service_pricing_configs', function (Blueprint $table) {
                $table->dropUnique(['service_code']);
            });
        }
    }
};
