<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng visitors — Khách viếng thăm.
 * Module: Reception (Tín)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('id_card', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('purpose')->nullable();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('check_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
