<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Paystack dedicated-virtual-account credit, keyed by the
 * Paystack transaction reference so webhook retries never double-credit
 * a business's wallet.
 */
class WalletFunding extends Model
{
    protected $fillable = [
        'business_id',
        'paystack_reference',
        'amount',
        'channel',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_payload' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
