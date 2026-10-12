<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    /**
     * Xác thực quyền của người dùng
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Chuẩn hóa dữ liệu trước khi kiểm tra (Trim và viết hoa biển số)
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('license_plate') && is_string($this->input('license_plate'))) {
            $this->merge([
                'license_plate' => strtoupper(trim($this->input('license_plate'))),
            ]);
        }
    }

    /**
     * Quy tắc xác thực dữ liệu phương tiện
     */
    public function rules(): array
    {
        $vehicleId = $this->route('id') ?? $this->route('vehicle') ?? $this->input('id');
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        if ($isUpdate) {
            return [
                'apartment_id' => 'sometimes|required|uuid|exists:apartments,id',
                'owner_user_id' => 'sometimes|required|uuid|exists:users,id',
                'license_plate' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:30',
                    Rule::unique('vehicles', 'license_plate')->ignore($vehicleId, 'id'),
                ],
                'vehicle_category' => 'sometimes|required|string|in:MOTORBIKE,CAR,E_SCOOTER,BICYCLE',
                'brand' => 'nullable|string|max:60',
                'model' => 'nullable|string|max:60',
                'color' => 'nullable|string|max:40',
                'registration_certificate_number' => 'nullable|string|max:80',
                'vehicle_photo_url' => 'nullable|string|max:500',
                'registration_cert_photo_url' => 'nullable|string|max:500',
                'monthly_parking_fee' => 'nullable|numeric|min:0',
                'has_electric_charging_subscription' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ];
        }

        return [
            'apartment_id' => 'required|uuid|exists:apartments,id',
            'owner_user_id' => 'required|uuid|exists:users,id',
            'license_plate' => [
                'required',
                'string',
                'max:30',
                Rule::unique('vehicles', 'license_plate'),
            ],
            'vehicle_category' => 'required|string|in:MOTORBIKE,CAR,E_SCOOTER,BICYCLE',
            'brand' => 'nullable|string|max:60',
            'model' => 'nullable|string|max:60',
            'color' => 'nullable|string|max:40',
            'registration_certificate_number' => 'nullable|string|max:80',
            'vehicle_photo_url' => 'nullable|string|max:500',
            'registration_cert_photo_url' => 'nullable|string|max:500',
            'monthly_parking_fee' => 'nullable|numeric|min:0',
            'has_electric_charging_subscription' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Tùy biến thông báo lỗi tiếng Việt thân thiện
     */
    public function messages(): array
    {
        return [
            'apartment_id.required' => 'Căn hộ đăng ký phương tiện là bắt buộc.',
            'apartment_id.uuid' => 'Mã căn hộ không đúng định dạng UUID.',
            'apartment_id.exists' => 'Căn hộ được chọn không tồn tại trong hệ thống.',
            'owner_user_id.required' => 'Chủ phương tiện / cư dân là bắt buộc.',
            'owner_user_id.uuid' => 'Mã chủ phương tiện không đúng định dạng UUID.',
            'owner_user_id.exists' => 'Chủ sở hữu được chọn không tồn tại trong hệ thống người dùng.',
            'license_plate.required' => 'Biển số xe là bắt buộc.',
            'license_plate.max' => 'Biển số xe không được vượt quá 30 ký tự.',
            'license_plate.unique' => 'Biển số xe này đã được đăng ký trong hệ thống.',
            'vehicle_category.required' => 'Loại phương tiện là bắt buộc.',
            'vehicle_category.in' => 'Loại phương tiện phải là MOTORBIKE (Xe máy), CAR (Ô tô), E_SCOOTER (Xe điện) hoặc BICYCLE (Xe đạp).',
            'brand.max' => 'Hãng xe không được vượt quá 60 ký tự.',
            'model.max' => 'Dòng xe (Model) không được vượt quá 60 ký tự.',
            'color.max' => 'Màu xe không được vượt quá 40 ký tự.',
            'registration_certificate_number.max' => 'Số cà-vẹt xe không được vượt quá 80 ký tự.',
            'vehicle_photo_url.max' => 'Đường dẫn ảnh xe không được vượt quá 500 ký tự.',
            'registration_cert_photo_url.max' => 'Đường dẫn ảnh cà-vẹt không được vượt quá 500 ký tự.',
            'monthly_parking_fee.numeric' => 'Phí gửi xe hàng tháng phải là số tiền hợp lệ.',
            'monthly_parking_fee.min' => 'Phí gửi xe hàng tháng không được nhỏ hơn 0.',
        ];
    }

    /**
     * Trả về JSON chuẩn khi validation thất bại
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Dữ liệu phương tiện không hợp lệ.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
