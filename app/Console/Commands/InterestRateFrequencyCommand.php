<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessRiskTier;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class InterestRateFrequencyCommand extends Command
{
     protected $signature = 'interest:manage
                           {action : Action to perform (status, apply, schedule, config)}
                           {--frequency= : Interest frequency (daily, weekly, monthly, quarterly, annual)}
                           {--business= : Specific business ID}
                           {--rate= : Interest rate to apply}
                           {--force : Force application without checks}
                           {--preview : Preview changes without applying}';

    protected $description = 'Manage interest rates with different frequencies';

    public function handle()
    {
        $action = $this->argument('action');
        $frequency = $this->option('frequency');
        $businessId = $this->option('business');
        $rate = $this->option('rate');
        $force = $this->option('force');
        $preview = $this->option('preview');

        switch ($action) {
            case 'status':
                $this->showInterestStatus($frequency);
                break;
            case 'apply':
                $this->applyInterestByFrequency($frequency, $businessId, $force, $preview);
                break;
            case 'schedule':
                $this->showScheduleStatus();
                break;
            case 'config':
                $this->showFrequencyConfiguration();
                break;
            default:
                $this->error("Unknown action: {$action}");
                return 1;
        }

        return 0;
    }

    private function showInterestStatus($frequency = null)
    {
        $this->info('INTEREST RATE STATUS BY FREQUENCY');
        $this->info('===================================');

        $frequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'annual'];

        if ($frequency) {
            $frequencies = [$frequency];
        }

        foreach ($frequencies as $freq) {
            $this->info("\n{$freq} Interest Rate Businesses:");
            $this->info(str_repeat('-', 40));

            // Businesses with this frequency from risk tiers
            $tierBusinesses = Business::whereHas('riskTier', function($q) use ($freq) {
                $q->where('interest_frequency', $freq);
            })->with('riskTier')->get();

            // Businesses with custom frequency
            $customBusinesses = Business::where('custom_interest_frequency', $freq)->get();

            $allBusinesses = $tierBusinesses->merge($customBusinesses)->unique('id');

            if ($allBusinesses->count() === 0) {
                $this->warn("  No businesses with {$freq} interest rate");
                continue;
            }

            $headers = ['ID', 'Business Name', 'Rate', 'Source', 'Outstanding Debt', 'Last Applied', 'Next Due'];
            $rows = [];

            foreach ($allBusinesses as $business) {
                $config = $business->getInterestRateConfig();
                $nextDue = $business->getNextInterestDate();
                $isDue = $business->isInterestDue();

                $rows[] = [
                    $business->id,
                    $business->name,
                    $config['rate'] . '%',
                    $config['source'],
                    '$' . number_format($business->credit_balance, 2),
                    $business->last_interest_applied_at ?
                        $business->last_interest_applied_at->format('Y-m-d') : 'Never',
                    $nextDue ? $nextDue->format('Y-m-d') . ($isDue ? ' (DUE)' : '') : 'N/A',
                ];
            }

            $this->table($headers, $rows);

            // Summary for this frequency
            $totalDebt = $allBusinesses->sum('credit_balance');
            $businessesDue = $allBusinesses->filter(function($b) { return $b->isInterestDue(); });

            $this->info("  Summary:");
            $this->info("    Total businesses: " . $allBusinesses->count());
            $this->info("    Total outstanding debt: $" . number_format($totalDebt, 2));
            $this->info("    Businesses with interest due: " . $businessesDue->count());
        }
    }

    private function applyInterestByFrequency($frequency, $businessId = null, $force = false, $preview = false)
    {
        if (!$frequency) {
            $this->error('Frequency is required for interest application');
            return;
        }

        $this->info("Applying {$frequency} interest rate...");

        $query = Business::where('credit_balance', '>', 0)->where('is_active', true);

        if ($businessId) {
            $query->where('id', $businessId);
        } else {
            // Filter by frequency
            $query->where(function($q) use ($frequency) {
                $q->where('custom_interest_frequency', $frequency)
                  ->orWhereHas('riskTier', function($tierQ) use ($frequency) {
                      $tierQ->where('interest_frequency', $frequency);
                  });
            });
        }

        $businesses = $query->get();

        if ($businesses->count() === 0) {
            $this->warn("No businesses found with {$frequency} interest rate");
            return;
        }

        $totalInterest = 0;
        $processedCount = 0;
        $skippedCount = 0;

        $this->info("Found {$businesses->count()} businesses with {$frequency} interest rate");

        foreach ($businesses as $business) {
            $config = $business->getInterestRateConfig();

            if ($config['frequency'] !== $frequency) {
                $skippedCount++;
                continue;
            }

            $isDue = $business->isInterestDue();

            if (!$force && !$isDue) {
                $this->warn("  Skipping {$business->name} - Interest not due yet");
                $skippedCount++;
                continue;
            }

            $interestAmount = $business->calculatePeriodInterest($business->credit_balance, 1, $config);

            if ($interestAmount <= 0) {
                $skippedCount++;
                continue;
            }

            if ($preview) {
                $this->info("  [PREVIEW] {$business->name}: Would apply \${$interestAmount} interest");
                $totalInterest += $interestAmount;
                continue;
            }

            try {
                $business->applyPeriodicInterest("Manual {$frequency} interest application");
                $business->update(['last_interest_applied_at' => now()]);

                $processedCount++;
                $totalInterest += $interestAmount;

                $this->info("  ✓ Applied \${$interestAmount} to {$business->name}");

            } catch (\Exception $e) {
                $this->error("  ✗ Failed to apply interest to {$business->name}: " . $e->getMessage());
                $skippedCount++;
            }
        }

        $this->info("\nInterest Application Summary:");
        $this->info("  Frequency: {$frequency}");
        $this->info("  Businesses processed: {$processedCount}");
        $this->info("  Businesses skipped: {$skippedCount}");
        $this->info("  Total interest applied: $" . number_format($totalInterest, 2));

        if ($preview) {
            $this->warn("  This was a preview - no actual changes were made");
        }
    }

    private function showScheduleStatus()
    {
        $this->info('INTEREST RATE SCHEDULE STATUS');
        $this->info('==============================');

        $schedules = [
            'Daily' => ['time' => '00:05', 'frequency' => 'Daily', 'next' => now()->addDay()->setTime(0, 5)],
            'Weekly' => ['time' => 'Monday 00:10', 'frequency' => 'Weekly', 'next' => now()->startOfWeek()->addWeek()->setTime(0, 10)],
            'Monthly' => ['time' => '1st 00:15', 'frequency' => 'Monthly', 'next' => now()->startOfMonth()->addMonth()->setTime(0, 15)],
            'Quarterly' => ['time' => 'Quarter start 00:20', 'frequency' => 'Quarterly', 'next' => now()->startOfQuarter()->addQuarter()->setTime(0, 20)],
            'Annual' => ['time' => 'Jan 1 00:25', 'frequency' => 'Annual', 'next' => now()->startOfYear()->addYear()->setTime(0, 25)],
        ];

        $headers = ['Frequency', 'Schedule Time', 'Next Run', 'Businesses Affected'];
        $rows = [];

        foreach ($schedules as $name => $schedule) {
            $frequency = strtolower($name);
            $businessCount = Business::where(function($q) use ($frequency) {
                $q->where('custom_interest_frequency', $frequency)
                  ->orWhereHas('riskTier', function($tierQ) use ($frequency) {
                      $tierQ->where('interest_frequency', $frequency);
                  });
            })->where('credit_balance', '>', 0)->count();

            $rows[] = [
                $name,
                $schedule['time'],
                $schedule['next']->format('Y-m-d H:i'),
                $businessCount,
            ];
        }

        $this->table($headers, $rows);

        // Show system configuration
        $this->info("\nSystem Configuration:");
        $autoAccrual = SystemSetting::getValue('auto_interest_accrual_enabled', false);
        $this->info("  Auto interest accrual: " . ($autoAccrual ? 'Enabled' : 'Disabled'));
        $this->info("  Calculation method: " . SystemSetting::getValue('interest_calculation_method', 'simple'));
        $this->info("  Grace period: " . SystemSetting::getValue('grace_period_days', 5) . " days");
    }

    private function showFrequencyConfiguration()
    {
        $this->info('INTEREST RATE FREQUENCY CONFIGURATION');
        $this->info('====================================');

        $frequencies = SystemSetting::getAvailableFrequencies();

        $headers = ['Frequency', 'Description', 'Periods/Year', 'Example Rate', 'Annual Equivalent'];
        $rows = [];

        foreach ($frequencies as $freq => $config) {
            $exampleRate = 1.0; // 1% example
            $annualEquivalent = $exampleRate * $config['periods_per_year'];

            $rows[] = [
                ucfirst($freq),
                $config['description'],
                $config['periods_per_year'],
                $exampleRate . '%',
                $annualEquivalent . '%',
            ];
        }

        $this->table($headers, $rows);

        $this->info("\nCurrent System Rates:");
        $baseConfig = SystemSetting::getInterestRateConfig('base_interest_rate');
        $this->info("  Base rate: {$baseConfig['rate']}% {$baseConfig['frequency']}");

        $lateConfig = SystemSetting::getInterestRateConfig('late_payment_rate');
        $this->info("  Late payment rate: {$lateConfig['rate']}% {$lateConfig['frequency']}");

        $this->info("\nRisk Tier Configurations:");
        $tiers = BusinessRiskTier::active()->get();
        foreach ($tiers as $tier) {
            $this->info("  {$tier->tier_name}: {$tier->interest_rate}% {$tier->interest_frequency}");
        }
    }
}
