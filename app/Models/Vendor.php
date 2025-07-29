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
        // Approval fields
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms' => 'array',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
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

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
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

    // Approval status methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    public function canBeApproved()
    {
        return $this->status === 'pending';
    }

    public function canBeRejected()
    {
        return $this->status === 'pending';
    }

    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'pending' => 'badge-warning',
            'approved' => 'badge-success',
            'rejected' => 'badge-danger',
            default => 'badge-secondary',
        };
    }

    public function getApprovalInfo()
    {
        return [
            'can_be_approved' => $this->canBeApproved(),
            'can_be_rejected' => $this->canBeRejected(),
            'approval_required' => $this->isPending(),
            'approved_by_name' => $this->approvedBy?->name,
            'approved_at_formatted' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejected_by_name' => $this->rejectedBy?->name,
            'rejected_at_formatted' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,
        ];
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
