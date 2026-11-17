<?php

namespace App\Http\Requests;

use App\Models\Resident;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * Chuẩn hóa dữ liệu trước khi kiểm tra (Trim và viết hoa biển số / loại phương tiện)
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('license_plate') && is_string($this->input('license_plate'))) {
            $this->merge([
                'license_plate' => strtoupper(trim($this->input('license_plate'))),
            ]);
        }

        if ($this->has('vehicle_category') && is_string($this->input('vehicle_category'))) {
            $this->merge([
                'vehicle_category' => strtoupper(trim($this->input('vehicle_category'))),
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
        ];
    }

    /**
     * Xác thực nâng cao mối quan hệ hợp lệ giữa Chủ xe và Căn hộ
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $apartmentId = $this->input('apartment_id');
            $ownerUserId = $this->input('owner_user_id');
            $isUpdate = $this->isMethod('put') || $this->isMethod('patch');
            $vehicleId = $this->route('id') ?? $this->route('vehicle') ?? $this->input('id');

            if ($isUpdate && $vehicleId) {
                $vehicle = Vehicle::find($vehicleId);
                if ($vehicle) {
                    $apartmentId = $apartmentId ?: $vehicle->apartment_id;
                    $ownerUserId = $ownerUserId ?: $vehicle->owner_user_id;
                }
            }

            if (! $apartmentId || ! $ownerUserId) {
                return;
            }

            // 1. Xác minh tài khoản người dùng tồn tại và đang hoạt động (ACTIVE)
            $ownerUser = User::find($ownerUserId);
            if (! $ownerUser || strtoupper((string) ($ownerUser->status ?? '')) !== 'ACTIVE') {
                $validator->errors()->add(
                    'owner_user_id',
                    'Chủ phương tiện không tồn tại hoặc tài khoản không ở trạng thái hoạt động.'
                );

                return;
            }

            // 2. Xác minh người đó có quan hệ cư trú hợp lệ với apartment_id
            $today = now()->toDateString();
            $isValidResident = Resident::where('user_id', $ownerUserId)
                ->where('apartment_id', $apartmentId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($today) {
                    $query->whereNull('stay_end_date')
                        ->orWhere('stay_end_date', '>=', $today);
                })
                ->exists();

            $isCurrentOwner = false;
            try {
                if (Schema::hasTable('apartment_owners')) {
                    $ownerQuery = DB::table('apartment_owners')
                        ->where('apartment_id', $apartmentId)
                        ->where('owner_user_id', $ownerUserId)
                        ->where('is_current_owner', 1);

                    if (Schema::hasColumn('apartment_owners', 'deleted_at')) {
                        $ownerQuery->whereNull('deleted_at');
                    }

                    $isCurrentOwner = $ownerQuery->exists();
                }
            } catch (\Throwable) {
            }

            if (! $isValidResident && ! $isCurrentOwner) {
                $validator->errors()->add(
                    'owner_user_id',
                    'Chủ phương tiện được chọn không phải là cư dân đang cư trú hợp lệ hoặc chủ sở hữu của căn hộ này.'
                );
            }
        });
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
