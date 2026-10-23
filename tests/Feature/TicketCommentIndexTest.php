<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketCommentIndexTest extends TestCase
{
    public function test_index_migration_preserves_existing_indexes_and_can_be_reapplied(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('Requires a dedicated MySQL *_test database.');
        }
        $migration = require database_path('migrations/2026_10_23_121354_add_ticket_comment_lookup_index.php');
        $name = 'idx_ticket_comments_ticket_created';
        try {
            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasIndex('ticket_comments', $name));
            $index = collect(Schema::getIndexes('ticket_comments'))->firstWhere('name', $name);
            $this->assertSame(['ticket_id', 'created_at', 'id'], $index['columns']);
            $migration->down();
            $this->assertFalse(Schema::hasIndex('ticket_comments', $name));
            DB::statement('ALTER TABLE ticket_comments ADD INDEX idx_ticket_comments_qa_cover (ticket_id, created_at, id)');
            $migration->up();
            $this->assertFalse(Schema::hasIndex('ticket_comments', $name));
            $migration->down();
            $this->assertTrue(Schema::hasIndex('ticket_comments', 'idx_ticket_comments_qa_cover'));
        } finally {
            if (Schema::hasIndex('ticket_comments', 'idx_ticket_comments_qa_cover')) {
                DB::statement('ALTER TABLE ticket_comments DROP INDEX idx_ticket_comments_qa_cover');
            }
            $migration->up();
        }
    }
}
