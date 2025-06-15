<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:businesses,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'business_type' => 'required|string|max:100',
            'registration_number' => 'nullable|string|unique:businesses,registration_number',
            'credit_limit' => 'nullable|numeric|min:0',
            'available_balance' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Business name is required',
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'email.unique' => 'This email address is already registered',
            'business_type.required' => 'Business type is required',
            'registration_number.unique' => 'This registration number is already in use',
        ];
    }
}
