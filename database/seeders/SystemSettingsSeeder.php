<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemSetting;
use App\Models\BusinessRiskTier;
use App\Models\User;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Create system admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@system.com'],
            [
                'name' => 'System Administrator',
                'password' => bcrypt('admin123'),
                'role' => 'super_admin',
                'user_type' => 'admin',
                'is_active' => true,
            ]
        );

        // Enhanced Interest Rate Settings with Frequencies
        $interestRateSettings = [
            // Base interest rate configuration
            [
                'key' => 'base_interest_rate',
                'value' => '12.00',
                'type' => 'percentage',
                'category' => 'interest_rates',
                'description' => 'Base interest rate for standard businesses',
            ],
            [
                'key' => 'base_interest_rate_frequency',
                'value' => 'annual',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Frequency for base interest rate application',
            ],
            [
                'key' => 'base_interest_rate_auto_apply',
                'value' => '0',
                'type' => 'boolean',
                'category' => 'interest_rates',
                'description' => 'Automatically apply base interest rate',
            ],
            [
                'key' => 'base_interest_rate_apply_day',
                'value' => '1',
                'type' => 'integer',
                'category' => 'interest_rates',
                'description' => 'Day of month to apply monthly interest (1-31)',
            ],

            // Late payment rate configuration
            [
                'key' => 'late_payment_rate',
                'value' => '18.00',
                'type' => 'percentage',
                'category' => 'interest_rates',
                'description' => 'Interest rate for late payments',
            ],
            [
                'key' => 'late_payment_rate_frequency',
                'value' => 'monthly',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Frequency for late payment rate application',
            ],
            [
                'key' => 'late_payment_rate_auto_apply',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'interest_rates',
                'description' => 'Automatically apply late payment interest',
            ],

            // Premium tier rate configuration
            [
                'key' => 'premium_tier_rate',
                'value' => '8.00',
                'type' => 'percentage',
                'category' => 'interest_rates',
                'description' => 'Preferential rate for premium tier businesses',
            ],
            [
                'key' => 'premium_tier_rate_frequency',
                'value' => 'annual',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Frequency for premium tier rate application',
            ],

            // High risk tier rate configuration
            [
                'key' => 'high_risk_tier_rate',
                'value' => '2.00',
                'type' => 'percentage',
                'category' => 'interest_rates',
                'description' => 'Higher rate for high-risk businesses (monthly)',
            ],
            [
                'key' => 'high_risk_tier_rate_frequency',
                'value' => 'monthly',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Frequency for high-risk tier rate application',
            ],
            [
                'key' => 'high_risk_tier_rate_auto_apply',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'interest_rates',
                'description' => 'Automatically apply high-risk tier interest',
            ],

            // Daily interest rate example
            [
                'key' => 'daily_interest_rate',
                'value' => '0.033',
                'type' => 'percentage',
                'category' => 'interest_rates',
                'description' => 'Daily interest rate (0.033% = ~12% annual)',
            ],
            [
                'key' => 'daily_interest_rate_frequency',
                'value' => 'daily',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Daily frequency for daily interest rate',
            ],
        ];

        // General system settings
        $generalSettings = [
            [
                'key' => 'interest_calculation_method',
                'value' => 'simple',
                'type' => 'string',
                'category' => 'system',
                'description' => 'Interest calculation method: simple or compound',
            ],
            [
                'key' => 'auto_interest_accrual_enabled',
                'value' => '0',
                'type' => 'boolean',
                'category' => 'system',
                'description' => 'Enable automatic interest accrual based on frequency',
            ],
            [
                'key' => 'grace_period_days',
                'value' => '5',
                'type' => 'integer',
                'category' => 'payment_terms',
                'description' => 'Grace period before interest starts accruing',
            ],
            [
                'key' => 'compound_interest_frequency',
                'value' => 'monthly',
                'type' => 'string',
                'category' => 'interest_rates',
                'description' => 'Compounding frequency for compound interest',
            ],
        ];

        // Processing fee settings
        $feeSettings = [
            [
                'key' => 'processing_fee_rate',
                'value' => '2.50',
                'type' => 'percentage',
                'category' => 'fees',
                'description' => 'Processing fee percentage on loan amounts',
            ],
            [
                'key' => 'late_fee_percentage',
                'value' => '5.00',
                'type' => 'percentage',
                'category' => 'fees',
                'description' => 'Late payment fee as percentage of outstanding amount',
            ],
            [
                'key' => 'max_late_fee_amount',
                'value' => '500.00',
                'type' => 'amount',
                'category' => 'fees',
                'description' => 'Maximum late fee amount in dollars',
            ],
        ];

        // Combine all settings
        $allSettings = array_merge($interestRateSettings, $generalSettings, $feeSettings);

        // Insert settings
        foreach ($allSettings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                array_merge($setting, ['updated_by' => $admin->id])
            );
        }

        // Create Enhanced Risk Tiers with Different Frequencies
        $riskTiers = [
            [
                'tier_name' => 'Premium',
                'tier_code' => 'TIER_1',
                'interest_rate' => 0.67, // 8% annual = 0.67% monthly
                'interest_frequency' => 'monthly',
                'credit_limit_multiplier' => 1.50,
                'criteria' => [
                    'min_payment_score' => 90,
                    'min_business_age_months' => 12,
                    'min_avg_order_value' => 5000,
                    'max_late_payments' => 1,
                ],
                'is_active' => true,
            ],
            [
                'tier_name' => 'Standard',
                'tier_code' => 'TIER_2',
                'interest_rate' => 12.00, // 12% annual
                'interest_frequency' => 'annual',
                'credit_limit_multiplier' => 1.00,
                'criteria' => [
                    'min_payment_score' => 70,
                    'min_business_age_months' => 6,
                    'min_avg_order_value' => 1000,
                    'max_late_payments' => 3,
                ],
                'is_active' => true,
            ],
            [
                'tier_name' => 'High Risk',
                'tier_code' => 'TIER_3',
                'interest_rate' => 2.00, // 2% monthly = 24% annual
                'interest_frequency' => 'monthly',
                'credit_limit_multiplier' => 0.75,
                'criteria' => [
                    'min_payment_score' => 0,
                    'min_business_age_months' => 0,
                    'min_avg_order_value' => 0,
                    'max_late_payments' => 999,
                ],
                'is_active' => true,
            ],
            [
                'tier_name' => 'Daily Interest',
                'tier_code' => 'TIER_DAILY',
                'interest_rate' => 0.033, // 0.033% daily = ~12% annual
                'interest_frequency' => 'daily',
                'credit_limit_multiplier' => 0.80,
                'criteria' => [
                    'min_payment_score' => 0,
                    'min_business_age_months' => 0,
                    'min_avg_order_value' => 0,
                    'max_late_payments' => 999,
                ],
                'is_active' => false, // Disabled by default
            ],
        ];

        foreach ($riskTiers as $tier) {
            BusinessRiskTier::updateOrCreate(
                ['tier_code' => $tier['tier_code']],
                $tier
            );
        }

        echo "Enhanced system settings with interest rate frequencies seeded successfully!\n";
        echo "Available interest frequencies: daily, weekly, monthly, quarterly, annual\n";
        echo "Admin credentials: admin@system.com / admin123\n";
    }
}
