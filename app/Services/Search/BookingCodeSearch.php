<?php

namespace App\Services\Search;

use App\Jobs\SyncBookingCodeIndex;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class BookingCodeSearch
{
    public static function enabled(): bool
    {
        return config('search.default') === 'elasticsearch';
    }

    public function queue(string $id): void
    {
        if (! self::enabled()) {
            return;
        }

        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection() || config('queue.connections.database.after_commit')) {
            throw new LogicException('Booking code search jobs must be written in the business transaction.');
        }

        SyncBookingCodeIndex::dispatch($id)->onConnection('database')->onQueue('booking-code-search')->beforeCommit();
    }

    public function configure(): void
    {
        $this->index()->configure([
            'booking_code' => ['type' => 'text', 'analyzer' => 'cassavas_text'],
            'revision' => ['type' => 'keyword'],
            'is_deleted' => ['type' => 'boolean'],
        ]);
    }

    public function publish(string $id): void
    {
        $row = DB::table('amenity_bookings')->where('id', $id)->first(['id', 'booking_code', 'deleted_at']);
        if ($row === null) {
            return;
        }

        $this->configure();
        $deleted = $row->deleted_at !== null;
        $this->index()->write($row->id, [
            'revision' => $row->id.':1',
            'is_deleted' => $deleted,
            ...($deleted ? [] : ['booking_code' => $row->booking_code]),
        ], $deleted ? 2 : 1);
    }

    /** @return list<string> */
    public function matches(User $user, string $amenityId, string $keyword): array
    {
        $scope = DB::table('amenity_bookings as booking')
            ->join('amenities as amenity', 'amenity.id', '=', 'booking.amenity_id')
            ->join('apartments as apartment', 'apartment.id', '=', 'booking.apartment_id')
            ->where('booking.amenity_id', $amenityId)->whereNull('booking.deleted_at')->whereNull('amenity.deleted_at');
        if (! $user->isSuperAdmin() && ! $user->hasRole('ADMIN')) {
            $block = DB::table('staff_profiles')->where('user_id', $user->id)->value('assigned_block_id');
            abort_unless($block, 403, 'Chưa được phân công tòa nhà.');
            $scope->where('apartment.block_id', $block)
                ->where(fn ($query) => $query->whereNull('amenity.block_id')->orWhere('amenity.block_id', $block));
        }

        $ids = [];
        if (self::enabled()) {
            $readScope = fn () => (clone $scope)->limit(10001)->pluck('booking.id')
                ->mapWithKeys(fn (string $id) => [$id => 1])->all();
            $ids = $this->index()->matchingRevisions($readScope, $keyword, ['booking_code'], requireAllTerms: true) ?? [];
        }

        return $scope->where(fn ($query) => $query->whereIn('booking.id', $ids)
            ->orWhereRaw('LOCATE(LOWER(?), LOWER(booking.booking_code)) > 0', [$keyword]))
            ->pluck('booking.id')->all();
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.booking_codes_index', 'cassavas_booking_codes_v1'));
    }
}
