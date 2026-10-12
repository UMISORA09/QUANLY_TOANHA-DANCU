<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResidentAmenityBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'amenity_id' => ['required', 'uuid'],
            'slot_id' => ['nullable', 'uuid', 'required_without:start_time'],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'apartment_id' => ['nullable', 'uuid'],
            'start_time' => ['nullable', 'required_without:slot_id', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_without:slot_id', 'date_format:H:i', 'after:start_time'],
            'attendee_count' => ['required', 'integer', 'min:1'],
            'resident_notes' => ['nullable', 'string', 'max:500'],
            'accepted_rules' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('id')) {
            $this->merge(['amenity_id' => $this->route('id')]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accepted_rules.accepted' => 'Vui lòng xác nhận đã đọc nội quy tiện ích.',
            'accepted_rules.required' => 'Vui lòng xác nhận đã đọc nội quy tiện ích.',
            'attendee_count.min' => 'Số người tham gia phải từ 1 trở lên.',
            'resident_notes.max' => 'Ghi chú không được vượt quá 500 ký tự.',
            'slot_id.required_without' => 'Vui lòng chọn một khung giờ.',
        ];
    }
}
