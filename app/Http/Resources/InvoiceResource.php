<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Bảo vệ dữ liệu cá nhân (PII): Chỉ cho phép hiển thị id, tên, SĐT và email của cư dân
        if ($this->relationLoaded('residentUser') || array_key_exists('resident_user', $data) || array_key_exists('residentUser', $data)) {
            $resident = $this->residentUser;
            $data['resident_user'] = $resident ? [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'phone_number' => $resident->phone_number,
                'email' => $resident->email,
            ] : null;

            unset($data['residentUser']);
        }

        return $data;
    }
}
