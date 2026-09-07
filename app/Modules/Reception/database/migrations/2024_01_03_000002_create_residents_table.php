<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng residents — Cư dân.
 * Module: Reception (Tín)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('id_card', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('move_in_date')->nullable();
            $table->date('move_out_date')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['apartment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
