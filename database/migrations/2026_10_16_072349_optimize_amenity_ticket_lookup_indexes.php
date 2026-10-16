<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OWNER = 'amenity-ticket-lookup:20261016';

    /** @var array<string, array{string, array<string, string>}> */
    private const INDEXES = [
        'idx_blackouts_amenity_date' => ['amenity_blackouts', ['amenity_id' => 'A', 'blackout_date' => 'A']],
        'idx_ticket_attachments_ticket' => ['ticket_attachments', ['ticket_id' => 'A']],
        'idx_ticket_history_ticket_created' => ['ticket_history', ['ticket_id' => 'A', 'created_at' => 'A']],
        'idx_notifications_recipient_category_created' => ['user_in_app_notifications', ['recipient_user_id' => 'A', 'category' => 'A', 'created_at' => 'D', 'id' => 'D']],
        'idx_tickets_technician_created' => ['tickets', ['current_technician_user_id' => 'A', 'deleted_at' => 'A', 'created_at' => 'D', 'id' => 'A']],
        'idx_tickets_created' => ['tickets', ['deleted_at' => 'A', 'created_at' => 'D', 'id' => 'A']],
        'idx_bookings_resident_created' => ['amenity_bookings', ['resident_user_id' => 'A', 'deleted_at' => 'A', 'created_at' => 'D', 'id' => 'A']],
        'idx_bookings_status_created' => ['amenity_bookings', ['status' => 'A', 'deleted_at' => 'A', 'created_at' => 'D', 'id' => 'A']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (self::INDEXES as $name => [$table, $columns]) {
            $indexes = $this->indexes($table);
            if ($indexes->contains(fn ($parts) => $this->covers($parts, $columns))) {
                continue;
            }
            if ($indexes->has($name)) {
                throw new RuntimeException("Index {$name} exists with an incompatible definition.");
            }
            $definition = collect($columns)->map(fn (string $direction, string $column) => '`'.$column.'` '.($direction === 'D' ? 'DESC' : 'ASC'))->implode(', ');
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$definition}) COMMENT '".self::OWNER."'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (array_reverse(self::INDEXES, true) as $name => [$table, $columns]) {
            $indexes = $this->indexes($table);
            $parts = $indexes->get($name);
            // The persistent index comment identifies ownership across separate migrate/rollback processes.
            if ($parts && count($parts) === count($columns) && $parts->every(fn ($part) => $part->INDEX_COMMENT === self::OWNER && (int) $part->NON_UNIQUE === 1) && $parts->pluck('COLLATION', 'COLUMN_NAME')->all() === $columns && $this->covers($parts, $columns)) {
                $requiredByForeignKey = collect(Schema::getForeignKeys($table))->contains(function (array $foreign) use ($indexes, $name, $columns): bool {
                    $prefix = $foreign['columns'];

                    return array_slice(array_keys($columns), 0, count($prefix)) === $prefix
                        && ! $indexes->except($name)->contains(fn ($other) => $this->covers($other, array_fill_keys($prefix, 'A')) && $other->take(count($prefix))->every(fn ($part) => $part->COLLATION === 'A'));
                });
                if ($requiredByForeignKey) {
                    continue;
                }
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    private function indexes(string $table): Collection
    {
        return collect(DB::select('SELECT INDEX_NAME, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, INDEX_COMMENT, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]))->groupBy('INDEX_NAME');
    }

    /** @param array<string, string> $columns */
    private function covers(Collection $parts, array $columns): bool
    {
        $prefix = $parts->take(count($columns));

        if ($prefix->count() !== count($columns)
            || ! $prefix->every(fn ($part) => $part->INDEX_TYPE === 'BTREE' && $part->SUB_PART === null)
            || $prefix->pluck('COLUMN_NAME')->all() !== array_keys($columns)) {
            return false;
        }
        // These lookups use equality prefixes followed by created_at/id; a backward scan reverses both sort columns.
        $actualOrder = $prefix->pluck('COLLATION')->take(-2)->values()->all();
        $expectedOrder = array_slice(array_values($columns), -2);

        return count($columns) <= 2 || $actualOrder === $expectedOrder
            || $actualOrder === array_map(fn (string $direction) => $direction === 'A' ? 'D' : 'A', $expectedOrder);
    }
};
