<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng amenities — Tiện ích tòa nhà.
 * Module: ResidentService (Nguyên)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 30); // bbq, gym, pool, meeting_room, other
            $table->text('description')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('active');
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenities');
    }
};
