<?php

namespace App\Modules\Reception\Services;

use App\Modules\Reception\Models\Apartment;
use App\Modules\Reception\Models\Resident;
use Illuminate\Database\Eloquent\Collection;

/**
 * ReceptionQueryService — Public API cho module khác truy vấn data cư dân & căn hộ.
 *
 * Đây là service quan trọng nhất vì Billing & ResidentService đều cần data
 * Resident và Apartment. Các module KHÔNG được query trực tiếp Model của Reception.
 */
class ReceptionQueryService
{
    /**
     * Lấy thông tin cư dân theo ID.
     */
    public function getResidentById(int $residentId): ?Resident
    {
        return Resident::with('apartment')->find($residentId);
    }

    /**
     * Lấy thông tin căn hộ theo ID.
     */
    public function getApartmentById(int $apartmentId): ?Apartment
    {
        return Apartment::with('residents')->find($apartmentId);
    }

    /**
     * Lấy danh sách cư dân theo căn hộ.
     */
    public function getResidentsByApartment(int $apartmentId): Collection
    {
        return Resident::where('apartment_id', $apartmentId)
            ->where('status', Resident::STATUS_ACTIVE)
            ->get();
    }

    /**
     * Lấy tất cả cư dân đang hoạt động.
     */
    public function getAllActiveResidents(): Collection
    {
        return Resident::where('status', Resident::STATUS_ACTIVE)
            ->with('apartment')
            ->get();
    }

    /**
     * Tìm cư dân theo email.
     */
    public function findResidentByEmail(string $email): ?Resident
    {
        return Resident::where('email', $email)->first();
    }
}
