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
        Schema::create('search_request_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('request_kind', 20);
            $table->string('engine', 20);
            $table->string('fallback_reason', 40)->nullable();
            $table->unsignedInteger('result_count');
            $table->double('duration_ms');
            $table->unsignedTinyInteger('algolia_calls')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_request_metrics');
    }
};
