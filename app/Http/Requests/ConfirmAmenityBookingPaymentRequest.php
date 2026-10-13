<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmAmenityBookingPaymentRequest extends FormRequest
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
            'bank_transaction_id' => 'required|string|max:100|regex:/^[A-Za-z0-9._\/-]+$/',
            'received_amount' => 'required|integer|min:1|max:99999999999999',
            'received_at' => 'required|date|before_or_equal:now',
        ];
    }
}
