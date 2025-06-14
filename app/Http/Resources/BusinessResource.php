<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'business_type' => $this->business_type,
            'registration_number' => $this->registration_number,
            'balances' => [
                'available_balance' => $this->available_balance,
                'current_balance' => $this->current_balance,
                'credit_balance' => $this->credit_balance,
                'treasury_collateral_balance' => $this->treasury_collateral_balance,
                'credit_limit' => $this->credit_limit,
            ],
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'admin' => $this->whenLoaded('admin', function () {
                return [
                    'id' => $this->admin->id,
                    'name' => $this->admin->name,
                    'email' => $this->admin->email,
                ];
            }),
        ];
    }
}
