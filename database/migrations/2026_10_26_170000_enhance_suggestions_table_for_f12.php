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
        Schema::table('suggestions', function (Blueprint $table) {
            if (! Schema::hasColumn('suggestions', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('status');
            }
            if (! Schema::hasColumn('suggestions', 'anonymous_access_token_hash')) {
                $table->string('anonymous_access_token_hash', 64)->nullable()->after('is_anonymous');
            }
            if (! Schema::hasColumn('suggestions', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('official_response_text');
            }
            if (! Schema::hasColumn('suggestions', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->after('box_id');
            }
        });

        // Add performance indexes if not existing
        Schema::table('suggestions', function (Blueprint $table) {
            $table->index('created_at', 'idx_suggestions_created_at');
            $table->index('anonymous_access_token_hash', 'idx_suggestions_anon_hash');
            $table->index('idempotency_key', 'idx_suggestions_idempotency');
            $table->index('topic_category', 'idx_suggestions_topic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            if (Schema::hasColumn('suggestions', 'idx_suggestions_created_at')) {
                $table->dropIndex('idx_suggestions_created_at');
            }
            if (Schema::hasColumn('suggestions', 'idx_suggestions_anon_hash')) {
                $table->dropIndex('idx_suggestions_anon_hash');
            }
            if (Schema::hasColumn('suggestions', 'idx_suggestions_idempotency')) {
                $table->dropIndex('idx_suggestions_idempotency');
            }
            if (Schema::hasColumn('suggestions', 'idx_suggestions_topic')) {
                $table->dropIndex('idx_suggestions_topic');
            }

            if (Schema::hasColumn('suggestions', 'version')) {
                $table->dropColumn('version');
            }
            if (Schema::hasColumn('suggestions', 'anonymous_access_token_hash')) {
                $table->dropColumn('anonymous_access_token_hash');
            }
            if (Schema::hasColumn('suggestions', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            if (Schema::hasColumn('suggestions', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
