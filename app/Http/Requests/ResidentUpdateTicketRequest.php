<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResidentUpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'description' => 'required|string|min:5',
            'location' => 'required|string|max:255',
            'priority' => 'nullable|string|in:LOW,MEDIUM,HIGH,URGENT',
            'preferred_service_time' => 'nullable|date',
            'version' => 'nullable|integer|min:1',
            'updated_at' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Tiêu đề sự cố không được để trống.',
            'title.max' => 'Tiêu đề sự cố không được vượt quá 200 ký tự.',
            'description.required' => 'Mô tả chi tiết sự cố là bắt buộc.',
            'description.min' => 'Mô tả chi tiết phải có ít nhất 5 ký tự.',
            'location.required' => 'Vị trí xảy ra sự cố không được để trống.',
            'location.max' => 'Vị trí sự cố không được vượt quá 255 ký tự.',
            'priority.in' => 'Mức độ khẩn cấp không hợp lệ (LOW, MEDIUM, HIGH, URGENT).',
        ];
    }
}
