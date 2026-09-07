<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng apartments — Căn hộ.
 * Module: Reception (Tín)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartments', function (Blueprint $table) {
            $table->id();
            $table->string('building', 10); // A, B, C...
            $table->unsignedTinyInteger('floor');
            $table->string('unit_number', 10);
            $table->decimal('area', 8, 2)->nullable(); // m²
            $table->string('type', 20)->default('2br');
            $table->string('status', 20)->default('vacant');
            $table->timestamps();

            $table->unique(['building', 'floor', 'unit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartments');
    }
};
