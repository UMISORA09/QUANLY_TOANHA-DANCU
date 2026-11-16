<?php

namespace Tests\Feature;

use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use App\Services\AmenityBookingPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityTicketIndexBenchmarkTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    public function test_measure_mysql_plans_on_disposable_audit_data(): void
    {
        if (getenv('RUN_INDEX_BENCHMARK') !== '1') {
            $this->markTestSkipped('Opt-in benchmark requires a new disposable index_audit *_test database.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringContainsString('index_audit', DB::getDatabaseName());
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        $this->assertSame(0, DB::table('tickets')->count(), 'Use a new disposable database for each benchmark.');
        $this->createBookingFixture();
        $rows = max(1000, min(50000, (int) (getenv('INDEX_BENCHMARK_ROWS') ?: 10000)));
        $amenity = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        foreach (array_chunk(range(1, 1000), 250) as $chunk) {
            DB::table('amenities')->insert(array_map(fn (int $i) => array_merge($amenity, ['id' => (string) Str::uuid(), 'amenity_code' => 'AUDIT-'.$i, 'amenity_name' => 'Phòng Gym '.$i]), $chunk));
        }
        $category = (string) Str::uuid();
        DB::table('ticket_categories')->insert(['id' => $category, 'category_code' => 'AUDIT', 'category_name' => 'Audit']);
        $ticketIds = array_map(fn () => (string) Str::uuid(), range(1, $rows));
        foreach (array_chunk(range(0, $rows - 1), 250) as $chunk) {
            $records = array_fill_keys(['tickets', 'amenity_bookings', 'amenity_booking_payments', 'amenity_blackouts', 'ticket_history', 'ticket_attachments', 'user_in_app_notifications'], []);
            foreach ($chunk as $i) {
                $date = '2027-'.sprintf('%02d-%02d', 1 + intdiv($i % 336, 28), 1 + $i % 28);
                $created = now()->subSeconds($i)->toDateTimeString();
                $records['tickets'][] = ['id' => $ticketIds[$i], 'ticket_number' => 'AUDIT-'.$i, 'category_id' => $category, 'apartment_id' => $this->apartmentId,
                    'creator_user_id' => $this->residentUser->id, 'current_technician_user_id' => $i % 5 === 0 ? $this->adminUser->id : null,
                    'title' => 'Audit '.$i, 'description' => 'Index benchmark only', 'status' => ['NEW', 'RECEIVED', 'IN_PROGRESS', 'CLOSED'][$i % 4], 'created_at' => $created];
                $bookingId = (string) Str::uuid();
                $records['amenity_bookings'][] = ['id' => $bookingId, 'booking_code' => 'AUDIT-'.$i, 'checkin_qr_code' => 'AUDIT-'.$i,
                    'amenity_id' => $this->amenityId, 'apartment_id' => $this->apartmentId, 'resident_user_id' => $this->residentUser->id,
                    'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:30',
                    'status' => ['PENDING', 'APPROVED', 'CANCELLED', 'COMPLETED'][$i % 4], 'created_at' => $created];
                $records['amenity_booking_payments'][] = ['id' => (string) Str::uuid(), 'booking_id' => $bookingId, 'reference' => 'AUDIT-'.$i,
                    'amount' => 100000, 'bank_bin' => 'TEST', 'bank_name' => 'QA only', 'account_number' => 'QA', 'account_name' => 'QA',
                    'status' => ['WAITING_APPROVAL', 'REPORTED', 'REVIEW', 'PAID'][$i % 4], 'created_at' => $created, 'updated_at' => $created];
                $records['amenity_blackouts'][] = ['id' => (string) Str::uuid(), 'amenity_id' => $this->amenityId, 'blackout_date' => $date, 'reason' => 'Audit'];
                $records['ticket_history'][] = ['id' => (string) Str::uuid(), 'ticket_id' => $ticketIds[$i % 100], 'action_type' => 'CREATED', 'created_at' => $created];
                $records['ticket_attachments'][] = ['id' => (string) Str::uuid(), 'ticket_id' => $ticketIds[intdiv($i, 5)], 'file_name' => 'audit.png', 'file_url' => '', 'file_type' => 'IMAGE', 'uploaded_by_user_id' => $this->residentUser->id];
                $records['user_in_app_notifications'][] = ['id' => (string) Str::uuid(), 'recipient_user_id' => $this->residentUser->id,
                    'title' => 'Audit', 'body_message' => 'Audit', 'category' => $i % 10 === 0 ? 'TICKET' : 'SYSTEM', 'is_read' => $i % 2, 'created_at' => $created];
            }
            foreach ($records as $table => $batch) {
                DB::table($table)->insert($batch);
            }
        }
        $holds = fn () => DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->whereNull('deleted_at')->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES);
        $queries = [
            'blackout_date_function' => DB::table('amenity_blackouts')->where('amenity_id', $this->amenityId)->whereDate('blackout_date', '2027-01-01'),
            'blackout_direct' => DB::table('amenity_blackouts')->where('amenity_id', $this->amenityId)->where('blackout_date', '2027-01-01'),
            'holds_date_function' => AmenityBookingPaymentService::holdingQuery($holds()->whereDate('booking_date', '2027-01-01')),
            'holds_direct' => AmenityBookingPaymentService::holdingQuery($holds()->where('booking_date', '2027-01-01')),
            'amenity_list' => DB::table('amenities')->whereNull('deleted_at')->where('is_active', 1)->where('category_id', $this->categoryId)->where('block_id', $this->blockId)->orderByDesc('created_at')->limit(15),
            'amenity_exact_code' => DB::table('amenities')->where('amenity_code', DB::table('amenities')->where('id', $this->amenityId)->value('amenity_code')),
            'smart_search_candidates' => DB::table('amenities')->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')->whereNull('amenities.deleted_at')->where('amenities.category_id', $this->categoryId)->select('amenities.*', 'amenity_categories.category_name', 'amenity_categories.category_code', 'blocks.block_name', 'blocks.block_code'),
            'slots' => DB::table('amenity_time_slots')->where('amenity_id', $this->amenityId)->where('day_of_week', today()->addDays(2)->dayOfWeek)->orderBy('slot_start_time'),
            'booking_worklist' => DB::table('amenity_bookings as b')->join('amenities as a', 'a.id', '=', 'b.amenity_id')->leftJoin('amenity_booking_payments as p', 'p.booking_id', '=', 'b.id')->whereNull('b.deleted_at')->whereNull('a.deleted_at')->where('b.status', 'PENDING')->orderByDesc('b.created_at')->orderBy('b.id')->limit(15)->select('b.*', 'p.status as payment_status'),
            'resident_bookings' => app(DatabaseResidentAmenityBookingRepository::class)->bookings($this->residentUser->id)->orderByDesc('amenity_bookings.created_at')->orderBy('amenity_bookings.id')->limit(10),
            'resident_tickets' => DB::table('tickets')->whereNull('deleted_at')->where(fn ($q) => $q->where('creator_user_id', $this->residentUser->id)->orWhere('apartment_id', $this->apartmentId))->orderByDesc('created_at')->orderBy('id')->limit(15),
            'manager_tickets' => DB::table('tickets')->whereNull('deleted_at')->where('status', 'NEW')->whereExists(fn ($q) => $q->selectRaw('1')->from('apartments')->whereColumn('apartments.id', 'tickets.apartment_id')->whereNull('apartments.deleted_at')->where('block_id', $this->blockId))->orderByDesc('created_at')->orderBy('id')->limit(15),
            'technician_tickets' => DB::table('tickets')->whereNull('deleted_at')->where('current_technician_user_id', $this->adminUser->id)->orderByDesc('created_at')->orderBy('id')->limit(15),
            'history' => DB::table('ticket_history')->where('ticket_id', $ticketIds[0])->orderBy('created_at'),
            'attachments' => DB::table('ticket_attachments')->whereIn('ticket_id', array_slice($ticketIds, 0, 15)),
            'notifications' => DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->where('category', 'TICKET')->orderByDesc('created_at')->orderByDesc('id')->limit(15),
        ];
        $candidates = [
            'amenity_blackouts' => ['idx_audit_blackout_day' => '`amenity_id`, `blackout_date`'],
            'ticket_attachments' => ['idx_audit_attachment_ticket' => '`ticket_id`'],
            'ticket_history' => ['idx_audit_history_time' => '`ticket_id`, `created_at`'],
            'user_in_app_notifications' => ['idx_audit_notification_category' => '`recipient_user_id`, `category`, `created_at` DESC, `id` DESC'],
            'tickets' => ['idx_audit_ticket_tech_time' => '`current_technician_user_id`, `deleted_at`, `created_at` DESC, `id`', 'idx_audit_ticket_created' => '`deleted_at`, `created_at` DESC, `id`'],
            'amenity_bookings' => ['idx_audit_booking_resident_time' => '`resident_user_id`, `deleted_at`, `created_at` DESC, `id`', 'idx_audit_booking_status_time' => '`status`, `deleted_at`, `created_at` DESC, `id`'],
        ];
        $tables = ['amenities', 'amenity_categories', 'amenity_time_slots', 'amenity_blackouts', 'amenity_bookings', 'amenity_booking_payments', 'tickets', 'ticket_categories', 'ticket_attachments', 'ticket_history', 'user_in_app_notifications', 'residents', 'apartments', 'staff_profiles'];
        $evidence = ['database' => DB::getDatabaseName(), 'mysql' => DB::selectOne('SELECT VERSION() AS version')->version, 'rows_per_workload_table' => $rows];
        foreach ($tables as $table) {
            $evidence['inventory'][$table] = DB::select('SHOW INDEX FROM `'.$table.'`');
            $evidence['counts'][$table] = DB::table($table)->count();
        }
        $createdIndexes = [];
        try {
            foreach (['before', 'after'] as $phase) {
                if ($phase === 'after') {
                    foreach ($candidates as $table => $indexes) {
                        foreach ($indexes as $name => $columns) {
                            $this->assertFalse(Schema::hasIndex($table, $name));
                            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
                            $createdIndexes[] = [$table, $name];
                        }
                    }
                }
                foreach ($tables as $table) {
                    DB::select('ANALYZE TABLE `'.$table.'`');
                }
                foreach ($queries as $name => $query) {
                    $times = [];
                    for ($i = 0; $i < 7; $i++) {
                        $start = hrtime(true);
                        DB::select($query->toSql(), $query->getBindings());
                        $times[] = (hrtime(true) - $start) / 1e6;
                    }
                    sort($times);
                    $evidence['queries'][$name][$phase] = ['sql' => $query->toSql(), 'bindings' => $query->getBindings(), 'median_ms' => $times[3], 'samples_ms' => $times,
                        'explain' => DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()), 'analyze' => DB::select('EXPLAIN ANALYZE '.$query->toSql(), $query->getBindings())];
                }
            }
        } finally {
            foreach (array_reverse($createdIndexes) as [$table, $name]) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
        file_put_contents(storage_path('logs/amenity-ticket-index-benchmark.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->assertCount(16, $evidence['queries']);
    }
}
