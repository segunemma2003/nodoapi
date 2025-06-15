<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessRiskTier extends Model
{
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
}
