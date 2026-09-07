<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng parcels — Bưu phẩm.
 * Module: Reception (Tín)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->string('sender')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->unsignedBigInteger('picked_up_by')->nullable();
            $table->string('status', 20)->default('received');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['resident_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcels');
    }
};
