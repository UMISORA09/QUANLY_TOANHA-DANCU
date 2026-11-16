<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Table Purpose: Durable persistence of monitoring heartbeats & health observations
     * Source of truth: Survives Redis flush, restarts, and container redeployments.
     */
    public function up(): void
    {
        if (Schema::hasTable('freshness_heartbeats')) {
            return;
        }

        Schema::create('freshness_heartbeats', function (Blueprint $table) {
            $table->string('channel', 80)->primary(); // e.g. 'collector', 'application_health'
            $table->timestamp('last_success_at')->nullable()->index();
            $table->string('status', 30)->default('healthy');
            $table->text('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freshness_heartbeats');
    }
};
