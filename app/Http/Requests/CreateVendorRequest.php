<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|array',
            'payment_terms.payment_days' => 'nullable|integer|min:1|max:365',
            'payment_terms.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms.late_fee_percentage' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vendor name is required',
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'email.unique' => 'This email address is already registered',
        ];
    }
}
