<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            // Legacy rows stay NULL; no historical records are merged or discarded.
            $table->char('submission_key_hash', 64)->nullable()->unique();
            $table->char('payload_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->dropUnique(['submission_key_hash']);
            $table->dropColumn(['submission_key_hash', 'payload_fingerprint']);
        });
    }
};
