<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResidentCreateTicketRequest extends FormRequest
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
            'category_id' => 'required|string|uuid|exists:ticket_categories,id',
            'location' => 'required|string|max:255',
            'priority' => 'nullable|string|in:LOW,MEDIUM,HIGH,URGENT',
            'apartment_id' => 'nullable|string|uuid',
            'preferred_service_time' => 'nullable|date',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpeg,png,jpg,webp|max:5120',
            'idempotency_key' => 'nullable|string|max:100',
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
            'category_id.required' => 'Vui lòng chọn loại sự cố / hạng mục hỗ trợ.',
            'category_id.exists' => 'Loại sự cố đã chọn không tồn tại trong hệ thống.',
            'location.required' => 'Vị trí xảy ra sự cố không được để trống.',
            'location.max' => 'Vị trí sự cố không được vượt quá 255 ký tự.',
            'priority.in' => 'Mức độ khẩn cấp không hợp lệ (LOW, MEDIUM, HIGH, URGENT).',
            'attachments.max' => 'Chỉ được phép đính kèm tối đa 5 hình ảnh.',
            'attachments.*.file' => 'Tệp đính kèm phải là tệp hợp lệ.',
            'attachments.*.mimes' => 'Chỉ chấp nhận các định dạng ảnh: JPG, JPEG, PNG, WEBP.',
            'attachments.*.max' => 'Dung lượng mỗi ảnh không được vượt quá 5MB.',
        ];
    }
}
