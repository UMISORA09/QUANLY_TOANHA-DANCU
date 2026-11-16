<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidentAnnouncementService
{
    public function __construct(public AnnouncementService $announcements) {}

    private function residences(User $user): Builder
    {
        abort_unless($user->status === 'ACTIVE', 403);

        return DB::table('residents as residence')
            ->join('apartments as apartment', 'apartment.id', '=', 'residence.apartment_id')
            ->join('blocks as block', 'block.id', '=', 'apartment.block_id')
            ->join('floors as floor', 'floor.id', '=', 'apartment.floor_id')
            ->where('residence.user_id', $user->id)->where('residence.is_active', 1)
            ->whereNull('residence.deleted_at')->whereNull('apartment.deleted_at')
            ->whereNull('block.deleted_at')->whereNull('floor.deleted_at')
            ->whereColumn('floor.block_id', 'block.id')
            ->where('residence.stay_start_date', '<=', today()->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('residence.stay_end_date')->orWhere('residence.stay_end_date', '>=', today()->toDateString()));
    }

    public function query(User $user): Builder
    {
        $residences = $this->residences($user);
        abort_unless((clone $residences)->exists(), 403, 'Bạn chưa có hồ sơ cư trú còn hiệu lực. Vui lòng liên hệ ban quản lý.');

        return DB::table('announcements as announcement')->whereNull('announcement.deleted_at')
            ->where('announcement.status', 'PUBLISHED')->where('announcement.publish_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('announcement.expires_at')->orWhere('announcement.expires_at', '>', now()))
            ->where(function (Builder $q) use ($residences): void {
                $q->where('announcement.target_scope', 'ALL_BLOCKS')->orWhereExists(
                    (clone $residences)->selectRaw('1')->whereColumn('apartment.block_id', 'announcement.target_block_id')
                        ->where(fn (Builder $scope) => $scope->where('announcement.target_scope', 'BLOCK')
                            ->orWhere(fn (Builder $floor) => $floor->where('announcement.target_scope', 'FLOOR')->whereColumn('apartment.floor_id', 'announcement.target_floor_id')))
                );
            });
    }

    public function withReceipt(Builder $query, User $user): Builder
    {
        return $query->leftJoin('announcement_reads as receipt', fn ($join) => $join->on('receipt.announcement_id', '=', 'announcement.id')->where('receipt.user_id', $user->id))
            ->leftJoin('blocks as target_block', 'target_block.id', '=', 'announcement.target_block_id')
            ->leftJoin('floors as target_floor', 'target_floor.id', '=', 'announcement.target_floor_id')
            ->select('announcement.*', 'receipt.read_at', 'receipt.acknowledged_at', 'target_block.block_name', 'target_floor.floor_name');
    }

    public function find(User $user, string $id): object
    {
        return $this->withReceipt($this->query($user), $user)->where('announcement.id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function serialize(object $row, bool $detail = false): array
    {
        $data = [];
        foreach (['id', 'title', 'summary_excerpt', 'category', 'target_scope', 'block_name', 'floor_name'] as $key) {
            $data[$key] = $row->{$key};
        }
        foreach (['is_pinned', 'is_urgent', 'requires_acknowledgement'] as $key) {
            $data[$key] = (bool) $row->{$key};
        }
        foreach (['publish_at', 'expires_at', 'read_at', 'acknowledged_at'] as $key) {
            $data[$key] = $row->{$key} ? Carbon::parse($row->{$key})->toIso8601String() : null;
        }
        $data['version'] = (int) $row->version;
        if ($detail) {
            $data['content_html'] = $this->announcements->clean($row->content_html);
            $data['attachments'] = DB::table('announcement_attachments')->where('announcement_id', $row->id)->orderBy('created_at')->orderBy('id')->get(['id', 'file_name', 'mime_type', 'file_size_bytes'])->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function receipt(User $actor, string $id, int $version, bool $acknowledge): array
    {
        return DB::transaction(function () use ($actor, $id, $version, $acknowledge): array {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = $this->query($user)->where('announcement.id', $id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === $version, 409, 'Bài viết đã thay đổi. Vui lòng mở lại nội dung mới nhất.');
            abort_if($acknowledge && ! $row->requires_acknowledgement, 422, 'Bài viết không yêu cầu xác nhận.');
            $receipt = DB::table('announcement_reads')->where('announcement_id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $receipt) {
                DB::table('announcement_reads')->insert(['id' => (string) Str::uuid(), 'announcement_id' => $id, 'user_id' => $user->id, 'read_at' => now(), 'acknowledged_at' => $acknowledge ? now() : null]);
            } elseif ($acknowledge && ! $receipt->acknowledged_at) {
                DB::table('announcement_reads')->where('id', $receipt->id)->update(['acknowledged_at' => now()]);
            }

            return $this->serialize($this->find($user, $id), true);
        }, 3);
    }
}
