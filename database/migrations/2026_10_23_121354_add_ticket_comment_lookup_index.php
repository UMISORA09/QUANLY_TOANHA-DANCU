<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (Schema::getIndexes('ticket_comments') as $index) {
            if (array_slice($index['columns'], 0, 3) === ['ticket_id', 'created_at', 'id']) {
                return;
            }
        }
        DB::statement("ALTER TABLE ticket_comments ADD INDEX idx_ticket_comments_ticket_created (ticket_id, created_at, id) COMMENT 'ticket-discussion:20261023'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $index = DB::select("SHOW INDEX FROM ticket_comments WHERE Key_name = 'idx_ticket_comments_ticket_created'");
        if ($index && collect($index)->every(fn (object $part): bool => $part->Index_comment === 'ticket-discussion:20261023')) {
            if (! Schema::hasIndex('ticket_comments', 'ticket_id')) {
                Schema::table('ticket_comments', fn (Blueprint $table) => $table->index('ticket_id', 'ticket_id'));
            }
            Schema::table('ticket_comments', fn (Blueprint $table) => $table->dropIndex('idx_ticket_comments_ticket_created'));
        }
    }
};
