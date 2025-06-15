<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'required|exists:vendors,id',
            'items' => 'required|array|min:1|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01|max:999999',
            'items.*.unit_price' => 'required|numeric|min:0',
            'order_date' => 'required|date|before_or_equal:today',
            'expected_delivery_date' => 'nullable|date|after:order_date',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Vendor selection is required',
            'vendor_id.exists' => 'Selected vendor is invalid',
            'items.required' => 'At least one item is required',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum 50 items allowed per purchase order',
            'items.*.description.required' => 'Item description is required',
            'items.*.quantity.required' => 'Item quantity is required',
            'items.*.quantity.min' => 'Quantity must be greater than 0',
            'items.*.unit_price.required' => 'Unit price is required',
            'order_date.required' => 'Order date is required',
            'expected_delivery_date.after' => 'Expected delivery date must be after order date',
        ];
    }
}
