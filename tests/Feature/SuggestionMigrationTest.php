<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuggestionMigrationTest extends TestCase
{
    public function test_repeated_up_and_rollback_remigrate_preserve_existing_rows_without_duplicate_indexes(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::create('suggestions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('box_id')->nullable();
            $table->string('status')->default('RECEIVED');
            $table->boolean('is_anonymous')->default(false);
            $table->text('official_response_text')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('topic_category')->nullable();
        });
        DB::table('suggestions')->insert(['id' => 'existing-row', 'topic_category' => 'KHAC']);
        $migration = require database_path('migrations/2026_10_26_170000_enhance_suggestions_table_for_f12.php');
        $indexes = ['idx_suggestions_created_at', 'idx_suggestions_anon_hash', 'idx_suggestions_idempotency', 'idx_suggestions_topic'];
        $migration->up();
        $migration->up();
        foreach ($indexes as $index) {
            $this->assertTrue(Schema::hasIndex('suggestions', $index));
        }
        $migration->down();
        foreach ($indexes as $index) {
            $this->assertFalse(Schema::hasIndex('suggestions', $index));
        }
        $this->assertFalse(Schema::hasColumn('suggestions', 'version'));
        $migration->up();
        foreach ($indexes as $index) {
            $this->assertTrue(Schema::hasIndex('suggestions', $index));
        }
        $this->assertSame('KHAC', DB::table('suggestions')->where('id', 'existing-row')->value('topic_category'));
    }
}
