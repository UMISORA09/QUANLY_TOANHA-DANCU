<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
                'expiry_date' => 'nullable|date|after_or_equal:issued_date',
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
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|string|in:ACTIVE,LOCKED,LOCKED_TEMPORARY,LOST_REPORTED,REVOKED',
            'deposit_fee' => 'nullable|numeric|min:0',
        ];
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
