<?php

namespace App\Http\Requests;

use App\Models\Resident;
use App\Models\TemporaryRegistration;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TemporaryRegistrationRequest extends FormRequest
{
    /**
     * Xác thực quyền người dùng: Quản trị viên (SUPER_ADMIN, BUILDING_MANAGER) hoặc Cư dân có quan hệ cư trú hợp lệ
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        // 1. Quản trị viên có toàn quyền
        if ($this->isUserAdmin($user)) {
            return true;
        }

        // 2. Cư dân phải có hồ sơ lưu trú hoạt động hoặc có role cư dân
        $isResident = Resident::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists()
            || (method_exists($user, 'hasRole') && $user->hasRole(['RESIDENT_OWNER', 'RESIDENT_MEMBER']));

        if (! $isResident) {
            return false;
        }

        // 3. Phân quyền theo thao tác
        if ($this->isMethod('post')) {
            $aptId = $this->input('apartment_id');
            $resId = $this->input('resident_id');

            if ($aptId || $resId) {
                if (! $this->canResidentAccess($user, $aptId, $resId)) {
                    return false;
                }
            }
        }

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            $registrationId = (string) ($this->route('id') ?? $this->route('temporary_registration') ?? '');
            if ($registrationId !== '') {
                $registration = TemporaryRegistration::find($registrationId);
                if ($registration && ! $this->canResidentModify($user, $registration)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Chuẩn bị dữ liệu trước khi xác thực (Tự động điền cho cư dân nếu thiếu ID hợp lệ)
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user && ! $this->isUserAdmin($user) && $this->isMethod('post')) {
            // Tự động gán apartment_id nếu cư dân chỉ ở 1 căn hộ
            if (! $this->has('apartment_id') || empty($this->input('apartment_id'))) {
                $userApartmentId = Resident::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->value('apartment_id');
                if ($userApartmentId) {
                    $this->merge(['apartment_id' => $userApartmentId]);
                }
            }

            // Tự động gán resident_id của chính người dùng trong căn hộ đó nếu chưa truyền
            if (! $this->has('resident_id') || empty($this->input('resident_id'))) {
                $aptId = $this->input('apartment_id');
                if ($aptId) {
                    $residentId = Resident::where('user_id', $user->id)
                        ->where('apartment_id', $aptId)
                        ->where('is_active', true)
                        ->value('id');
                    if ($residentId) {
                        $this->merge(['resident_id' => $residentId]);
                    }
                }
            }
        }
    }

    /**
     * Kiểm tra người dùng có quyền quản trị hay không
     */
    protected function isUserAdmin(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole(['SUPER_ADMIN', 'BUILDING_MANAGER']));
    }

    /**
     * Kiểm tra cư dân có quyền đăng ký cho căn hộ và cư dân được chọn
     */
    protected function canResidentAccess(mixed $user, ?string $apartmentId, ?string $residentId): bool
    {
        if (! $user) {
            return false;
        }

        if (! $apartmentId && ! $residentId) {
            return true;
        }

        // 1. Kiểm tra căn hộ: user phải có quan hệ cư trú active tại apartment_id
        if ($apartmentId) {
            $userResident = Resident::where('user_id', $user->id)
                ->where('apartment_id', $apartmentId)
                ->where('is_active', true)
                ->first();

            if (! $userResident) {
                return false;
            }

            // 2. Kiểm tra resident_id
            if ($residentId) {
                $targetResident = Resident::where('id', $residentId)->first();
                if ($targetResident) {
                    // Đăng ký cho chính mình
                    $isSelf = ((string) $targetResident->user_id === (string) $user->id)
                        || ((string) $targetResident->id === (string) $userResident->id);

                    if ($isSelf) {
                        return true;
                    }

                    // Đăng ký cho thành viên cùng hộ gia đình (chỉ chủ hộ hoặc chủ sở hữu)
                    $isHeadOrOwner = (bool) $userResident->is_head_of_household
                        || $userResident->resident_type === 'OWNER'
                        || (method_exists($user, 'hasRole') && $user->hasRole('RESIDENT_OWNER'));

                    if ($isHeadOrOwner && (string) $targetResident->apartment_id === (string) $apartmentId && (bool) $targetResident->is_active) {
                        return true;
                    }

                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * Kiểm tra cư dân có quyền chỉnh sửa hồ sơ
     */
    protected function canResidentModify(mixed $user, TemporaryRegistration $registration): bool
    {
        if (! $user) {
            return false;
        }

        $registration->loadMissing('resident');

        if ($registration->resident && (string) $registration->resident->user_id === (string) $user->id) {
            return true;
        }

        $userResident = Resident::where('user_id', $user->id)
            ->where('apartment_id', $registration->apartment_id)
            ->where('is_active', true)
            ->first();

        if ($userResident) {
            $isHeadOrOwner = (bool) $userResident->is_head_of_household
                || $userResident->resident_type === 'OWNER'
                || (method_exists($user, 'hasRole') && $user->hasRole('RESIDENT_OWNER'));

            if ($isHeadOrOwner) {
                return true;
            }

            if ((string) $registration->resident_id === (string) $userResident->id) {
                return true;
            }
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
