<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

     protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'vendor_code',
        'category',
        'payment_terms',
        'is_active',
        'business_id',
        'account_number',
        'bank_code',
        'bank_name',
        'account_holder_name',  // NEW: Store verified name from bank
        'recipient_code',       // Paystack recipient code
    ];

    protected function casts(): array
    {
        return [
            'payment_terms' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    // Check if vendor has complete bank details for payments
    public function hasCompletePaymentDetails()
    {
        return !empty($this->account_number) &&
               !empty($this->bank_code) &&
               !empty($this->bank_name);
    }

    // Get formatted bank details for display
    public function getBankDetailsFormatted()
    {
        if (!$this->hasCompletePaymentDetails()) {
            return 'Bank details not configured';
        }

        return "{$this->bank_name} - {$this->account_number}" .
               ($this->account_holder_name ? " ({$this->account_holder_name})" : '');
    }

    // Generate unique vendor code
    public static function generateVendorCode($businessId)
    {
        $prefix = 'VND';
        $businessCode = str_pad($businessId, 3, '0', STR_PAD_LEFT);

        do {
            $suffix = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $code = $prefix . $businessCode . $suffix;
        } while (self::where('vendor_code', $code)->exists());

        return $code;
    }
}
