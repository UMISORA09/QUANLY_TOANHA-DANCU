<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResidentRequest extends FormRequest
{
    /**
     * Xác thực quyền của người dùng: Yêu cầu permission theo action-level RBAC
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if (strtoupper((string) ($user->status ?? '')) !== 'ACTIVE') {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $permission = match (strtoupper($this->method())) {
            'GET', 'HEAD' => 'RESIDENT:VIEW',
            'POST' => 'RESIDENT:CREATE',
            'PUT', 'PATCH' => 'RESIDENT:UPDATE',
            'DELETE' => 'RESIDENT:DELETE',
            default => 'RESIDENT:VIEW',
        };

        return method_exists($user, 'hasPermission') && $user->hasPermission($permission);
    }

    /**
     * Quy tắc xác thực dữ liệu đầu vào
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        if ($isUpdate) {
            return [
                'resident_type' => 'nullable|string|in:OWNER,TENANT,FAMILY_MEMBER',
                'is_head_of_household' => 'nullable|boolean',
                'stay_start_date' => 'nullable|date',
                'stay_end_date' => 'nullable|date',
                'relationship_to_head' => 'nullable|string|in:SELF,SPOUSE,CHILD,PARENT,TENANT,MAID',
                'occupation' => 'nullable|string|max:100',
                'vehicle_count' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
                'updated_at' => 'nullable|string',
            ];
        }

        return [
            'apartment_id' => 'required|uuid|exists:apartments,id',
            'user_id' => 'required|uuid|exists:users,id',
            'resident_type' => 'required|string|in:OWNER,TENANT,FAMILY_MEMBER',
            'is_head_of_household' => 'nullable|boolean',
            'stay_start_date' => 'required|date',
            'stay_end_date' => 'nullable|date|after_or_equal:stay_start_date',
            'relationship_to_head' => 'nullable|string|in:SELF,SPOUSE,CHILD,PARENT,TENANT,MAID',
            'occupation' => 'nullable|string|max:100',
            'vehicle_count' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Tùy biến thông báo lỗi tiếng Việt
     */
    public function messages(): array
    {
        return [
            'apartment_id.required' => 'Mã căn hộ là bắt buộc.',
            'apartment_id.uuid' => 'Mã căn hộ không đúng định dạng UUID.',
            'apartment_id.exists' => 'Căn hộ được chọn không tồn tại trong hệ thống.',
            'user_id.required' => 'Thông tin người dùng / cư dân là bắt buộc.',
            'user_id.uuid' => 'Mã người dùng không đúng định dạng UUID.',
            'user_id.exists' => 'Người dùng được chọn không tồn tại trong hệ thống.',
            'resident_type.required' => 'Loại cư dân là bắt buộc.',
            'resident_type.in' => 'Loại cư dân phải thuộc: OWNER (Chủ sở hữu), TENANT (Người thuê), FAMILY_MEMBER (Thành viên hộ).',
            'relationship_to_head.in' => 'Quan hệ với chủ hộ phải thuộc: SELF, SPOUSE, CHILD, PARENT, TENANT, MAID.',
            'stay_start_date.required' => 'Ngày bắt đầu cư trú là bắt buộc.',
            'stay_start_date.date' => 'Ngày bắt đầu cư trú không hợp lệ.',
            'stay_start_date.before_or_equal' => 'Ngày bắt đầu cư trú phải trước hoặc bằng ngày kết thúc cư trú.',
            'stay_end_date.date' => 'Ngày kết thúc cư trú không hợp lệ.',
            'stay_end_date.after_or_equal' => 'Ngày kết thúc cư trú phải sau hoặc bằng ngày bắt đầu cư trú.',
            'occupation.max' => 'Nghề nghiệp không được vượt quá 100 ký tự.',
        ];
    }

    /**
     * Bổ sung kiểm tra logic chéo giữa ngày bắt đầu và kết thúc cư trú (Partial Date Validation)
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('stay_start_date') || $validator->errors()->has('stay_end_date')) {
                return;
            }

            $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

            if (! $isUpdate) {
                if ($this->filled('stay_start_date') && $this->filled('stay_end_date')) {
                    $start = Carbon::parse($this->input('stay_start_date'))->startOfDay();
                    $end = Carbon::parse($this->input('stay_end_date'))->startOfDay();
                    if ($end->lt($start)) {
                        $validator->errors()->add(
                            'stay_end_date',
                            'Ngày kết thúc cư trú phải sau hoặc bằng ngày bắt đầu cư trú.'
                        );
                    }
                }

                return;
            }

            $hasStartDate = $this->has('stay_start_date');
            $hasEndDate = $this->has('stay_end_date');

            if (! $hasStartDate && ! $hasEndDate) {
                return;
            }

            $inputStartDate = $this->input('stay_start_date');
            $inputEndDate = $this->input('stay_end_date');

            // Cả hai trường đều được gửi trong payload
            if ($hasStartDate && $hasEndDate) {
                if ($inputStartDate !== null && $inputEndDate !== null) {
                    $start = Carbon::parse($inputStartDate)->startOfDay();
                    $end = Carbon::parse($inputEndDate)->startOfDay();
                    if ($end->lt($start)) {
                        $validator->errors()->add(
                            'stay_end_date',
                            'Ngày kết thúc cư trú phải sau hoặc bằng ngày bắt đầu cư trú.'
                        );
                    }
                }

                return;
            }

            // Trường hợp chỉ cập nhật 1 trong 2 ngày -> đối chiếu với dữ liệu hiện tại trong DB
            $residentId = $this->route('id') ?? $this->route('resident');
            if ($residentId instanceof Resident) {
                $residentId = $residentId->id;
            }

            if (! $residentId) {
                return;
            }

            $currentResident = Resident::find($residentId);
            if (! $currentResident) {
                return;
            }

            // Chỉ cập nhật stay_end_date
            if ($hasEndDate && ! $hasStartDate) {
                if ($inputEndDate !== null && $currentResident->stay_start_date !== null) {
                    $start = Carbon::parse($currentResident->stay_start_date)->startOfDay();
                    $end = Carbon::parse($inputEndDate)->startOfDay();
                    if ($end->lt($start)) {
                        $validator->errors()->add(
                            'stay_end_date',
                            'Ngày kết thúc cư trú phải sau hoặc bằng ngày bắt đầu cư trú.'
                        );
                    }
                }
            }

            // Chỉ cập nhật stay_start_date
            if ($hasStartDate && ! $hasEndDate) {
                if ($inputStartDate !== null && $currentResident->stay_end_date !== null) {
                    $start = Carbon::parse($inputStartDate)->startOfDay();
                    $end = Carbon::parse($currentResident->stay_end_date)->startOfDay();
                    if ($start->gt($end)) {
                        $validator->errors()->add(
                            'stay_start_date',
                            'Ngày bắt đầu cư trú phải trước hoặc bằng ngày kết thúc cư trú.'
                        );
                    }
                }
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
            'message' => 'Dữ liệu xác thực không hợp lệ.',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * Trả về JSON chuẩn khi không có quyền truy cập
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
