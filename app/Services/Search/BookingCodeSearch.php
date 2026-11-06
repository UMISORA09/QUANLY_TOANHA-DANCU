<?php

namespace App\Services\Search;

use App\Jobs\SyncBookingCodeIndex;
use App\Models\User;
use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class BookingCodeSearch
{
    use HasPerformanceCache;

    public static function enabled(): bool
    {
        return config('search.default') === 'elasticsearch';
    }

    public function queue(string $id): void
    {
        $this->queueMany([$id]);
    }

    /** @param list<string> $ids */
    public function queueMany(array $ids): void
    {
        if ($ids === [] || ! self::enabled()) {
            return;
        }

        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection() || config('queue.connections.database.after_commit')) {
            throw new LogicException('Booking code search jobs must be written in the business transaction.');
        }

        Queue::connection('database')->bulk(array_map(fn (string $id): SyncBookingCodeIndex => new SyncBookingCodeIndex($id), $ids), '', 'booking-code-search');
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
        $this->flushSearchEntityCache('booking_codes');
        $this->flushSearchEntityCache('booking_codes_matches');
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

        $cacheParams = [
            'user_id' => $user->id,
            'amenity_id' => $amenityId,
            'q' => trim(mb_strtolower($keyword)),
        ];

        return $this->rememberSearchResult('booking_codes_matches', $cacheParams, function () use ($scope, $keyword): array {
            $ids = [];
            if (self::enabled()) {
                $readScope = fn () => (clone $scope)->limit(10001)->pluck('booking.id')
                    ->mapWithKeys(fn (string $id) => [$id => 1])->all();
                $ids = $this->index()->matchingRevisions($readScope, $keyword, ['booking_code'], requireAllTerms: true) ?? [];
            }

            return $scope->where(fn ($query) => $query->whereIn('booking.id', $ids)
                ->orWhereRaw('LOCATE(LOWER(?), LOWER(booking.booking_code)) > 0', [$keyword]))
                ->pluck('booking.id')->all();
        }, 300);
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.booking_codes_index', 'cassavas_booking_codes_v1'));
    }
}
