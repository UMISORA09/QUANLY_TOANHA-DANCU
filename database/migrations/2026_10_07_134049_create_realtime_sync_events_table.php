<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('realtime_sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 100)->index();
            $table->string('module', 50)->index();
            $table->string('entity', 50);
            $table->string('action', 50);
            $table->string('entity_id', 100)->nullable();
            $table->string('version', 100)->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_at_ms')->index();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('realtime_sync_events');
    }
};
