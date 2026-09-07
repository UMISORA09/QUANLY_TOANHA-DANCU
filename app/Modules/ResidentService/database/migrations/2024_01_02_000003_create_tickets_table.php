<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng tickets — Báo cáo sự cố / Yêu cầu hỗ trợ.
 * Module: ResidentService (Nguyên)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('resident_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
