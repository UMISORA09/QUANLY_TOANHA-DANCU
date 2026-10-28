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
        Schema::create('amenity_search_states', function (Blueprint $table): void {
            $table->uuid('amenity_id')->primary();
            $table->unsignedBigInteger('desired_version')->default(0);
            $table->unsignedBigInteger('published_version')->default(0);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('task_version')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenity_search_states');
    }
};
