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
public function scopePending($query)
{
    return $query->where('purchase_orders.status', 'pending');
}

public function scopeApproved($query)
{
    return $query->where('purchase_orders.status', 'approved');
}

public function scopeDraft($query)
{
    return $query->where('purchase_orders.status', 'draft');
}

public function scopeForBusiness($query, $businessId)
{
    return $query->where('purchase_orders.business_id', $businessId);
}

public function scopeUnpaid($query)
{
    return $query->where('purchase_orders.payment_status', 'unpaid');
}

public function scopePartiallyPaid($query)
{
    return $query->where('purchase_orders.payment_status', 'partially_paid');
}

public function scopeFullyPaid($query)
{
    return $query->where('purchase_orders.payment_status', 'fully_paid');
}

public function scopeOverdue($query)
{
    return $query->where('purchase_orders.due_date', '<', now())
                ->whereIn('purchase_orders.payment_status', ['unpaid', 'partially_paid']);
}
    // Scopes
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
        $oldPaymentStatus = $this->payment_status;

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

        // Send webhook if payment status changed
        if ($oldPaymentStatus !== $this->payment_status) {
            try {
                $webhookController = app(\App\Http\Controllers\Api\WebhookController::class);
                $webhookController->sendPaymentStatusWebhook($this, $oldPaymentStatus);
            } catch (\Exception $e) {
                Log::error('Failed to send payment status webhook', [
                    'po_id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

        // Send webhook when PO is created
        static::created(function ($po) {
            try {
                $webhookController = app(\App\Http\Controllers\Api\WebhookController::class);
                $webhookController->sendPoCreatedWebhook($po);
            } catch (\Exception $e) {
                Log::error('Failed to send PO creation webhook', [
                    'po_id' => $po->id,
                    'error' => $e->getMessage(),
                ]);
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


    /**
 * Get approval status information
 */
public function getApprovalInfo()
{
    return [
        'can_be_approved' => $this->status === 'pending',
        'can_be_rejected' => $this->status === 'pending',
        'approval_required' => in_array($this->status, ['pending', 'draft']),
        'approved_by_name' => $this->approvedBy?->name,
        'approved_at_formatted' => $this->approved_at?->format('Y-m-d H:i:s'),
    ];
}

/**
 * Get payment status summary
 */
public function getPaymentStatusSummary()
{
    $confirmedPayments = $this->payments()->where('status', 'confirmed')->get();
    $pendingPayments = $this->payments()->where('status', 'pending')->get();
    $rejectedPayments = $this->payments()->where('status', 'rejected')->get();

    return [
        'total_payments' => $this->payments()->count(),
        'confirmed_payments' => $confirmedPayments->count(),
        'pending_payments' => $pendingPayments->count(),
        'rejected_payments' => $rejectedPayments->count(),
        'confirmed_amount' => $confirmedPayments->sum('amount'),
        'pending_amount' => $pendingPayments->sum('amount'),
        'last_payment_date' => $confirmedPayments->max('confirmed_at'),
        'needs_payment' => $this->outstanding_amount > 0,
        'fully_paid' => $this->outstanding_amount <= 0,
    ];
}

/**
 * Calculate various financial metrics
 */
public function getFinancialMetrics()
{
    return [
        'net_amount' => $this->net_amount,
        'total_paid_amount' => $this->total_paid_amount,
        'outstanding_amount' => $this->outstanding_amount,
        'payment_progress_percentage' => $this->getPaymentProgress(),
        'remaining_amount' => $this->getRemainingAmount(),
        'accrued_interest' => $this->calculateAccruedInterest(),
        'late_fees' => $this->calculateLateFees(),
        'total_amount_owed' => $this->getTotalAmountOwed(),
    ];
}

/**
 * Get timeline information
 */
public function getTimelineInfo()
{
    return [
        'order_date' => $this->order_date,
        'due_date' => $this->due_date,
        'expected_delivery_date' => $this->expected_delivery_date,
        'days_since_order' => $this->getDaysSinceOrder(),
        'days_until_due' => $this->due_date ? now()->diffInDays($this->due_date, false) : null,
        'is_overdue' => $this->isOverdue(),
        'days_overdue' => $this->getDaysOverdue(),
        'urgency_level' => $this->getUrgencyLevel(),
    ];
}

/**
 * Get urgency level based on due date and payment status
 */
public function getUrgencyLevel()
{
    if ($this->isFullyPaid()) {
        return 'none';
    }

    if ($this->isOverdue()) {
        $daysOverdue = $this->getDaysOverdue();
        if ($daysOverdue > 30) {
            return 'critical';
        } elseif ($daysOverdue > 14) {
            return 'high';
        } else {
            return 'medium';
        }
    }

    $daysUntilDue = $this->due_date ? now()->diffInDays($this->due_date, false) : 999;
    if ($daysUntilDue <= 3) {
        return 'high';
    } elseif ($daysUntilDue <= 7) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * Get vendor information summary
 */
public function getVendorInfo()
{
    return [
        'vendor_id' => $this->vendor_id,
        'vendor_name' => $this->vendor?->name,
        'vendor_email' => $this->vendor?->email,
        'vendor_category' => $this->vendor?->category,
        'vendor_code' => $this->vendor?->vendor_code,
    ];
}

/**
 * Get business information summary
 */
public function getBusinessInfo()
{
    return [
        'business_id' => $this->business_id,
        'business_name' => $this->business?->name,
        'business_email' => $this->business?->email,
        'business_type' => $this->business?->business_type,
        'current_utilization' => $this->business?->getCreditUtilization(),
        'payment_score' => $this->business?->getPaymentScore(),
    ];
}

/**
 * Get admin action suggestions
 */
public function getAdminActionSuggestions()
{
    $suggestions = [];

    // Approval suggestions
    if ($this->status === 'pending') {
        $suggestions[] = [
            'type' => 'approval',
            'action' => 'Review and approve/reject this purchase order',
            'priority' => 'high',
            'reason' => 'Purchase order awaiting admin approval'
        ];
    }

    // Payment follow-up suggestions
    if ($this->isOverdue() && $this->outstanding_amount > 0) {
        $suggestions[] = [
            'type' => 'payment_followup',
            'action' => 'Contact business about overdue payment',
            'priority' => 'urgent',
            'reason' => "Payment is {$this->getDaysOverdue()} days overdue"
        ];
    }

    // Interest application suggestions
    if ($this->outstanding_amount > 0 && $this->business->getEffectiveInterestRate() > 0) {
        $suggestions[] = [
            'type' => 'interest',
            'action' => 'Consider applying interest charges',
            'priority' => 'medium',
            'reason' => 'Outstanding debt with applicable interest rate'
        ];
    }

    // Large amount alerts
    if ($this->net_amount > 50000) {
        $suggestions[] = [
            'type' => 'review',
            'action' => 'Review large purchase order details',
            'priority' => 'medium',
            'reason' => 'High-value purchase order requires attention'
        ];
    }

    return $suggestions;
}

/**
 * Get comprehensive purchase order summary for admin
 */
public function getAdminSummary()
{
    return [
        'basic_info' => [
            'po_number' => $this->po_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'description' => $this->description,
            'notes' => $this->notes,
        ],
        'financial_metrics' => $this->getFinancialMetrics(),
        'timeline_info' => $this->getTimelineInfo(),
        'payment_summary' => $this->getPaymentStatusSummary(),
        'approval_info' => $this->getApprovalInfo(),
        'vendor_info' => $this->getVendorInfo(),
        'business_info' => $this->getBusinessInfo(),
        'admin_suggestions' => $this->getAdminActionSuggestions(),
        'risk_indicators' => [
            'is_high_value' => $this->net_amount > 50000,
            'is_overdue' => $this->isOverdue(),
            'business_high_utilization' => $this->business?->getCreditUtilization() > 80,
            'urgency_level' => $this->getUrgencyLevel(),
        ]
    ];
}

/**
 * Check if PO can be modified
 */
public function canBeModified()
{
    return in_array($this->status, ['draft', 'pending']);
}

/**
 * Check if PO can be cancelled
 */
public function canBeCancelled()
{
    return !in_array($this->status, ['completed', 'cancelled']) && !$this->isFullyPaid();
}

/**
 * Get status transition options
 */
public function getAvailableStatusTransitions()
{
    $transitions = [];

    switch ($this->status) {
        case 'draft':
            $transitions = ['pending', 'cancelled'];
            break;
        case 'pending':
            $transitions = ['approved', 'rejected', 'cancelled'];
            break;
        case 'approved':
            $transitions = ['completed', 'cancelled'];
            break;
        case 'rejected':
            $transitions = ['pending', 'cancelled'];
            break;
        case 'completed':
            // No transitions from completed
            break;
        case 'cancelled':
            $transitions = ['pending']; // Allow reactivation if needed
            break;
    }

    return $transitions;
}

/**
 * Validate status transition
 */
public function canTransitionTo($newStatus)
{
    return in_array($newStatus, $this->getAvailableStatusTransitions());
}

/**
 * Get items summary for display
 */
public function getItemsSummary()
{
    if (!$this->items) {
        return ['count' => 0, 'summary' => 'No items listed'];
    }

    $itemCount = count($this->items);
    $firstItem = $this->items[0]['description'] ?? 'Item';

    if ($itemCount === 1) {
        return [
            'count' => 1,
            'summary' => $firstItem
        ];
    } else {
        return [
            'count' => $itemCount,
            'summary' => "{$firstItem} and " . ($itemCount - 1) . " other item(s)"
        ];
    }
}
}
