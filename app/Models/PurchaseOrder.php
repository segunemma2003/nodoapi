<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'business_id',
        'vendor_id',
        'total_amount',
        'tax_amount',
        'discount_amount',
        'net_amount',
        'total_paid_amount',
        'outstanding_amount',
        'payment_status',
        'status',
        'order_date',
        'due_date',
        'expected_delivery_date',
        'notes',
        'items',
        'approved_at',
        'approved_by',
        'interest_rate',
        'late_fee_amount',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'order_date' => 'date',
            'due_date' => 'date',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'total_paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
        ];
    }

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function confirmedPayments()
    {
        return $this->hasMany(Payment::class)->where('status', 'confirmed');
    }

    public function pendingPayments()
    {
        return $this->hasMany(Payment::class)->where('status', 'pending');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    public function scopePartiallyPaid($query)
    {
        return $query->where('payment_status', 'partially_paid');
    }

    public function scopeFullyPaid($query)
    {
        return $query->where('payment_status', 'fully_paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->whereIn('payment_status', ['unpaid', 'partially_paid']);
    }

    // Helper methods
    public function isOverdue()
    {
        return $this->due_date && now()->gt($this->due_date) && !$this->isFullyPaid();
    }

    public function getDaysOverdue()
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->due_date);
    }

    public function getDaysSinceOrder()
    {
        return now()->diffInDays($this->order_date);
    }

    public function isFullyPaid()
    {
        return $this->payment_status === 'fully_paid' || $this->outstanding_amount <= 0;
    }

    public function isPartiallyPaid()
    {
        return $this->payment_status === 'partially_paid';
    }

    public function isUnpaid()
    {
        return $this->payment_status === 'unpaid';
    }

    public function getPaymentProgress()
    {
        if ($this->net_amount <= 0) return 100;

        return round(($this->total_paid_amount / $this->net_amount) * 100, 2);
    }

    public function getRemainingAmount()
    {
        return max(0, $this->net_amount - $this->total_paid_amount);
    }

    // Calculate current accrued interest
    public function calculateAccruedInterest()
    {
        if ($this->isFullyPaid()) {
            return 0;
        }

        $business = $this->business;
        $daysSinceOrder = $this->getDaysSinceOrder();
        $isLate = $this->isOverdue();

        return $business->calculateInterest($this->outstanding_amount, $daysSinceOrder, $isLate);
    }

    // Calculate late fees
    public function calculateLateFees()
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        $business = $this->business;
        $daysLate = $this->getDaysOverdue();

        return $business->calculateLateFees($this->outstanding_amount, $daysLate);
    }

    // Get total amount owed including interest and fees
    public function getTotalAmountOwed()
    {
        return $this->outstanding_amount + $this->calculateAccruedInterest() + $this->calculateLateFees();
    }

    // Update payment amounts after payment confirmation
    public function updatePaymentAmounts($paymentAmount)
    {
        $this->total_paid_amount += $paymentAmount;
        $this->outstanding_amount = max(0, $this->net_amount - $this->total_paid_amount);

        // Update payment status
        if ($this->outstanding_amount <= 0) {
            $this->payment_status = 'fully_paid';
        } elseif ($this->total_paid_amount > 0) {
            $this->payment_status = 'partially_paid';
        } else {
            $this->payment_status = 'unpaid';
        }

        $this->save();
    }

    // Get status badge class for UI
    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'draft' => 'badge-secondary',
            'pending' => 'badge-warning',
            'approved' => 'badge-success',
            'rejected' => 'badge-danger',
            'completed' => 'badge-info',
            'cancelled' => 'badge-dark',
            default => 'badge-secondary',
        };
    }

    public function getPaymentStatusBadgeClass()
    {
        return match($this->payment_status) {
            'unpaid' => 'badge-danger',
            'partially_paid' => 'badge-warning',
            'fully_paid' => 'badge-success',
            default => 'badge-secondary',
        };
    }

    // Generate unique PO number
    public static function generatePoNumber($businessId)
    {
        $prefix = 'PO';
        $year = date('Y');
        $businessCode = str_pad($businessId, 3, '0', STR_PAD_LEFT);

        $lastPo = self::where('business_id', $businessId)
                     ->where('po_number', 'like', $prefix . $year . $businessCode . '%')
                     ->orderBy('po_number', 'desc')
                     ->first();

        if ($lastPo) {
            $lastNumber = intval(substr($lastPo->po_number, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $year . $businessCode . $newNumber;
    }

    // Check if PO can receive payments
    public function canReceivePayment()
    {
        return in_array($this->status, ['approved', 'pending']) &&
               !$this->isFullyPaid() &&
               $this->outstanding_amount > 0;
    }

    // Get next payment amount suggestions
    public function getPaymentSuggestions()
    {
        $outstanding = $this->outstanding_amount;

        return [
            'minimum' => min(100, $outstanding), // Minimum $100 or remaining amount
            'quarter' => round($outstanding / 4, 2),
            'half' => round($outstanding / 2, 2),
            'full' => $outstanding,
        ];
    }

    // Calculate effective interest rate for this PO
    public function getEffectiveInterestRate()
    {
        if ($this->interest_rate) {
            return $this->interest_rate;
        }

        return $this->business->getEffectiveInterestRate();
    }

    // Get formatted items for display
    public function getFormattedItems()
    {
        if (!$this->items) {
            return [];
        }

        return collect($this->items)->map(function ($item) {
            return [
                'description' => $item['description'] ?? 'Item',
                'quantity' => number_format($item['quantity'] ?? 0, 2),
                'unit_price' => number_format($item['unit_price'] ?? 0, 2),
                'line_total' => number_format($item['line_total'] ?? 0, 2),
            ];
        })->toArray();
    }

    // Boot method for model events
    protected static function boot()
    {
        parent::boot();

        // Set initial outstanding amount when creating
        static::creating(function ($po) {
            if (!isset($po->outstanding_amount)) {
                $po->outstanding_amount = $po->net_amount;
            }
            if (!isset($po->total_paid_amount)) {
                $po->total_paid_amount = 0;
            }
            if (!isset($po->payment_status)) {
                $po->payment_status = 'unpaid';
            }
        });

        // Update business balances when PO status changes
        static::updating(function ($po) {
            if ($po->isDirty('status')) {
                $originalStatus = $po->getOriginal('status');
                $newStatus = $po->status;

                // Log status change
                Log::info("PO {$po->po_number} status changed from {$originalStatus} to {$newStatus}", [
                    'po_id' => $po->id,
                    'business_id' => $po->business_id,
                    'amount' => $po->net_amount,
                ]);
            }
        });
    }
}
