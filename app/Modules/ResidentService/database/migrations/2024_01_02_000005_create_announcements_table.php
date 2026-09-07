<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng announcements — Bảng tin thông báo.
 * Module: ResidentService (Nguyên)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('category', 50)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
