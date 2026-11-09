<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['amenity_categories', 'amenity_time_slots', 'amenity_blackouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['amenity_categories', 'amenity_time_slots', 'amenity_blackouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }
};
