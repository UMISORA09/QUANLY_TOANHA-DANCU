<?php

namespace App\Console\Commands;

use App\Services\Search\BookingCodeSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBookingCodeSearch extends Command
{
    protected $signature = 'search:sync-booking-codes {--dry-run}';

    protected $description = 'Queue Elasticsearch synchronization for amenity booking codes';

    public function handle(BookingCodeSearch $search): int
    {
        $bookings = DB::table('amenity_bookings')->select('id');
        if ($this->option('dry-run')) {
            $this->info('Booking codes to synchronize: '.$bookings->count());

            return self::SUCCESS;
        }
        if (! BookingCodeSearch::enabled()) {
            $this->error('Set SEARCH_DRIVER=elasticsearch before synchronizing booking codes.');

            return self::FAILURE;
        }

        $search->configure();
        $bookings->chunkById(100, function ($rows) use ($search): void {
            foreach ($rows as $row) {
                $search->queue($row->id);
            }
        });

        return self::SUCCESS;
    }
}
