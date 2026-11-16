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
        if (! Schema::hasTable('web_push_subscriptions')) {
            Schema::create('web_push_subscriptions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->text('endpoint');
                $table->string('endpoint_hash', 64)->unique();
                $table->string('public_key', 255);
                $table->string('auth_token', 255);
                $table->string('content_encoding', 20)->default('aes128gcm');
                $table->boolean('is_active')->default(true)->index();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'is_active']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
