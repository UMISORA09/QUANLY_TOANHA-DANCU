<?php

namespace Tests\Feature;

use App\DTOs\AmenityFilterDTO;
use App\Repositories\Eloquent\DatabaseAmenityRepository;
use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use App\Services\Search\Drivers\SmartSearchDriver;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityIndexOptimizationTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    public function test_catalog_only_loads_related_metadata_for_the_page(): void
    {
        $this->createBookingFixture();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = app(DatabaseAmenityRepository::class)->getPaginated(AmenityFilterDTO::fromArray(['category_id' => $this->categoryId, 'limit' => 15]));
            $queries = collect(DB::getQueryLog());
            $this->assertCount(1, $page['items']);
            $this->assertSame('Booking Test Block', $page['items'][0]['block_name']);
            $this->assertLessThanOrEqual(6, $queries->count());
            foreach (['amenity_categories', 'blocks'] as $table) {
                $sql = $queries->first(fn (array $query): bool => str_contains($query['query'], 'from `'.$table.'`'))['query'];
                $this->assertStringContainsString('where `id` in (', $sql);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_date_lookups_preserve_results_without_wrapping_indexed_columns(): void
    {
        $this->createBookingFixture();
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        DB::table('amenity_blackouts')->insert(['id' => (string) Str::uuid(), 'amenity_id' => $this->amenityId, 'blackout_date' => $this->bookingDate, 'reason' => 'QA']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $repository = app(DatabaseResidentAmenityBookingRepository::class);
            $this->assertCount(1, $repository->holdingBookings($this->amenityId, $this->bookingDate));
            $this->assertCount(1, $repository->blackouts($this->amenityId, $this->bookingDate));
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('date(', strtolower($query['query']));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_lookup_indexes_keep_unique_codes_and_remove_the_redundant_index(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->assertTrue(Schema::hasIndex('amenity_blackouts', 'idx_amenity_blackouts_lookup'));
        $this->assertTrue(Schema::hasIndex('amenities', 'amenity_code', 'unique'));
        $this->assertFalse(Schema::hasIndex('amenities', 'idx_amenities_code_btree'));
    }

    public function test_representative_mysql_lookup_benchmark(): void
    {
        if (! getenv('AMENITY_INDEX_BENCHMARK') || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Opt-in benchmark on a dedicated MySQL *_test database.');
        }
        $this->createBookingFixture();
        $booking = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        $template = (array) DB::table('amenity_bookings')->where('id', $booking['id'])->first();
        $rows = [];
        for ($index = 0; $index < 6000; $index++) {
            $id = (string) Str::uuid();
            $rows[] = [...$template, 'id' => $id, 'booking_code' => 'QA-'.$id, 'checkin_qr_code' => 'QR-'.$id, 'booking_date' => today()->addDays($index % 365)->toDateString(), 'created_at' => now()->subSeconds($index)->toDateTimeString()];
            if (count($rows) === 250) {
                DB::table('amenity_bookings')->insert($rows);
                $rows = [];
            }
        }
        for ($index = 0; $index < 1200; $index++) {
            $rows[] = ['id' => (string) Str::uuid(), 'amenity_id' => $this->amenityId, 'blackout_date' => today()->addDays($index)->toDateString(), 'reason' => 'QA benchmark'];
            if (count($rows) === 200) {
                DB::table('amenity_blackouts')->insert($rows);
                $rows = [];
            }
        }
        $base = DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->whereNull('deleted_at')->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES);
        $old = (clone $base)->whereDate('booking_date', $this->bookingDate);
        $optimized = (clone $base)->where('booking_date', $this->bookingDate);
        $this->assertEqualsCanonicalizing($old->pluck('id')->all(), $optimized->pluck('id')->all());
        $queries = [
            'conflict_date_function' => $old,
            'conflict_date_equality' => $optimized,
            'blackout' => DB::table('amenity_blackouts')->where('amenity_id', $this->amenityId)->where('blackout_date', $this->bookingDate),
            'resident_history' => app(DatabaseResidentAmenityBookingRepository::class)->bookings($this->residentUser->id)->orderByDesc('amenity_bookings.created_at')->orderBy('amenity_bookings.id')->limit(10),
        ];
        foreach ($queries as $name => $query) {
            $this->benchmarkQuery($name, $query);
        }
    }

    private function benchmarkQuery(string $name, Builder $query): void
    {
        $samples = [];
        for ($sample = 0; $sample < 7; $sample++) {
            $start = hrtime(true);
            $query->get();
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
        sort($samples);
        $plan = DB::select('EXPLAIN ANALYZE '.$query->toSql(), $query->getBindings());
        fwrite(STDERR, json_encode(['query' => $name, 'median_ms' => round($samples[3], 3), 'plan' => $plan], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->assertNotEmpty($plan);
    }

    public function test_representative_search_memory_benchmark(): void
    {
        if (! getenv('AMENITY_INDEX_BENCHMARK')) {
            $this->markTestSkipped('Opt-in search benchmark on a dedicated *_test database.');
        }
        $this->createBookingFixture();
        $template = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        $rows = [];
        for ($index = 0; $index < 2000; $index++) {
            $rows[] = [...$template, 'id' => (string) Str::uuid(), 'amenity_code' => 'BENCH-'.$index, 'amenity_name' => 'Phòng Gym Benchmark '.$index, 'rules_and_regulations' => str_repeat('Giữ vệ sinh. ', 100)];
            if (count($rows) === 200) {
                DB::table('amenities')->insert($rows);
                $rows = [];
            }
        }
        $driver = new SmartSearchDriver;
        $filters = ['category_id' => $this->categoryId];
        $driver->search('amenities', 'gym', $filters);
        $times = [];
        $peaks = [];
        for ($sample = 0; $sample < 7; $sample++) {
            memory_reset_peak_usage();
            $startMemory = memory_get_usage();
            $start = hrtime(true);
            $result = $driver->search('amenities', 'gym', $filters, ['per_page' => 15]);
            $times[] = (hrtime(true) - $start) / 1e6;
            $peaks[] = memory_get_peak_usage() - $startMemory;
            $this->assertSame(2000, $result->total);
            $this->assertCount(15, $result->items);
            $this->assertArrayHasKey('rules_and_regulations', $result->items[0]);
            unset($result);
        }
        sort($times);
        sort($peaks);
        fwrite(STDERR, json_encode(['query' => 'fuzzy_search_2000', 'median_ms' => round($times[3], 3), 'median_php_peak_bytes' => $peaks[3]]).PHP_EOL);
    }
}
