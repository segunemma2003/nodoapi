<?php

namespace App\Jobs;

use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Bus\Dispatchable;

class AccrueInterestByFrequencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $frequency;

    public function __construct($frequency = 'daily')
    {
        $this->frequency = $frequency;
    }

    public function handle()
    {
        $businesses = Business::where('credit_balance', '>', 0)
                             ->where('is_active', true)
                             ->get();

        $processedCount = 0;
        $totalInterest = 0;

        foreach ($businesses as $business) {
            try {
                $config = $business->getInterestRateConfig();

                // Skip if frequency doesn't match or auto-apply is disabled
                if ($config['frequency'] !== $this->frequency || !$config['auto_apply']) {
                    continue;
                }

                // Check if interest is due for this frequency
                if (!$business->isInterestDue()) {
                    continue;
                }

                $interestAmount = $business->applyPeriodicInterest(
                    "Automatic {$this->frequency} interest application"
                );

                if ($interestAmount > 0) {
                    $processedCount++;
                    $totalInterest += $interestAmount;

                    // Update last application date
                    $business->update(['last_interest_applied_at' => now()]);

                    Log::info("Interest applied", [
                        'business_id' => $business->id,
                        'frequency' => $this->frequency,
                        'interest_amount' => $interestAmount,
                        'rate' => $config['rate'],
                        'outstanding_debt' => $business->credit_balance,
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Failed to apply {$this->frequency} interest", [
                    'business_id' => $business->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Interest accrual job completed", [
            'frequency' => $this->frequency,
            'businesses_processed' => $processedCount,
            'total_interest_applied' => $totalInterest,
        ]);
    }
}
