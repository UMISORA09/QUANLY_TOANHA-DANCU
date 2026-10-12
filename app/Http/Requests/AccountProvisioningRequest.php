<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class AccountProvisioningRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:120', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[0-9+() -]{8,20}$/', 'unique:users,phone_number'],
            'national_id_number' => ['nullable', 'string', 'max:25', 'unique:users,national_id_number'],
            'gender' => ['nullable', 'string', 'in:MALE,FEMALE,OTHER'],
            'date_of_birth' => ['nullable', 'date'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Họ và tên người dùng là bắt buộc.',
            'full_name.max' => 'Họ và tên không được vượt quá 150 ký tự.',
            'email.required' => 'Email là bắt buộc để gửi thư kích hoạt tài khoản.',
            'email.email' => 'Địa chỉ email không đúng định dạng.',
            'email.unique' => 'Địa chỉ email này đã tồn tại trong hệ thống.',
            'email.max' => 'Email không được vượt quá 120 ký tự.',
            'phone_number.required' => 'Số điện thoại là bắt buộc.',
            'phone_number.regex' => 'Số điện thoại không đúng định dạng.',
            'phone_number.unique' => 'Số điện thoại này đã được đăng ký bởi tài khoản khác.',
            'phone_number.max' => 'Số điện thoại không được vượt quá 20 ký tự.',
            'national_id_number.unique' => 'Số CCCD/Passport này đã tồn tại trong hệ thống.',
            'national_id_number.max' => 'Số CCCD/Passport không được vượt quá 25 ký tự.',
            'gender.in' => 'Giới tính không hợp lệ (MALE, FEMALE, OTHER).',
            'date_of_birth.date' => 'Ngày sinh không đúng định dạng ngày tháng.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Dữ liệu không hợp lệ.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
