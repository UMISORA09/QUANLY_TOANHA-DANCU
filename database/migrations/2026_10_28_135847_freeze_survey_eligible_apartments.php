<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('satisfaction_surveys', function (Blueprint $table): void {
            // Historical membership cannot be reconstructed reliably from current residence records.
            $table->json('eligible_apartment_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('satisfaction_surveys', fn (Blueprint $table) => $table->dropColumn('eligible_apartment_ids'));
    }
};
