<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng amenity_bookings — Đặt chỗ tiện ích.
 * Module: ResidentService (Nguyên)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenity_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amenity_id')->constrained('amenities')->cascadeOnDelete();
            $table->unsignedBigInteger('resident_id')->index();
            $table->date('booking_date');
            $table->time('time_slot_start');
            $table->time('time_slot_end');
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['amenity_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_bookings');
    }
};
