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

    private function calculateAnnualEquivalent($rate, $frequency)
    {
        $frequencies = SystemSetting::getAvailableFrequencies();
        $periodsPerYear = $frequencies[$frequency]['periods_per_year'];
        return round($rate * $periodsPerYear, 2);
    }
}
