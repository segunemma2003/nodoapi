<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_reference',
        'purchase_order_id',
        'business_id',
        'amount',
        'payment_type',
        'status',
        'receipt_path',
        'notes',
        'payment_date',
        'confirmed_by',
        'confirmed_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    // Relationships
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }


    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('payment_type', $type);
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isConfirmed()
    {
        return $this->status === 'confirmed';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    public function canBeConfirmed()
    {
        return $this->status === 'pending' && $this->amount > 0;
    }

    public function canBeRejected()
    {
        return $this->status === 'pending';
    }

    public function getReceiptUrl()
    {
        if (!$this->receipt_path) {
            return null;
        }

        // Generate a secure URL for accessing the receipt
        return route('payments.receipt', ['payment' => $this->id]);
    }

    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'pending' => 'badge-warning',
            'confirmed' => 'badge-success',
            'rejected' => 'badge-danger',
            default => 'badge-secondary',
        };
    }

    public function getPaymentTypeLabel()
    {
        return match($this->payment_type) {
            'business_payment' => 'Business Payment',
            'admin_adjustment' => 'Admin Adjustment',
            'system_credit' => 'System Credit',
            'refund' => 'Refund',
            default => ucfirst(str_replace('_', ' ', $this->payment_type)),
        };
    }

    // Calculate days since payment submission
    public function getDaysPending()
    {
        if ($this->status !== 'pending') {
            return 0;
        }

        return now()->diffInDays($this->created_at);
    }

    // Check if payment is overdue for confirmation
    public function isOverdueForConfirmation($maxDays = 3)
    {
        return $this->status === 'pending' && $this->getDaysPending() > $maxDays;
    }

    // Generate payment confirmation hash for security
    public function generateConfirmationHash()
    {
        return hash('sha256', $this->id . $this->payment_reference . $this->amount . config('app.key'));
    }

    // Verify confirmation hash
    public function verifyConfirmationHash($hash)
    {
        return hash_equals($this->generateConfirmationHash(), $hash);
    }

    // Boot method for model events
    protected static function boot()
    {
        parent::boot();

        // Automatically set payment_date if not provided
        static::creating(function ($payment) {
            if (!$payment->payment_date) {
                $payment->payment_date = now();
            }
        });

        // Log payment status changes
        static::updating(function ($payment) {
            if ($payment->isDirty('status')) {
                $originalStatus = $payment->getOriginal('status');
                $newStatus = $payment->status;

                // Log the status change
                Log::info("Payment {$payment->payment_reference} status changed from {$originalStatus} to {$newStatus}", [
                    'payment_id' => $payment->id,
                    'business_id' => $payment->business_id,
                    'amount' => $payment->amount,
                    'changed_by' => Auth::id(),
                ]);
            }
        });
    }
}
