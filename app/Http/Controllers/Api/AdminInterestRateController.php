<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\BusinessRiskTier;
use App\Models\InterestRateHistory;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminInterestRateController extends Controller
{
    /**
     * Get available interest rate frequencies
     */
    public function getAvailableFrequencies()
    {
        $frequencies = SystemSetting::getAvailableFrequencies();

        return response()->json([
            'success' => true,
            'message' => 'Available interest rate frequencies retrieved successfully',
            'data' => [
                'frequencies' => $frequencies,
                'current_system_rates' => [
                    'base_rate' => SystemSetting::getInterestRateConfig('base_interest_rate'),
                    'late_payment_rate' => SystemSetting::getInterestRateConfig('late_payment_rate'),
                    'premium_tier_rate' => SystemSetting::getInterestRateConfig('premium_tier_rate'),
                ],
                'usage_info' => [
                    'daily' => 'Best for high-volume, short-term credit',
                    'weekly' => 'Good for regular payment cycles',
                    'monthly' => 'Standard business billing cycle',
                    'quarterly' => 'For seasonal businesses',
                    'annual' => 'Traditional annual percentage rate',
                ]
            ]
        ]);
    }

    /**
     * Get system settings for interest rates
     */
    public function getSystemSettings()
    {
        $settings = [
            // Interest rate settings
            'interest_rates' => [
                'base_interest_rate' => SystemSetting::getInterestRateConfig('base_interest_rate'),
                'late_payment_rate' => SystemSetting::getInterestRateConfig('late_payment_rate'),
                'premium_tier_rate' => SystemSetting::getInterestRateConfig('premium_tier_rate'),
            ],

            // General settings
            'general' => [
                'default_payment_terms_days' => SystemSetting::getValue('default_payment_terms_days', 30),
                'grace_period_days' => SystemSetting::getValue('grace_period_days', 5),
                'auto_interest_accrual_enabled' => SystemSetting::getValue('auto_interest_accrual_enabled', false),
                'interest_calculation_method' => SystemSetting::getValue('interest_calculation_method', 'simple'),
                'max_credit_limit' => SystemSetting::getValue('max_credit_limit', 1000000),
                'min_payment_amount' => SystemSetting::getValue('min_payment_amount', 100),
            ],

            // Business settings
            'business' => [
                'require_business_registration' => SystemSetting::getValue('require_business_registration', true),
                'auto_approve_low_amounts' => SystemSetting::getValue('auto_approve_low_amounts', false),
                'low_amount_threshold' => SystemSetting::getValue('low_amount_threshold', 1000),
                'max_pending_pos' => SystemSetting::getValue('max_pending_pos', 10),
            ],

            // Email and notification settings
            'notifications' => [
                'send_payment_confirmations' => SystemSetting::getValue('send_payment_confirmations', true),
                'send_overdue_notifications' => SystemSetting::getValue('send_overdue_notifications', true),
                'overdue_notification_days' => SystemSetting::getValue('overdue_notification_days', [1, 3, 7, 14]),
                'admin_notification_email' => SystemSetting::getValue('admin_notification_email', 'admin@company.com'),
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'System settings retrieved successfully',
            'data' => $settings
        ]);
    }

    /**
     * Update system settings
     */
    public function updateSystemSettings(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required',
            'settings.*.type' => 'nullable|string|in:string,integer,float,boolean,json',
            'settings.*.category' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $updatedSettings = [];

            foreach ($request->settings as $setting) {
                $key = $setting['key'];
                $value = $setting['value'];
                $type = $setting['type'] ?? 'string';
                $category = $setting['category'] ?? 'general';

                // Validate specific settings
                $this->validateSettingValue($key, $value);

                // Update or create setting
                SystemSetting::setValue($key, $value, $type, $category);

                $updatedSettings[] = [
                    'key' => $key,
                    'value' => $value,
                    'type' => $type,
                    'category' => $category,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'System settings updated successfully',
                'data' => [
                    'updated_settings' => $updatedSettings,
                    'updated_count' => count($updatedSettings),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update system settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get risk tiers
     */
    public function getRiskTiers()
    {
        $tiers = BusinessRiskTier::where('is_active', true)
            ->orderBy('tier_code')
            ->get()
            ->map(function ($tier) {
                return [
                    'id' => $tier->id,
                    'tier_name' => $tier->tier_name,
                    'tier_code' => $tier->tier_code,
                    'interest_rate' => $tier->interest_rate,
                    'interest_frequency' => $tier->interest_frequency,
                    'interest_description' => $tier->getInterestDescription(),
                    'annual_equivalent_rate' => $tier->getAnnualEquivalentRate(),
                    'credit_limit_multiplier' => $tier->credit_limit_multiplier,
                    'criteria' => $tier->criteria,
                    'businesses_count' => Business::where('risk_tier_id', $tier->id)->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Risk tiers retrieved successfully',
            'data' => [
                'risk_tiers' => $tiers,
                'available_frequencies' => SystemSetting::getAvailableFrequencies(),
            ]
        ]);
    }

    /**
     * Create new risk tier
     */
    public function createRiskTier(Request $request)
    {
        $request->validate([
            'tier_name' => 'required|string|max:255',
            'tier_code' => 'required|string|max:10|unique:business_risk_tiers,tier_code',
            'interest_rate' => 'required|numeric|min:0|max:100',
            'interest_frequency' => 'required|string|in:daily,weekly,monthly,quarterly,annual',
            'credit_limit_multiplier' => 'nullable|numeric|min:0|max:10',
            'criteria' => 'nullable|array',
        ]);

        try {
            $tier = BusinessRiskTier::create([
                'tier_name' => $request->tier_name,
                'tier_code' => strtoupper($request->tier_code),
                'interest_rate' => $request->interest_rate,
                'interest_frequency' => $request->interest_frequency,
                'credit_limit_multiplier' => $request->credit_limit_multiplier ?? 1.0,
                'criteria' => $request->criteria ?? [],
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk tier created successfully',
                'data' => $tier
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create risk tier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update risk tier
     */
    public function updateRiskTier(Request $request, BusinessRiskTier $tier)
    {
        $request->validate([
            'tier_name' => 'string|max:255',
            'tier_code' => 'string|max:10|unique:business_risk_tiers,tier_code,' . $tier->id,
            'interest_rate' => 'numeric|min:0|max:100',
            'interest_frequency' => 'string|in:daily,weekly,monthly,quarterly,annual',
            'credit_limit_multiplier' => 'nullable|numeric|min:0|max:10',
            'criteria' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        try {
            $tier->update($request->only([
                'tier_name',
                'tier_code',
                'interest_rate',
                'interest_frequency',
                'credit_limit_multiplier',
                'criteria',
                'is_active',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Risk tier updated successfully',
                'data' => $tier->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update risk tier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete risk tier
     */
    public function deleteRiskTier(BusinessRiskTier $tier)
    {
        $businessCount = Business::where('risk_tier_id', $tier->id)->count();

        if ($businessCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete risk tier. {$businessCount} businesses are using this tier."
            ], 400);
        }

        try {
            $tier->delete();

            return response()->json([
                'success' => true,
                'message' => 'Risk tier deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete risk tier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update interest rate with frequency configuration
     */
    public function updateInterestRateWithFrequency(Request $request)
    {
        $request->validate([
            'rate_type' => 'required|string|in:base_interest_rate,late_payment_rate,premium_tier_rate',
            'rate' => 'required|numeric|min:0|max:100',
            'frequency' => 'required|string|in:daily,weekly,monthly,quarterly,annual',
            'auto_apply' => 'boolean',
            'apply_day' => 'nullable|integer|min:1|max:31', // For monthly frequency
            'reason' => 'required|string|max:500',
            'effective_date' => 'nullable|date|after_or_equal:today',
        ]);

        DB::beginTransaction();
        try {
            $rateType = $request->rate_type;
            $oldConfig = SystemSetting::getInterestRateConfig($rateType);

            // Update rate configuration
            SystemSetting::setInterestRateConfig(
                $rateType,
                $request->rate,
                $request->frequency,
                $request->auto_apply ?? false,
                $request->apply_day ?? 1
            );

            // Log the change
            InterestRateHistory::logRateChange(
                $rateType,
                $oldConfig['rate'] ?? 0,
                $request->rate,
                $request->reason . " (Frequency: {$request->frequency})",
                $request->effective_date
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Interest rate configuration updated successfully',
                'data' => [
                    'rate_type' => $rateType,
                    'old_config' => $oldConfig,
                    'new_config' => [
                        'rate' => $request->rate,
                        'frequency' => $request->frequency,
                        'auto_apply' => $request->auto_apply ?? false,
                        'apply_day' => $request->apply_day ?? 1,
                    ],
                    'annual_equivalent' => $this->calculateAnnualEquivalent($request->rate, $request->frequency),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update interest rate configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set custom interest rate for specific business
     */
    public function setBusinessInterestRate(Request $request, Business $business)
    {
        $request->validate([
            'rate' => 'required|numeric|min:0|max:100',
            'frequency' => 'required|string|in:daily,weekly,monthly,quarterly,annual',
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $oldRate = $business->custom_interest_rate;
            $oldFrequency = $business->custom_interest_frequency;

            $business->update([
                'custom_interest_rate' => $request->rate,
                'custom_interest_frequency' => $request->frequency,
            ]);

            // Log the change
            InterestRateHistory::logRateChange(
                "business_{$business->id}_custom",
                $oldRate ?? 0,
                $request->rate,
                $request->reason . " (Frequency: {$request->frequency})"
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Custom interest rate set for business',
                'data' => [
                    'business' => $business->fresh(),
                    'old_rate' => $oldRate,
                    'old_frequency' => $oldFrequency,
                    'new_rate' => $request->rate,
                    'new_frequency' => $request->frequency,
                    'annual_equivalent' => $this->calculateAnnualEquivalent($request->rate, $request->frequency),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to set custom interest rate',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get interest rate comparison across frequencies
     */
    public function getInterestRateComparison()
    {
        $frequencies = SystemSetting::getAvailableFrequencies();
        $baseRate = 12; // Example 12% for comparison

        $comparisons = [];
        foreach ($frequencies as $freq => $config) {
            $periodsPerYear = $config['periods_per_year'];
            $ratePerPeriod = $baseRate / $periodsPerYear;
            $annualEquivalent = $ratePerPeriod * $periodsPerYear;

            $comparisons[$freq] = [
                'frequency' => $freq,
                'label' => $config['label'],
                'periods_per_year' => $periodsPerYear,
                'rate_per_period' => round($ratePerPeriod, 4),
                'annual_equivalent' => round($annualEquivalent, 2),
                'example_interest_on_1000' => round(1000 * ($ratePerPeriod / 100), 2),
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Interest rate frequency comparison',
            'data' => [
                'base_rate_example' => $baseRate,
                'comparisons' => $comparisons,
                'note' => 'This shows how the same annual rate applies across different frequencies'
            ]
        ]);
    }

    /**
     * Bulk apply interest for specific frequency
     */
    public function bulkApplyInterestByFrequency(Request $request)
    {
        $request->validate([
            'frequency' => 'required|string|in:daily,weekly,monthly,quarterly,annual',
            'apply_to' => 'required|in:all,overdue,high_utilization,specific_tier',
            'tier_id' => 'nullable|exists:business_risk_tiers,id',
            'force' => 'boolean',
            'reason' => 'required|string|max:500',
        ]);

        $frequency = $request->frequency;
        $query = Business::where('credit_balance', '>', 0)->where('is_active', true);

        // Filter businesses based on criteria
        switch ($request->apply_to) {
            case 'overdue':
                $query->whereHas('purchaseOrders', function($q) {
                    $q->overdue();
                });
                break;
            case 'high_utilization':
                $query->whereRaw('(credit_balance / current_balance) > 0.8');
                break;
            case 'specific_tier':
                if ($request->tier_id) {
                    $query->where('risk_tier_id', $request->tier_id);
                }
                break;
        }

        $businesses = $query->get();
        $processedCount = 0;
        $totalInterest = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($businesses as $business) {
                try {
                    $config = $business->getInterestRateConfig();

                    // Skip if frequency doesn't match or rate is 0
                    if ($config['frequency'] !== $frequency || $config['rate'] <= 0) {
                        continue;
                    }

                    // Check if interest is due (unless forced)
                    if (!$request->force && !$business->isInterestDue()) {
                        continue;
                    }

                    $interestAmount = $business->applyPeriodicInterest($request->reason);

                    if ($interestAmount > 0) {
                        $processedCount++;
                        $totalInterest += $interestAmount;

                        // Update last application date
                        $business->update(['last_interest_applied_at' => now()]);
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'business_id' => $business->id,
                        'business_name' => $business->name,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Bulk {$frequency} interest application completed",
                'data' => [
                    'frequency' => $frequency,
                    'businesses_processed' => $processedCount,
                    'total_interest_applied' => $totalInterest,
                    'businesses_eligible' => $businesses->count(),
                    'errors' => $errors,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Bulk interest application failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate setting value
     */
    private function validateSettingValue($key, $value)
    {
        switch ($key) {
            case 'default_payment_terms_days':
                if (!is_numeric($value) || $value < 1 || $value > 365) {
                    throw new \Exception('Payment terms must be between 1 and 365 days');
                }
                break;
            case 'grace_period_days':
                if (!is_numeric($value) || $value < 0 || $value > 30) {
                    throw new \Exception('Grace period must be between 0 and 30 days');
                }
                break;
            case 'max_credit_limit':
                if (!is_numeric($value) || $value < 1000) {
                    throw new \Exception('Maximum credit limit must be at least 1000');
                }
                break;
            case 'min_payment_amount':
                if (!is_numeric($value) || $value < 1) {
                    throw new \Exception('Minimum payment amount must be at least 1');
                }
                break;
        }
    }

    /**
     * Calculate annual equivalent rate
     */
    private function calculateAnnualEquivalent($rate, $frequency)
    {
        $frequencies = SystemSetting::getAvailableFrequencies();
        $periodsPerYear = $frequencies[$frequency]['periods_per_year'];
        return round($rate * $periodsPerYear, 2);
    }
}
