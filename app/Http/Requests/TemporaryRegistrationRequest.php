<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TemporaryRegistrationRequest extends FormRequest
{
    /**
     * Xác thực quyền người dùng: Yêu cầu quyền Quản trị viên (SUPER_ADMIN hoặc BUILDING_MANAGER)
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

        if (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER'])) {
            return true;
        }

        return false;
    }

    /**
     * Quy tắc xác thực dữ liệu đầu vào
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        if ($isUpdate) {
            return [
                'registration_type' => 'nullable|string|in:TEMPORARY_STAY,TEMPORARY_ABSENCE',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'reason' => 'nullable|string|max:1000',
                'identity_card_front_url' => 'nullable|string|max:500',
                'identity_card_back_url' => 'nullable|string|max:500',
                'notes' => 'nullable|string|max:1000',
                'police_reference_code' => 'nullable|string|max:100',
                'updated_at' => 'nullable|string',
            ];
        }

        return [
            'resident_id' => 'required|uuid|exists:residents,id',
            'apartment_id' => 'required|uuid|exists:apartments,id',
            'registration_type' => 'required|string|in:TEMPORARY_STAY,TEMPORARY_ABSENCE',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
            'identity_card_front_url' => 'nullable|string|max:500',
            'identity_card_back_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Tùy biến thông báo lỗi tiếng Việt
     */
    public function messages(): array
    {
        return [
            'resident_id.required' => 'Thông tin cư dân là bắt buộc.',
            'resident_id.uuid' => 'Mã cư dân không đúng định dạng UUID.',
            'resident_id.exists' => 'Cư dân được chọn không tồn tại trong hệ thống.',
            'apartment_id.required' => 'Mã căn hộ là bắt buộc.',
            'apartment_id.uuid' => 'Mã căn hộ không đúng định dạng UUID.',
            'apartment_id.exists' => 'Căn hộ được chọn không tồn tại trong hệ thống.',
            'registration_type.required' => 'Loại đăng ký là bắt buộc.',
            'registration_type.in' => 'Loại đăng ký phải là TEMPORARY_STAY (Tạm trú) hoặc TEMPORARY_ABSENCE (Tạm vắng).',
            'start_date.required' => 'Ngày bắt đầu là bắt buộc.',
            'start_date.date' => 'Ngày bắt đầu không đúng định dạng ngày.',
            'end_date.required' => 'Ngày kết thúc là bắt buộc.',
            'end_date.date' => 'Ngày kết thúc không đúng định dạng ngày.',
            'end_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
            'reason.required' => 'Lý do đăng ký là bắt buộc.',
            'reason.max' => 'Lý do không được vượt quá 1000 ký tự.',
            'notes.max' => 'Ghi chú không được vượt quá 1000 ký tự.',
            'police_reference_code.max' => 'Mã tham chiếu công an không được vượt quá 100 ký tự.',
        ];
    }

    /**
     * Trả về JSON chuẩn 422 khi validation thất bại
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Dữ liệu xác thực không hợp lệ.',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * Trả về JSON chuẩn 403 khi không có quyền truy cập
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Bạn không có quyền quản trị để thực hiện thao tác này.',
            'error' => 'FORBIDDEN',
        ], 403));
    }
}
