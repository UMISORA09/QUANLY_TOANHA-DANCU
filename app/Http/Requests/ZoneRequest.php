<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest kiểm thực dữ liệu Khối Tòa nhà (Block/Zone).
 */
class ZoneRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện yêu cầu này không.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Chuẩn hóa dữ liệu trước khi kiểm thực.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->status)) {
            $this->merge([
                'status' => strtolower(trim($this->status)),
            ]);
        }
    }

    /**
     * Quy tắc kiểm thực (Validation Rules).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $zoneId = $this->route('zone') ?? $this->route('id');

        $rules = [
            'zone_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('zones', 'zone_code')->ignore($zoneId)->whereNull('deleted_at'),
            ],
            'zone_name' => [
                'required',
                'string',
                'max:150',
            ],
            'floor_count' => [
                'required',
                'integer',
                'min:1', // Bắt buộc floor_count phải > 0
            ],
            'basement_count' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'total_apartments' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in(['ACTIVE', 'MAINTENANCE', 'INACTIVE', 'active', 'maintenance', 'inactive']),
            ],
            'address_line' => [
                'nullable',
                'string',
                'max:500',
            ],
            'hotline_phone' => [
                'nullable',
                'string',
                'max:30',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];

        // Nếu là thao tác cập nhật (PUT/PATCH), nhận trường last_updated_at phục vụ Optimistic Locking
        if ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['last_updated_at'] = [
                'required',
                'string',
            ];
        }

        return $rules;
    }

    /**
     * Tùy chỉnh thông báo lỗi kiểm thực bằng tiếng Việt.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zone_code.required' => 'Mã khối tòa nhà không được để trống.',
            'zone_code.max' => 'Mã khối tòa nhà không được vượt quá 50 ký tự.',
            'zone_code.unique' => 'Mã khối tòa nhà này đã tồn tại trong hệ thống, vui lòng chọn mã khác.',
            'zone_name.required' => 'Tên khối tòa nhà không được để trống.',
            'zone_name.max' => 'Tên khối tòa nhà không được vượt quá 150 ký tự.',
            'floor_count.required' => 'Số tầng nổi không được để trống.',
            'floor_count.integer' => 'Số tầng nổi phải là một số nguyên hợp lệ.',
            'floor_count.min' => 'Số tầng nổi của khối nhà phải lớn hơn 0 (tối thiểu là 1 tầng).',
            'basement_count.integer' => 'Số tầng hầm phải là số nguyên.',
            'basement_count.min' => 'Số tầng hầm không được nhỏ hơn 0.',
            'total_apartments.integer' => 'Tổng số căn hộ phải là số nguyên.',
            'total_apartments.min' => 'Tổng số căn hộ không được nhỏ hơn 0.',
            'status.in' => 'Trạng thái khối tòa nhà không hợp lệ (chỉ chấp nhận: ACTIVE, MAINTENANCE, INACTIVE).',
            'last_updated_at.required' => 'Dấu thời gian cập nhật (last_updated_at) là bắt buộc để đảm bảo an toàn đồng thời.',
        ];
    }
}
