<?php

namespace App\Http\Requests;

use App\Models\AccessCard;
use App\Models\Resident;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AccessCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('card_uid') && is_string($this->input('card_uid'))) {
            $merge['card_uid'] = strtoupper(trim($this->input('card_uid')));
        }
        if ($this->has('card_number') && is_string($this->input('card_number'))) {
            $merge['card_number'] = strtoupper(trim($this->input('card_number')));
        }
        if ($this->has('card_type') && is_string($this->input('card_type'))) {
            $merge['card_type'] = strtoupper(trim($this->input('card_type')));
        }
        if ($this->has('status') && is_string($this->input('status'))) {
            $merge['status'] = strtoupper(trim($this->input('status')));
        }
        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $cardId = $this->route('id') ?? $this->route('card') ?? $this->input('id');
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        if ($isUpdate) {
            return [
                'card_uid' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:60',
                    Rule::unique('access_cards', 'card_uid')->ignore($cardId, 'id'),
                ],
                'card_number' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:40',
                    Rule::unique('access_cards', 'card_number')->ignore($cardId, 'id'),
                ],
                'card_type' => 'sometimes|required|string|in:RESIDENT_ALL_ACCESS,PARKING_ONLY,ELEVATOR_ONLY,VISITOR_PASS',
                'assigned_user_id' => 'nullable|uuid|exists:users,id',
                'assigned_apartment_id' => 'nullable|uuid|exists:apartments,id',
                'assigned_vehicle_id' => 'nullable|uuid|exists:vehicles,id',
                'issued_date' => 'sometimes|required|date',
                'expiry_date' => 'nullable|date',
                'status' => 'sometimes|required|string|in:ACTIVE,LOCKED,LOCKED_TEMPORARY,LOST_REPORTED,REVOKED',
                'deposit_fee' => 'nullable|numeric|min:0',
            ];
        }

        return [
            'card_uid' => 'required|string|max:60|unique:access_cards,card_uid',
            'card_number' => 'required|string|max:40|unique:access_cards,card_number',
            'card_type' => 'nullable|string|in:RESIDENT_ALL_ACCESS,PARKING_ONLY,ELEVATOR_ONLY,VISITOR_PASS',
            'assigned_user_id' => 'nullable|uuid|exists:users,id',
            'assigned_apartment_id' => 'nullable|uuid|exists:apartments,id',
            'assigned_vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issued_date',
            'status' => 'nullable|string|in:ACTIVE,LOCKED,LOCKED_TEMPORARY,LOST_REPORTED,REVOKED',
            'deposit_fee' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Ràng buộc nghiệp vụ chéo nâng cao giữa Căn hộ, Cư dân, Phương tiện và Thời hạn thẻ (BUG-M5-04)
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $apartmentId = $this->input('assigned_apartment_id');
            $userId = $this->input('assigned_user_id');
            $vehicleId = $this->input('assigned_vehicle_id');
            $issuedDate = $this->input('issued_date');
            $expiryDate = $this->input('expiry_date');

            $isUpdate = $this->isMethod('put') || $this->isMethod('patch');
            $cardId = $this->route('id') ?? $this->route('card') ?? $this->input('id');

            if ($isUpdate && $cardId) {
                $existingCard = AccessCard::find($cardId);
                if ($existingCard) {
                    if (! $this->has('assigned_apartment_id')) {
                        $apartmentId = $existingCard->assigned_apartment_id;
                    }
                    if (! $this->has('assigned_user_id')) {
                        $userId = $existingCard->assigned_user_id;
                    }
                    if (! $this->has('assigned_vehicle_id')) {
                        $vehicleId = $existingCard->assigned_vehicle_id;
                    }
                    if (! $this->has('issued_date')) {
                        $issuedDate = $existingCard->issued_date?->format('Y-m-d');
                    }
                    if (! $this->has('expiry_date')) {
                        $expiryDate = $existingCard->expiry_date?->format('Y-m-d');
                    }
                }
            } else {
                if (! $issuedDate) {
                    $issuedDate = date('Y-m-d');
                }
            }

            // 1. Kiểm tra ngày hết hạn >= ngày cấp (nếu có cả 2 trường ngày)
            if (! empty($issuedDate) && ! empty($expiryDate)) {
                if (strtotime((string) $expiryDate) < strtotime((string) $issuedDate)) {
                    $validator->errors()->add(
                        'expiry_date',
                        'Ngày hết hạn thẻ phải lớn hơn hoặc bằng ngày cấp phát.'
                    );
                }
            }

            // 2. Ràng buộc chéo: Cư dân (assigned_user_id) phải thuộc căn hộ (assigned_apartment_id)
            if (! empty($userId) && ! empty($apartmentId)) {
                if (! $this->userBelongsToApartment($userId, $apartmentId)) {
                    $validator->errors()->add(
                        'assigned_user_id',
                        'Cư dân được chọn không thuộc căn hộ này hoặc không có quan hệ cư trú hợp lệ.'
                    );
                }
            }

            // 3. Ràng buộc chéo: Phương tiện (assigned_vehicle_id) phải thuộc căn hộ (assigned_apartment_id)
            if (! empty($vehicleId)) {
                if (empty($apartmentId)) {
                    $validator->errors()->add(
                        'assigned_apartment_id',
                        'Vui lòng chọn căn hộ trực thuộc của phương tiện khi gán xe cho thẻ RFID.'
                    );
                } else {
                    $vehicle = Vehicle::where('id', $vehicleId)->whereNull('deleted_at')->first();
                    if ($vehicle && (string) $vehicle->apartment_id !== (string) $apartmentId) {
                        $validator->errors()->add(
                            'assigned_vehicle_id',
                            'Phương tiện được chọn không thuộc căn hộ này.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Xác thực người dùng có quan hệ hợp lệ với căn hộ (cư dân active, chủ sở hữu, hoặc đại diện căn hộ)
     */
    protected function userBelongsToApartment(string $userId, string $apartmentId): bool
    {
        $today = now()->toDateString();

        // 1. Cư dân đang hoạt động trong căn hộ
        $isResident = Resident::where('user_id', $userId)
            ->where('apartment_id', $apartmentId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($today) {
                $query->whereNull('stay_end_date')
                    ->orWhere('stay_end_date', '>=', $today);
            })
            ->exists();

        if ($isResident) {
            return true;
        }

        // 2. Chủ hộ / Chủ sở hữu trong apartment_owners
        try {
            if (Schema::hasTable('apartment_owners')) {
                $ownerQuery = DB::table('apartment_owners')
                    ->where('apartment_id', $apartmentId)
                    ->where('owner_user_id', $userId)
                    ->where('is_current_owner', 1);

                if (Schema::hasColumn('apartment_owners', 'deleted_at')) {
                    $ownerQuery->whereNull('deleted_at');
                }

                if ($ownerQuery->exists()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        // 3. Cư dân đại diện hiện tại trong bảng apartments
        return DB::table('apartments')
            ->where('id', $apartmentId)
            ->where('current_resident_user_id', $userId)
            ->exists();
    }

    public function messages(): array
    {
        return [
            'card_uid.required' => 'Mã chip RFID (card_uid) là bắt buộc.',
            'card_uid.unique' => 'Mã chip RFID này đã được cấp cho một thẻ khác.',
            'card_number.required' => 'Mã in trên mặt thẻ (card_number) là bắt buộc.',
            'card_number.unique' => 'Mã số thẻ này đã tồn tại trong hệ thống.',
            'card_type.in' => 'Loại thẻ không hợp lệ (RESIDENT_ALL_ACCESS, PARKING_ONLY, ELEVATOR_ONLY, VISITOR_PASS).',
            'assigned_user_id.exists' => 'Cư dân được chọn không tồn tại.',
            'assigned_apartment_id.exists' => 'Căn hộ được chọn không tồn tại.',
            'assigned_vehicle_id.exists' => 'Phương tiện được chọn không tồn tại.',
            'status.in' => 'Trạng thái thẻ không hợp lệ (ACTIVE, LOCKED_TEMPORARY, LOST_REPORTED, REVOKED).',
            'expiry_date.after_or_equal' => 'Ngày hết hạn thẻ phải lớn hơn hoặc bằng ngày cấp phát.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Dữ liệu thẻ RFID không hợp lệ.',
            'error' => 'VALIDATION_ERROR',
            'errors' => $validator->errors(),
        ], 422));
    }
}
