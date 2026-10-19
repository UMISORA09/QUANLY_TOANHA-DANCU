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
        if (Schema::hasTable('access_cards')) {
            if (! Schema::hasIndex('access_cards', 'idx_access_cards_status_type')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->index(['status', 'card_type'], 'idx_access_cards_status_type');
                });
            }

            if (! Schema::hasIndex('access_cards', 'idx_access_cards_created_at')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->index(['created_at'], 'idx_access_cards_created_at');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('access_cards')) {
            if (Schema::hasIndex('access_cards', 'idx_access_cards_status_type')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->dropIndex('idx_access_cards_status_type');
                });
            }

            if (Schema::hasIndex('access_cards', 'idx_access_cards_created_at')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->dropIndex('idx_access_cards_created_at');
                });
            }
        }
    }
};
