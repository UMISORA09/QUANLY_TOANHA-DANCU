<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'required|uuid',
            'comment_text' => 'required|string|max:2000',
            'is_internal_staff_note' => 'sometimes|required|boolean',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'id.required' => 'Thiếu mã gửi bình luận. Vui lòng thử lại.',
            'id.uuid' => 'Mã gửi bình luận không hợp lệ.',
            'comment_text.required' => 'Vui lòng nhập nội dung trao đổi.',
            'comment_text.max' => 'Nội dung không được vượt quá 2.000 ký tự.',
            'is_internal_staff_note.boolean' => 'Lựa chọn ghi chú nội bộ không hợp lệ.',
        ];
    }
}
