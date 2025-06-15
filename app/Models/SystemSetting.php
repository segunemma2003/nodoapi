<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'category',
        'description',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    // Helper methods
    public static function getValue($key, $default = null)
    {
        $setting = self::where('key', $key)->where('is_active', true)->first();

        if (!$setting) {
            return $default;
        }

        return match($setting->type) {
            'percentage', 'amount' => (float) $setting->value,
            'boolean' => (bool) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function setValue($key, $value, $type = 'string', $category = 'general', $description = null)
    {
        $processedValue = match($type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };

        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $processedValue,
                'type' => $type,
                'category' => $category,
                'description' => $description,
                'updated_by' => Auth::id(),
            ]
        );
    }

    // Get all interest rate settings
    public static function getInterestRates()
    {
        return self::where('category', 'interest_rates')
                  ->where('is_active', true)
                  ->pluck('value', 'key')
                  ->map(fn($value) => (float) $value);
    }

    // Get default system interest rates
    public static function getDefaultRates()
    {
        return [
            'base_interest_rate' => self::getValue('base_interest_rate', 12.0), // 12% annually
            'late_payment_rate' => self::getValue('late_payment_rate', 18.0), // 18% annually
            'premium_tier_rate' => self::getValue('premium_tier_rate', 8.0), // 8% for premium businesses
            'high_risk_tier_rate' => self::getValue('high_risk_tier_rate', 24.0), // 24% for high risk
            'processing_fee_rate' => self::getValue('processing_fee_rate', 2.5), // 2.5% processing fee
        ];
    }

        public static function getInterestRateConfig($rateType = 'base_interest_rate')
    {
        $rate = self::getValue($rateType, 0);
        $frequency = self::getValue($rateType . '_frequency', 'annual');
        $autoApply = self::getValue($rateType . '_auto_apply', false);
        $applyDay = self::getValue($rateType . '_apply_day', 1); // Day of month for monthly

        return [
            'rate' => $rate,
            'frequency' => $frequency, // 'daily', 'weekly', 'monthly', 'quarterly', 'annual'
            'auto_apply' => $autoApply,
            'apply_day' => $applyDay,
        ];
    }

    /**
     * Set interest rate with frequency
     */
    public static function setInterestRateConfig($rateType, $rate, $frequency, $autoApply = false, $applyDay = 1)
    {
        self::setValue($rateType, $rate, 'percentage', 'interest_rates');
        self::setValue($rateType . '_frequency', $frequency, 'string', 'interest_rates');
        self::setValue($rateType . '_auto_apply', $autoApply, 'boolean', 'interest_rates');
        self::setValue($rateType . '_apply_day', $applyDay, 'integer', 'interest_rates');
    }

    /**
     * Get all interest rate frequencies
     */
    public static function getAvailableFrequencies()
    {
        return [
            'daily' => [
                'label' => 'Daily',
                'description' => 'Applied every day',
                'periods_per_year' => 365,
            ],
            'weekly' => [
                'label' => 'Weekly',
                'description' => 'Applied every week',
                'periods_per_year' => 52,
            ],
            'monthly' => [
                'label' => 'Monthly',
                'description' => 'Applied every month',
                'periods_per_year' => 12,
            ],
            'quarterly' => [
                'label' => 'Quarterly',
                'description' => 'Applied every 3 months',
                'periods_per_year' => 4,
            ],
            'annual' => [
                'label' => 'Annual',
                'description' => 'Applied once per year',
                'periods_per_year' => 1,
            ]
        ];
    }
}
