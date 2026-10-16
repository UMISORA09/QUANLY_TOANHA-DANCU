<?php

namespace Tests\Feature;

use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityTicketDatabaseIndexTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    private function migration(): Migration
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertStringEndsWith('_test', DB::getDatabaseName());

        return require database_path('migrations/2026_10_16_072349_optimize_amenity_ticket_lookup_indexes.php');
    }

    public function test_migration_is_idempotent_and_rollback_preserves_equivalent_preexisting_indexes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL index metadata and persistent ownership comments.');
        }
        $migration = $this->migration();
        $migration->down();
        $original = Schema::getIndexes('amenity_blackouts');
        $this->assertFalse(Schema::hasIndex('amenity_blackouts', 'idx_blackouts_amenity_date'));
        try {
            DB::statement('ALTER TABLE amenity_blackouts ADD UNIQUE INDEX existing_blackout_lookup (amenity_id DESC, blackout_date DESC, id)');
            DB::statement('ALTER TABLE ticket_history ADD INDEX existing_history_lookup (ticket_id, created_at DESC)');
            $migration->up();
            $migration->up();
            $this->assertFalse(Schema::hasIndex('amenity_blackouts', 'idx_blackouts_amenity_date'));
            $this->assertFalse(Schema::hasIndex('ticket_history', 'idx_ticket_history_ticket_created'));
            $this->assertTrue(Schema::hasIndex('ticket_attachments', 'idx_ticket_attachments_ticket'));
            $this->migration()->down();
            $this->assertTrue(Schema::hasIndex('amenity_blackouts', 'existing_blackout_lookup'));
            $this->assertTrue(Schema::hasIndex('ticket_history', 'existing_history_lookup'));
            $this->assertFalse(Schema::hasIndex('ticket_attachments', 'idx_ticket_attachments_ticket'));
        } finally {
            DB::statement('ALTER TABLE amenity_blackouts DROP INDEX existing_blackout_lookup');
            DB::statement('ALTER TABLE ticket_history DROP INDEX existing_history_lookup');
            $this->assertEquals($original, Schema::getIndexes('amenity_blackouts'));
            $migration->up();
        }
    }

    public function test_rollback_does_not_drop_an_unowned_index_with_the_same_name(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL index ownership comments.');
        }
        $migration = $this->migration();
        $migration->down();
        DB::statement('ALTER TABLE ticket_attachments ADD INDEX idx_ticket_attachments_ticket (ticket_id)');
        try {
            $migration->up();
            $this->migration()->down();
            $this->assertTrue(Schema::hasIndex('ticket_attachments', 'idx_ticket_attachments_ticket'));
        } finally {
            DB::statement('ALTER TABLE ticket_attachments DROP INDEX idx_ticket_attachments_ticket');
            $migration->up();
        }
    }

    public function test_rollback_preserves_an_index_still_required_by_a_foreign_key(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL foreign key index dependency.');
        }
        $migration = $this->migration();
        $migration->down();
        DB::statement('ALTER TABLE amenity_blackouts ADD CONSTRAINT audit_blackout_amenity_fk FOREIGN KEY (amenity_id) REFERENCES amenities(id)');
        try {
            $migration->up();
            $this->migration()->down();
            $this->assertNotEmpty(Schema::getForeignKeys('amenity_blackouts'));
            $this->assertTrue(collect(Schema::getIndexes('amenity_blackouts'))->contains(fn (array $index) => ($index['columns'][0] ?? null) === 'amenity_id'));
        } finally {
            DB::statement('ALTER TABLE amenity_blackouts DROP FOREIGN KEY audit_blackout_amenity_fk');
            if (Schema::hasIndex('amenity_blackouts', 'audit_blackout_amenity_fk')) {
                DB::statement('ALTER TABLE amenity_blackouts DROP INDEX audit_blackout_amenity_fk');
            }
            $migration->up();
        }
    }

    public function test_date_lookup_matches_only_the_requested_day_and_retains_row_locks(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        DB::beginTransaction();
        try {
            $this->createBookingFixture();
            $repository = app(DatabaseResidentAmenityBookingRepository::class);
            config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
            $this->travelTo(Carbon::parse('2027-01-02 00:05:00', 'Asia/Ho_Chi_Minh'));
            foreach (['2027-01-01', '2027-01-02', '2027-01-03'] as $date) {
                DB::table('amenity_blackouts')->insert(['id' => (string) Str::uuid(), 'amenity_id' => $this->amenityId, 'blackout_date' => $date, 'reason' => 'Date regression']);
                DB::table('amenity_bookings')->insert(['id' => (string) Str::uuid(), 'booking_code' => Str::uuid(), 'checkin_qr_code' => Str::uuid(), 'amenity_id' => $this->amenityId,
                    'apartment_id' => $this->apartmentId, 'resident_user_id' => $this->residentUser->id, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:30', 'status' => 'PENDING']);
            }
            DB::enableQueryLog();
            foreach (['2027-01-01', '2027-01-02', '2027-01-03'] as $date) {
                $this->assertSame([$date], $repository->blackouts($this->amenityId, $date, true)->pluck('blackout_date')->all());
                $this->assertSame([$date], $repository->holdingBookings($this->amenityId, $date, true)->pluck('booking_date')->all());
            }
            $queries = DB::getQueryLog();
            $this->assertCount(6, $queries);
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('date(', strtolower($query['query']));
                $this->assertStringContainsString('for update', strtolower($query['query']));
            }
        } finally {
            DB::disableQueryLog();
            DB::rollBack();
            $this->travelBack();
        }
    }
}
