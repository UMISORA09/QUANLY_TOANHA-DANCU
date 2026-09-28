<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Table Purpose: Durable persistence of freshness incident transitions (STALE, CRITICAL, RECOVERED)
     * Retention: Keeps history of incidents across deployments and service restarts.
     */
    public function up(): void
    {
        if (Schema::hasTable('freshness_incidents')) {
            return;
        }

        Schema::create('freshness_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80)->index();
            $table->string('source_type', 30)->default('data'); // 'data' or 'monitoring'
            $table->string('state', 30); // 'STALE', 'CRITICAL', 'RECOVERED'
            $table->integer('age_seconds')->nullable();
            $table->integer('threshold_seconds')->nullable();
            $table->timestamp('source_timestamp')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index(['source', 'state'], 'idx_freshness_src_state');
            $table->index('detected_at', 'idx_freshness_detected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freshness_incidents');
    }
};
