<?php

namespace App\Services;

use App\Models\Suggestion;
use App\Models\SuggestionBox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SuggestionAccessService
{
    public function isManager(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasRole(['BUILDING_MANAGER', 'SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'DEVELOPER']);
    }

    public function assignedBlock(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $profile = DB::table('staff_profiles')->where('user_id', $user->id)->first();
        if (! $profile) {
            return null;
        }

        return $profile->assigned_block_id;
    }

    /**
     * Scope query for management portal based on assigned building/block.
     */
    public function scopeForManager(User $user, Builder $query): Builder
    {
        abort_unless($this->isManager($user) && $user->hasPermission('SUGGESTION:VIEW'), 403, 'Bạn không có quyền xem hòm thư góp ý.');

        if ($user->isSuperAdmin()) {
            return $query;
        }

        $blockId = $this->assignedBlock($user);
        $query->whereHas('box', function (Builder $boxQuery) use ($blockId): void {
            if ($blockId) {
                $boxQuery->whereNull('block_id')->orWhere('block_id', $blockId);
            } else {
                $boxQuery->whereNull('block_id');
            }
        });

        return $query;
    }

    /**
     * Get active resident apartments.
     *
     * @return Collection<int, object>
     */
    public function residentApartments(User $user): Collection
    {
        return DB::table('residents')
            ->join('apartments', 'apartments.id', '=', 'residents.apartment_id')
            ->join('blocks', 'blocks.id', '=', 'apartments.block_id')
            ->where('residents.user_id', $user->id)
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at')
            ->where('residents.stay_start_date', '<=', today()->toDateString())
            ->where(fn ($q) => $q->whereNull('residents.stay_end_date')->orWhere('residents.stay_end_date', '>=', today()->toDateString()))
            ->select('apartments.id', 'apartments.apartment_number', 'apartments.block_id', 'blocks.block_name')
            ->orderBy('apartments.apartment_number')
            ->get();
    }

    /**
     * Validate whether a resident can submit to a given suggestion box.
     */
    public function canResidentSubmitToBox(User $user, SuggestionBox $box): bool
    {
        $apartments = $this->residentApartments($user);
        if ($apartments->isEmpty()) {
            return false;
        }

        // If box is general (block_id is null), any active resident can submit
        if ($box->block_id === null) {
            return true;
        }

        // Otherwise resident must have an apartment in the corresponding block
        return $apartments->contains('block_id', $box->block_id);
    }
}
