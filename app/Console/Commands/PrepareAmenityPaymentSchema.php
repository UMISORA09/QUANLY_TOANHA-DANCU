<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

class PrepareAmenityPaymentSchema extends Command
{
    protected $signature = 'amenities:prepare-payment-schema';

    protected $description = 'Apply only the approved missing payment-table migration during an explicitly enabled Production build';

    public function handle(): int
    {
        if (getenv('AMENITY_PAYMENT_SCHEMA_BOOTSTRAP') !== '1' || getenv('VERCEL_ENV') !== 'production') {
            $this->info('Payment schema bootstrap disabled.');

            return self::SUCCESS;
        }

        $connection = DB::connection();
        $locked = false;
        try {
            if ($connection->getDriverName() !== 'mysql') {
                throw new LogicException('MySQL required.');
            }
            $locked = (int) $connection->selectOne("SELECT GET_LOCK('amenity-payment-schema', 10) AS acquired")->acquired === 1;
            if (! $locked) {
                throw new LogicException('Another schema operation is running.');
            }

            $migration = '2026_10_07_133624_create_amenity_booking_payments_table';
            if (! Schema::hasTable('migrations')) {
                throw new LogicException('Migration ledger missing.');
            }
            $recorded = $connection->table('migrations')->where('migration', $migration)->exists();
            $exists = Schema::hasTable('amenity_booking_payments');
            if ($exists !== $recorded) {
                throw new LogicException('Payment table and migration ledger disagree.');
            }
            if ($exists) {
                $this->info('Approved payment migration already applied.');

                return self::SUCCESS;
            }

            $columns = $connection->select("SELECT c.TABLE_NAME, c.COLUMN_TYPE, c.CHARACTER_SET_NAME, c.COLLATION_NAME, c.COLUMN_KEY, t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ('amenity_bookings', 'users') AND c.COLUMN_NAME = 'id' ORDER BY c.TABLE_NAME");
            if (count($columns) !== 2) {
                throw new LogicException('Foreign-key parents missing.');
            }
            foreach ($columns as $column) {
                if (strtolower($column->COLUMN_TYPE) !== 'char(36)' || $column->COLUMN_KEY !== 'PRI' || $column->ENGINE !== 'InnoDB' || $column->CHARACTER_SET_NAME !== config('database.connections.mysql.charset')) {
                    throw new LogicException('Foreign-key parent format incompatible.');
                }
            }
            if ($columns[0]->COLLATION_NAME !== $columns[1]->COLLATION_NAME) {
                throw new LogicException('Foreign-key parent collations differ.');
            }

            $this->info('Foreign-key preflight passed; applying only the approved payment migration.');

            return $this->call('migrate', ['--path' => 'database/migrations/'.$migration.'.php', '--force' => true, '--no-interaction' => true]);
        } catch (Throwable $exception) {
            $this->error($exception instanceof LogicException ? $exception->getMessage() : 'Payment schema bootstrap failed. Inspect database access before retrying; no other migration was requested.');

            return self::FAILURE;
        } finally {
            if ($locked) {
                $connection->selectOne("SELECT RELEASE_LOCK('amenity-payment-schema') AS released");
            }
        }
    }
}
