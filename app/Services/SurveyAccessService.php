<?php

namespace App\Services;

use App\Models\SatisfactionSurvey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SurveyAccessService
{
    public function isManager(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasRole(['BUILDING_MANAGER', 'SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'DEVELOPER']);
    }

    /**
     * Get the block ID assigned to a manager staff profile (null for super admins/all-building managers).
     */
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
     * Scope a SatisfactionSurvey query for management portal based on assigned building/block.
     */
    public function scopeForManager(User $user, Builder $query): Builder
    {
        abort_unless($this->isManager($user) && $user->hasPermission('SURVEY:VIEW'), 403, 'Bạn không có quyền xem khảo sát.');

        if ($user->isSuperAdmin()) {
            return $query;
        }

        $assignedBlockId = $this->assignedBlock($user);
        $query->where(function (Builder $q) use ($assignedBlockId): void {
            if ($assignedBlockId) {
                $q->whereNull('target_block_id')->orWhere('target_block_id', $assignedBlockId);
            } else {
                $q->whereNull('target_block_id');
            }
        });

        return $query;
    }

    /**
     * Get active resident apartments for a user.
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
     * Determine if a resident can access and participate in a survey.
     */
    public function canResidentAccessSurvey(User $user, SatisfactionSurvey $survey): bool
    {
        $apartments = $this->residentApartments($user);
        if ($apartments->isEmpty()) {
            return false;
        }

        if ($survey->target_block_id === null) {
            return true;
        }

        return $apartments->contains(fn ($apt) => $apt->block_id === $survey->target_block_id);
    }

    /**
     * Resolve the eligible apartment of a resident for a survey.
     */
    public function residentApartmentForSurvey(User $user, SatisfactionSurvey $survey): ?object
    {
        $apartments = $this->residentApartments($user);
        if ($apartments->isEmpty()) {
            return null;
        }

        if ($survey->target_block_id === null) {
            return $apartments->first();
        }

        return $apartments->firstWhere('block_id', $survey->target_block_id);
    }
}
