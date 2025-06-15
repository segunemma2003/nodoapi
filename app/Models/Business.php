<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;

class Business extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'business_type',
        'registration_number',
        'password',
        'available_balance',      // Remaining spending power
        'current_balance',        // Total assigned credit (constant)
        'credit_balance',         // Outstanding debt
        'treasury_collateral_balance', // Admin-managed platform funds
        'credit_limit',          // Same as available_balance
        'is_active',
        'created_by',
        'risk_tier_id',
        'custom_interest_rate',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'available_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'credit_balance' => 'decimal:2',
            'treasury_collateral_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'custom_interest_rate' => 'decimal:2',
        ];
    }

    // Relationships
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function riskTier()
    {
        return $this->belongsTo(BusinessRiskTier::class, 'risk_tier_id');
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function balanceTransactions()
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    public function payments()
    {
        return $this->hasManyThrough(Payment::class, PurchaseOrder::class);
    }

    public function directPayments()
    {
        return $this->hasMany(Payment::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * CORRECTED BALANCE MANAGEMENT OPERATIONS
     * =======================================
     */

    /**
     * 1. ADMIN ASSIGNS INITIAL CREDIT
     * - Sets current_balance (total assigned credit)
     * - Sets available_balance (initial spending power)
     * - Sets credit_limit (same as available)
     */
    public function assignInitialCredit($amount, $adminId)
    {
        DB::beginTransaction();
        try {
            $this->current_balance = $amount;        // Total assigned credit
            $this->available_balance = $amount;      // Initial spending power
            $this->credit_limit = $amount;           // Same as available
            $this->credit_balance = 0;               // No debt yet
            $this->save();

            // Log the transaction
            $this->logBalanceTransaction(
                'current',
                $amount,
                'credit',
                'Initial credit assigned by admin',
                'admin_assignment',
                $adminId
            );

            $this->logBalanceTransaction(
                'available',
                $amount,
                'credit',
                'Initial spending power assigned',
                'admin_assignment',
                $adminId
            );

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 2. BUSINESS CREATES PURCHASE ORDER
     * - Platform pays vendor directly (not business)
     * - Reduces available_balance (less spending power)
     * - Reduces credit_limit (same as available)
     * - Increases credit_balance (debt created)
     * - current_balance UNCHANGED (assigned credit remains same)
     */
    public function createPurchaseOrder($amount, $poId)
    {
        DB::beginTransaction();
        try {
            // Check if sufficient spending power available
            if ($this->available_balance < $amount) {
                throw new \Exception('Insufficient available balance for purchase order');
            }

            // Reduce spending power
            $this->available_balance -= $amount;
            $this->credit_limit = $this->available_balance; // Keep in sync

            // Increase debt
            $this->credit_balance += $amount;

            // current_balance UNCHANGED - it's the total assigned credit

            $this->save();

            // Log the transactions
            $this->logBalanceTransaction(
                'available',
                $amount,
                'debit',
                'Purchase order created - spending power reduced',
                'purchase_order',
                $poId
            );

            $this->logBalanceTransaction(
                'credit',
                $amount,
                'debit',
                'Debt created for purchase order',
                'purchase_order',
                $poId
            );

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 3. BUSINESS SUBMITS PAYMENT
     * - Just records the payment, no balance changes yet
     * - Admin will decide whether to approve and restore credit
     */
    public function submitPayment($amount, $paymentId)
    {
        // No balance changes yet - just log the submission
        $this->logBalanceTransaction(
            'credit',
            $amount,
            'pending',
            'Payment submitted - awaiting admin approval',
            'payment',
            $paymentId
        );

        return true;
    }

    /**
     * 4. ADMIN APPROVES PAYMENT (RESTORES SPENDING POWER)
     * - Increases available_balance (restored spending power)
     * - Increases credit_limit (same as available)
     * - Decreases credit_balance (debt reduced)
     * - current_balance UNCHANGED (assigned credit remains same)
     */
    public function approvePayment($amount, $paymentId)
    {
        DB::beginTransaction();
        try {
            // Ensure we don't over-restore (payment can't exceed debt)
            $actualAmount = min($amount, $this->credit_balance);

            // Restore spending power
            $this->available_balance += $actualAmount;
            $this->credit_limit = $this->available_balance; // Keep in sync

            // Reduce debt
            $this->credit_balance -= $actualAmount;
            $this->credit_balance = max(0, $this->credit_balance); // No negative debt

            // Ensure available doesn't exceed current (assigned credit)
            $this->available_balance = min($this->available_balance, $this->current_balance);
            $this->credit_limit = $this->available_balance;

            $this->save();

            // Log the transactions
            $this->logBalanceTransaction(
                'available',
                $actualAmount,
                'credit',
                'Payment approved - spending power restored',
                'payment',
                $paymentId
            );

            $this->logBalanceTransaction(
                'credit',
                $actualAmount,
                'credit',
                'Debt reduced by approved payment',
                'payment',
                $paymentId
            );

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 5. ADMIN REJECTS PAYMENT
     * - No balance changes
     * - Just log the rejection
     */
    public function rejectPayment($amount, $paymentId, $reason = null)
    {
        // No balance changes - just log the rejection
        $this->logBalanceTransaction(
            'credit',
            $amount,
            'rejected',
            'Payment rejected: ' . ($reason ?? 'No reason provided'),
            'payment',
            $paymentId
        );

        return true;
    }

    /**
     * 6. ADMIN APPLIES INTEREST (OPTIONAL)
     * - Increases credit_balance (debt)
     * - Reduces available_balance (less spending power)
     * - current_balance UNCHANGED
     */
    public function applyInterest($interestAmount, $reason = 'Periodic interest charge')
    {
        if ($interestAmount <= 0) {
            return 0;
        }

        DB::beginTransaction();
        try {
            // Increase debt
            $this->credit_balance += $interestAmount;

            // Reduce spending power (but not below 0)
            $this->available_balance = max(0, $this->available_balance - $interestAmount);
            $this->credit_limit = $this->available_balance;

            $this->save();

            // Log the transaction
            $this->logBalanceTransaction(
                'credit',
                $interestAmount,
                'debit',
                $reason,
                'interest_charge',
                null
            );

            $this->logBalanceTransaction(
                'available',
                $interestAmount,
                'debit',
                'Spending power reduced due to interest charge',
                'interest_charge',
                null
            );

            DB::commit();
            return $interestAmount;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 7. ADMIN ADJUSTS ASSIGNED CREDIT
     * - Changes current_balance (total assigned credit)
     * - Adjusts available_balance proportionally
     * - Maintains credit_limit = available_balance
     */
    public function adjustAssignedCredit($newAmount, $reason, $adminId)
    {
        DB::beginTransaction();
        try {
            $oldAmount = $this->current_balance;
            $difference = $newAmount - $oldAmount;

            // Update assigned credit
            $this->current_balance = $newAmount;

            // Adjust available spending power
            $this->available_balance += $difference;
            $this->available_balance = max(0, $this->available_balance); // No negative available
            $this->credit_limit = $this->available_balance;

            $this->save();

            // Log the adjustment
            $this->logBalanceTransaction(
                'current',
                abs($difference),
                $difference > 0 ? 'credit' : 'debit',
                "Credit limit adjusted: {$reason}",
                'admin_adjustment',
                $adminId
            );

            $this->logBalanceTransaction(
                'available',
                abs($difference),
                $difference > 0 ? 'credit' : 'debit',
                "Spending power adjusted due to credit limit change",
                'admin_adjustment',
                $adminId
            );

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 8. ADMIN MANAGES TREASURY (PLATFORM FUNDS)
     * - Only admin can update treasury_collateral_balance
     * - This represents platform's actual money
     */
    public function updateTreasury($amount, $operation, $description, $adminId)
    {
        DB::beginTransaction();
        try {
            if ($operation === 'add') {
                $this->treasury_collateral_balance += $amount;
            } else {
                $this->treasury_collateral_balance -= $amount;
                $this->treasury_collateral_balance = max(0, $this->treasury_collateral_balance);
            }

            $this->save();

            // Log the transaction
            $this->logBalanceTransaction(
                'treasury_collateral',
                $amount,
                $operation === 'add' ? 'credit' : 'debit',
                $description,
                'treasury_management',
                $adminId
            );

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * HELPER METHODS
     * ==============
     */

    public function getAvailableSpendingPower()
    {
        return $this->available_balance; // Same as credit_limit
    }

    public function getTotalAssignedCredit()
    {
        return $this->current_balance; // Never changes unless admin adjusts
    }

    public function getOutstandingDebt()
    {
        return $this->credit_balance;
    }

    public function getCreditUtilization()
    {
        if ($this->current_balance <= 0) return 0;
        return round(($this->credit_balance / $this->current_balance) * 100, 2);
    }

    public function getSpendingPowerUtilization()
    {
        if ($this->current_balance <= 0) return 0;
        $usedAmount = $this->current_balance - $this->available_balance;
        return round(($usedAmount / $this->current_balance) * 100, 2);
    }

    public function canCreatePurchaseOrder($amount)
    {
        return $this->available_balance >= $amount;
    }

    public function getMaxPaymentAmount()
    {
        return $this->credit_balance; // Can't pay more than what's owed
    }

    public function getSpendingHistory()
    {
        return $this->purchaseOrders()
            ->selectRaw('DATE(created_at) as date, SUM(net_amount) as total_spent')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();
    }

    public function getPaymentHistory()
    {
        return $this->payments()
            ->where('status', 'confirmed')
            ->selectRaw('DATE(confirmed_at) as date, SUM(amount) as total_paid')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();
    }

    /**
     * INTEREST RATE METHODS (OPTIONAL)
     * ================================
     */

    public function getEffectiveInterestRate()
    {
        // Custom rate for this business
        if ($this->custom_interest_rate) {
            return $this->custom_interest_rate;
        }

        // Risk tier rate
        if ($this->riskTier) {
            return $this->riskTier->interest_rate;
        }

        // System default rate
        return SystemSetting::getValue('base_interest_rate', 0);
    }

    public function calculatePotentialInterest($days = 30)
    {
        if ($this->credit_balance <= 0) return 0;

        $annualRate = $this->getEffectiveInterestRate();
        if ($annualRate <= 0) return 0; // Interest not applied

        $dailyRate = $annualRate / 365 / 100;
        return round($this->credit_balance * $dailyRate * $days, 2);
    }

    /**
     * LOG BALANCE TRANSACTIONS
     * ========================
     */
    private function logBalanceTransaction($balanceType, $amount, $transactionType, $description, $referenceType = null, $referenceId = null)
    {
        $balanceField = $balanceType . '_balance';
        $balanceBefore = $this->getOriginal($balanceField) ?? 0;
        $balanceAfter = $this->$balanceField ?? 0;

        BalanceTransaction::create([
            'business_id' => $this->id,
            'transaction_type' => $transactionType, // 'credit', 'debit', 'pending', 'rejected'
            'balance_type' => $balanceType,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ]);
    }

    /**
     * BALANCE VALIDATION
     * ==================
     */
    public function validateBalances()
    {
        $errors = [];

        // Check for negative balances
        if ($this->available_balance < 0) {
            $errors[] = 'Available balance cannot be negative';
        }
        if ($this->current_balance < 0) {
            $errors[] = 'Current balance cannot be negative';
        }
        if ($this->credit_balance < 0) {
            $errors[] = 'Credit balance cannot be negative';
        }

        // Check logical constraints
        if ($this->available_balance > $this->current_balance) {
            $errors[] = 'Available balance cannot exceed assigned credit (current balance)';
        }

        if ($this->credit_limit != $this->available_balance) {
            $errors[] = 'Credit limit should equal available balance';
        }

        // Check if debt makes sense
        $expectedDebt = $this->current_balance - $this->available_balance;
        if (abs($this->credit_balance - $expectedDebt) > 0.01) {
            $errors[] = 'Credit balance (debt) does not match expected amount based on spending';
        }

        return $errors;
    }

    /**
     * RECONCILIATION METHODS
     * ======================
     */
    public function reconcileBalances()
    {
        // Recalculate balances from PO and payment history
        $totalSpent = $this->purchaseOrders()->sum('net_amount');
        $totalPaid = $this->payments()->where('status', 'confirmed')->sum('amount');

        $expectedDebt = $totalSpent - $totalPaid;
        $expectedAvailable = $this->current_balance - $expectedDebt;

        // Update balances
        $this->credit_balance = max(0, $expectedDebt);
        $this->available_balance = max(0, $expectedAvailable);
        $this->credit_limit = $this->available_balance;
        $this->save();

        return [
            'current' => $this->current_balance,
            'available' => $this->available_balance,
            'credit' => $this->credit_balance,
            'treasury_collateral' => $this->treasury_collateral_balance,
        ];
    }

    /**
     * PAYMENT PERFORMANCE METRICS
     * ===========================
     */
    public function getPaymentScore()
    {
        $totalPayments = $this->payments()->where('status', 'confirmed')->count();

        if ($totalPayments === 0) return 0;

        $onTimePayments = $this->payments()
            ->where('status', 'confirmed')
            ->whereHas('purchaseOrder', function($query) {
                $query->whereRaw('payments.confirmed_at <= purchase_orders.due_date');
            })
            ->count();

        return round(($onTimePayments / $totalPayments) * 100, 2);
    }

    public function getAveragePaymentTime()
    {
        return $this->payments()
            ->where('status', 'confirmed')
            ->whereHas('purchaseOrder')
            ->get()
            ->avg(function($payment) {
                return $payment->confirmed_at->diffInDays($payment->purchaseOrder->order_date);
            });
    }

     public function getInterestRateConfig()
    {
        // Custom rate configuration for this business
        if ($this->custom_interest_rate && $this->custom_interest_frequency) {
            return [
                'rate' => $this->custom_interest_rate,
                'frequency' => $this->custom_interest_frequency,
                'source' => 'custom',
            ];
        }

        // Risk tier rate configuration
        if ($this->riskTier && $this->riskTier->interest_rate) {
            return [
                'rate' => $this->riskTier->interest_rate,
                'frequency' => $this->riskTier->interest_frequency ?? 'annual',
                'source' => 'risk_tier',
            ];
        }

        // System default rate configuration
        return array_merge(
            SystemSetting::getInterestRateConfig('base_interest_rate'),
            ['source' => 'system_default']
        );
    }

    /**
     * Calculate interest for specific period and frequency
     */
    public function calculateInterest($principal, $days, $rateConfig = null)
    {
        if ($principal <= 0) return 0;

        $config = $rateConfig ?? $this->getInterestRateConfig();
        $rate = $config['rate'];
        $frequency = $config['frequency'];

        if ($rate <= 0) return 0;

        switch ($frequency) {
            case 'daily':
                return round($principal * ($rate / 100) * $days, 2);

            case 'weekly':
                $weeks = $days / 7;
                return round($principal * ($rate / 100) * $weeks, 2);

            case 'monthly':
                $months = $days / 30.44; // Average month length
                return round($principal * ($rate / 100) * $months, 2);

            case 'quarterly':
                $quarters = $days / 91.31; // Average quarter length
                return round($principal * ($rate / 100) * $quarters, 2);

            case 'annual':
                $years = $days / 365;
                return round($principal * ($rate / 100) * $years, 2);

            default:
                throw new \Exception("Unsupported interest frequency: {$frequency}");
        }
    }
 /**
     * Calculate interest for exact frequency period
     */
    public function calculatePeriodInterest($principal, $periods = 1, $rateConfig = null)
    {
        if ($principal <= 0 || $periods <= 0) return 0;

        $config = $rateConfig ?? $this->getInterestRateConfig();
        $rate = $config['rate'];

        if ($rate <= 0) return 0;

        return round($principal * ($rate / 100) * $periods, 2);
    }

    /**
     * Apply interest based on configured frequency
     */
    public function applyPeriodicInterest($reason = null, $periods = 1)
    {
        if ($this->credit_balance <= 0) return 0;

        $config = $this->getInterestRateConfig();
        $interestAmount = $this->calculatePeriodInterest($this->credit_balance, $periods, $config);

        if ($interestAmount <= 0) return 0;

        $frequency = $config['frequency'];
        $defaultReason = $reason ?? ucfirst($frequency) . " interest charge at {$config['rate']}% {$frequency} rate";

        return $this->applyInterest($interestAmount, $defaultReason);
    }

    /**
     * Check if interest is due based on frequency
     */
    public function isInterestDue($lastApplicationDate = null)
    {
        $config = $this->getInterestRateConfig();

        if (!$config['auto_apply'] || $config['rate'] <= 0) {
            return false;
        }

        $lastDate = $lastApplicationDate ?? $this->last_interest_applied_at ?? $this->created_at;
        $frequency = $config['frequency'];
        $now = now();

        switch ($frequency) {
            case 'daily':
                return $now->diffInDays($lastDate) >= 1;

            case 'weekly':
                return $now->diffInWeeks($lastDate) >= 1;

            case 'monthly':
                // Check if it's the right day of month and at least a month has passed
                $applyDay = min($config['apply_day'], $now->daysInMonth);
                return $now->day >= $applyDay && $now->diffInMonths($lastDate) >= 1;

            case 'quarterly':
                return $now->diffInMonths($lastDate) >= 3;

            case 'annual':
                return $now->diffInYears($lastDate) >= 1;

            default:
                return false;
        }
    }

    /**
     * Get next interest application date
     */
    public function getNextInterestDate($lastApplicationDate = null)
    {
        $config = $this->getInterestRateConfig();
        $lastDate = $lastApplicationDate ?? $this->last_interest_applied_at ?? $this->created_at;
        $frequency = $config['frequency'];

        switch ($frequency) {
            case 'daily':
                return $lastDate->copy()->addDay();

            case 'weekly':
                return $lastDate->copy()->addWeek();

            case 'monthly':
                $nextDate = $lastDate->copy()->addMonth();
                $applyDay = min($config['apply_day'], $nextDate->daysInMonth);
                return $nextDate->day($applyDay);

            case 'quarterly':
                return $lastDate->copy()->addMonths(3);

            case 'annual':
                return $lastDate->copy()->addYear();

            default:
                return null;
        }
    }

    /**
     * Enhanced interest calculation with compound option
     */
    public function calculateCompoundInterest($principal, $periods, $compounding = 'simple')
    {
        $config = $this->getInterestRateConfig();
        $rate = $config['rate'] / 100;

        if ($compounding === 'compound') {
            $amount = $principal * pow((1 + $rate), $periods);
            return round($amount - $principal, 2);
        } else {
            // Simple interest
            return $this->calculatePeriodInterest($principal, $periods, $config);
        }
    }

}
