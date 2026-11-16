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
        if (Schema::hasTable('service_pricing_configs')) {
            $hasIndex = Schema::hasIndex('service_pricing_configs', 'service_code')
                || Schema::hasIndex('service_pricing_configs', 'service_pricing_configs_service_code_unique');

            if (! $hasIndex) {
                Schema::table('service_pricing_configs', function (Blueprint $table) {
                    $table->unique('service_code', 'service_pricing_configs_service_code_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_pricing_configs')) {
            if (Schema::hasIndex('service_pricing_configs', 'service_pricing_configs_service_code_unique')) {
                Schema::table('service_pricing_configs', function (Blueprint $table) {
                    $table->dropIndex('service_pricing_configs_service_code_unique');
                });
            }
        }
    }
};
