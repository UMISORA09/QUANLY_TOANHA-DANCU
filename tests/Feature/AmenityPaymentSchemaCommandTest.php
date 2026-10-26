<?php

namespace Tests\Feature;

use App\Console\Commands\PrepareAmenityPaymentSchema;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AmenityPaymentSchemaCommandTest extends TestCase
{
    public function test_disabled_build_and_preview_never_touch_database(): void
    {
        DB::shouldReceive('connection')->never();
        $this->assertSame(0, $this->runBootstrap(false));
        $this->assertSame(0, $this->runBootstrap(true, 'preview'));
    }

    public function test_matching_parent_keys_run_only_the_approved_migration_and_release_lock(): void
    {
        $connection = $this->database(false, false);
        $connection->shouldReceive('select')->once()->andReturn($this->parentColumns());
        $command = Mockery::mock(PrepareAmenityPaymentSchema::class)->makePartial();
        $command->shouldReceive('call')->once()->with('migrate', [
            '--path' => 'database/migrations/2026_10_07_133624_create_amenity_booking_payments_table.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->andReturn(0);
        $this->assertSame(0, $this->runBootstrap(command: $command));
    }

    public function test_existing_recorded_table_does_not_run_migrations(): void
    {
        $this->database(true, true)->shouldNotReceive('select');
        $this->assertSame(0, $this->runBootstrap());
    }

    public function test_partial_previous_migration_is_rejected_without_changing_schema(): void
    {
        $this->database(true, false)->shouldNotReceive('select');
        $this->assertSame(1, $this->runBootstrap());
    }

    public function test_incompatible_collation_is_rejected_before_migration(): void
    {
        $columns = $this->parentColumns();
        $columns[1]->COLLATION_NAME = 'utf8mb4_bin';
        $this->database(false, false)->shouldReceive('select')->once()->andReturn($columns);
        $this->assertSame(1, $this->runBootstrap());
    }

    private function database(bool $exists, bool $recorded): MockInterface
    {
        Schema::swap(Mockery::mock(Builder::class));
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->with("SELECT GET_LOCK('amenity-payment-schema', 10) AS acquired")->once()->andReturn((object) ['acquired' => 1]);
        $connection->shouldReceive('selectOne')->with("SELECT RELEASE_LOCK('amenity-payment-schema') AS released")->once()->andReturn((object) ['released' => 1]);
        $connection->shouldReceive('table->where->exists')->once()->andReturn($recorded);
        Schema::shouldReceive('hasTable')->with('migrations')->once()->andReturn(true);
        Schema::shouldReceive('hasTable')->with('amenity_booking_payments')->once()->andReturn($exists);

        return $connection;
    }

    /** @return array<int, object> */
    private function parentColumns(): array
    {
        return array_map(fn (string $table): object => (object) ['TABLE_NAME' => $table, 'COLUMN_TYPE' => 'char(36)', 'CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_unicode_ci', 'COLUMN_KEY' => 'PRI', 'ENGINE' => 'InnoDB'], ['amenity_bookings', 'users']);
    }

    private function runBootstrap(bool $enabled = true, string $environment = 'production', ?PrepareAmenityPaymentSchema $command = null): int
    {
        $originalFlag = getenv('AMENITY_PAYMENT_SCHEMA_BOOTSTRAP');
        $originalEnvironment = getenv('VERCEL_ENV');
        putenv('AMENITY_PAYMENT_SCHEMA_BOOTSTRAP='.($enabled ? '1' : '0'));
        putenv('VERCEL_ENV='.$environment);
        try {
            $command ??= new PrepareAmenityPaymentSchema;
            $command->setLaravel($this->app);
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

            return $command->handle();
        } finally {
            putenv($originalFlag === false ? 'AMENITY_PAYMENT_SCHEMA_BOOTSTRAP' : 'AMENITY_PAYMENT_SCHEMA_BOOTSTRAP='.$originalFlag);
            putenv($originalEnvironment === false ? 'VERCEL_ENV' : 'VERCEL_ENV='.$originalEnvironment);
        }
    }
}
