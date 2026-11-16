<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest kiểm thực dữ liệu Khối Tòa nhà (Block/Zone).
 * Đảm bảo phân quyền chặt chẽ, chuẩn hóa dữ liệu đầu vào và kiểm tra tính duy nhất.
 */
class ZoneRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện yêu cầu này không (RBAC check).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ($this->isMethod('post')) {
            return $user->hasPermission('BUILDING:MANAGE')
                || $user->hasPermission('ZONE:CREATE')
                || $user->hasPermission('ZONE:MANAGE')
                || $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']);
        }

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            return $user->hasPermission('BUILDING:MANAGE')
                || $user->hasPermission('ZONE:UPDATE')
                || $user->hasPermission('ZONE:MANAGE')
                || $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']);
        }

        if ($this->isMethod('delete')) {
            return $user->hasPermission('BUILDING:MANAGE')
                || $user->hasPermission('ZONE:DELETE')
                || $user->hasPermission('ZONE:MANAGE')
                || $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']);
        }

        return $user->hasPermission('BUILDING:VIEW')
            || $user->hasPermission('ZONE:VIEW')
            || $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER', 'ACCOUNTANT', 'RECEPTIONIST']);
    }

    /**
     * Chuẩn hóa dữ liệu trước khi kiểm thực (Sanitization).
     */
    protected function prepareForValidation(): void
    {
        $mergeData = [];

        // Đồng bộ alias block_code / zone_code
        if ($this->has('block_code') && ! $this->has('zone_code')) {
            $mergeData['zone_code'] = $this->block_code;
        }
        if ($this->has('block_name') && ! $this->has('zone_name')) {
            $mergeData['zone_name'] = $this->block_name;
        }
        if ($this->has('total_floors') && ! $this->has('floor_count')) {
            $mergeData['floor_count'] = $this->total_floors;
        }
        if ($this->has('total_basements') && ! $this->has('basement_count')) {
            $mergeData['basement_count'] = $this->total_basements;
        }

        // Chuẩn hóa status thành chữ hoa (ACTIVE, MAINTENANCE, INACTIVE)
        if ($this->has('status') && is_string($this->status)) {
            $mergeData['status'] = strtoupper(trim($this->status));
        }

        // Chuẩn hóa zone_code thành chữ hoa
        if ($this->has('zone_code') && is_string($this->zone_code)) {
            $mergeData['zone_code'] = strtoupper(trim($this->zone_code));
        }

        if (! empty($mergeData)) {
            $this->merge($mergeData);
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
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');
        $requiredOrSometimes = $isUpdate ? 'sometimes' : 'required';

        $rules = [
            'zone_code' => [
                $requiredOrSometimes,
                'string',
                'max:50',
                // Kiểm tra duy nhất trên bảng blocks (nguồn sự thật duy nhất), bao gồm cả bản ghi soft-delete để khớp với DB unique constraint
                Rule::unique('blocks', 'block_code')->ignore($zoneId),
            ],
            'zone_name' => [
                $requiredOrSometimes,
                'string',
                'max:150',
            ],
            'floor_count' => [
                $requiredOrSometimes,
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
                Rule::in(['ACTIVE', 'MAINTENANCE', 'INACTIVE']),
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

        // Nếu là thao tác cập nhật (PUT/PATCH), nhận version hoặc last_updated_at phục vụ Optimistic Locking
        if ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['version'] = [
                'nullable',
                'integer',
                'min:1',
            ];
            $rules['last_updated_at'] = [
                'nullable',
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
        ];
    }
}
