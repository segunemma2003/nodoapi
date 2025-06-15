<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessRiskTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'tier_name',
        'tier_code',
        'interest_rate',
        'interest_frequency',
        'credit_limit_multiplier',
        'criteria',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:2',
            'credit_limit_multiplier' => 'decimal:2',
            'criteria' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function businesses()
    {
        return $this->hasMany(Business::class, 'risk_tier_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helper methods

    /**
     * Get human-readable interest description
     */
    public function getInterestDescription()
    {
        if (!$this->interest_rate) {
            return 'No interest applied';
        }

        $frequency = $this->interest_frequency ?? 'annual';
        return "{$this->interest_rate}% {$frequency}";
    }

    /**
     * Calculate annual equivalent rate for comparison
     */
    public function getAnnualEquivalentRate()
    {
        if (!$this->interest_rate) return 0;

        $frequencies = SystemSetting::getAvailableFrequencies();
        $periodsPerYear = $frequencies[$this->interest_frequency ?? 'annual']['periods_per_year'];

        return $this->interest_rate * $periodsPerYear;
    }

    /**
     * Get risk level based on interest rate
     */
    public function getRiskLevel()
    {
        $annualRate = $this->getAnnualEquivalentRate();

        if ($annualRate >= 20) {
            return 'high';
        } elseif ($annualRate >= 12) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Check if a business qualifies for this tier
     */
    public function businessQualifies(Business $business)
    {
        if (!$this->criteria || empty($this->criteria)) {
            return true; // No criteria means all businesses qualify
        }

        foreach ($this->criteria as $criterion => $value) {
            switch ($criterion) {
                case 'min_payment_score':
                    if ($business->getPaymentScore() < $value) {
                        return false;
                    }
                    break;
                case 'max_credit_utilization':
                    if ($business->getCreditUtilization() > $value) {
                        return false;
                    }
                    break;
                case 'min_business_age_months':
                    if ($business->created_at->diffInMonths(now()) < $value) {
                        return false;
                    }
                    break;
                case 'min_annual_revenue':
                    // This would need to be implemented based on business financial data
                    break;
                case 'required_business_types':
                    if (is_array($value) && !in_array($business->business_type, $value)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Auto-assign eligible businesses to this tier
     */
    public function autoAssignEligibleBusinesses()
    {
        $eligibleBusinesses = Business::where('is_active', true)
            ->whereNull('risk_tier_id') // Only businesses without a tier
            ->get()
            ->filter(function ($business) {
                return $this->businessQualifies($business);
            });

        $assignedCount = 0;
        foreach ($eligibleBusinesses as $business) {
            $business->update(['risk_tier_id' => $this->id]);
            $assignedCount++;
        }

        return $assignedCount;
    }

    /**
     * Get tier statistics
     */
    public function getStatistics()
    {
        $businesses = $this->businesses()->where('is_active', true)->get();

        return [
            'total_businesses' => $businesses->count(),
            'total_credit_assigned' => $businesses->sum('current_balance'),
            'total_outstanding_debt' => $businesses->sum('credit_balance'),
            'average_credit_utilization' => $businesses->avg(function($b) {
                return $b->getCreditUtilization();
            }),
            'average_payment_score' => $businesses->avg(function($b) {
                return $b->getPaymentScore();
            }),
            'businesses_with_debt' => $businesses->where('credit_balance', '>', 0)->count(),
            'high_utilization_businesses' => $businesses->filter(function($b) {
                return $b->getCreditUtilization() > 80;
            })->count(),
        ];
    }

    /**
     * Generate tier code automatically
     */
    public static function generateTierCode($tierName)
    {
        // Take first 3 letters of tier name
        $code = strtoupper(substr($tierName, 0, 3));

        // Add number if code already exists
        $counter = 1;
        $originalCode = $code;

        while (self::where('tier_code', $code)->exists()) {
            $code = $originalCode . $counter;
            $counter++;
        }

        return $code;
    }

    /**
     * Get recommended tier for business
     */
    public static function getRecommendedTierForBusiness(Business $business)
    {
        $tiers = self::active()->get();

        // Find tiers the business qualifies for
        $qualifiedTiers = $tiers->filter(function ($tier) use ($business) {
            return $tier->businessQualifies($business);
        });

        if ($qualifiedTiers->isEmpty()) {
            return null;
        }

        // Return the tier with the lowest interest rate among qualified tiers
        return $qualifiedTiers->sortBy('interest_rate')->first();
    }

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate tier code if not provided
        static::creating(function ($tier) {
            if (!$tier->tier_code) {
                $tier->tier_code = self::generateTierCode($tier->tier_name);
            }
        });

        // Set default values
        static::creating(function ($tier) {
            if (!isset($tier->interest_frequency)) {
                $tier->interest_frequency = 'annual';
            }
            if (!isset($tier->credit_limit_multiplier)) {
                $tier->credit_limit_multiplier = 1.0;
            }
        });
    }
}
