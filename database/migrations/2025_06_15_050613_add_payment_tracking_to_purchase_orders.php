<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        if (Schema::hasTable('balance_transactions')) {
            if (Schema::hasColumn('balance_transactions', 'transaction_type')) {
                try {
                    DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN transaction_type ENUM('credit', 'debit', 'pending', 'rejected', 'admin_assignment', 'interest_charge', 'treasury_update') NOT NULL");
                } catch (\Exception $e) {
                    // Column might already have this enum
                }
            }
            if (Schema::hasColumn('balance_transactions', 'balance_type')) {
                try {
                    DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN balance_type ENUM('available', 'current', 'credit', 'treasury_collateral') NOT NULL");
                } catch (\Exception $e) {
                    // Column might already have this enum
                }
            }
            if (Schema::hasColumn('balance_transactions', 'reference_type')) {
                try {
                    DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN reference_type ENUM('purchase_order', 'payment', 'admin_assignment', 'admin_adjustment', 'interest_accrual', 'interest_charge', 'late_fee', 'treasury_management', 'withdrawal') NULL");
                } catch (\Exception $e) {
                    // Column might already have this enum
                }
            }
        }

        if (Schema::hasTable('businesses')) {
            Schema::table('businesses', function (Blueprint $table) {
                if (!Schema::hasColumn('businesses', 'risk_tier_id')) {
                    $table->foreignId('risk_tier_id')->nullable()->constrained('business_risk_tiers')->after('created_by');
                }
                if (!Schema::hasColumn('businesses', 'custom_interest_rate')) {
                    $table->decimal('custom_interest_rate', 5, 2)->nullable()->after('risk_tier_id');
                }

                if (Schema::hasColumn('businesses', 'risk_tier_id')) {
                    try {
                        $table->index('risk_tier_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Update existing purchase orders
        if (Schema::hasTable('purchase_orders')) {
            try {
                DB::statement('UPDATE purchase_orders SET outstanding_amount = net_amount WHERE outstanding_amount = 0');
                DB::statement("UPDATE purchase_orders SET payment_status = 'unpaid' WHERE payment_status IS NULL");
            } catch (\Exception $e) {
                // Ignore update errors
            }
        }

        if (Schema::hasTable('businesses')) {
            Schema::table('businesses', function (Blueprint $table) {
                if (!Schema::hasColumn('businesses', 'custom_interest_frequency')) {
                    $table->enum('custom_interest_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annual'])
                          ->nullable()
                          ->after('custom_interest_rate');
                }
                if (!Schema::hasColumn('businesses', 'last_interest_applied_at')) {
                    $table->timestamp('last_interest_applied_at')->nullable()->after('custom_interest_frequency');
                }
            });
        }

        // Add frequency fields to business_risk_tiers table
        if (Schema::hasTable('business_risk_tiers')) {
            Schema::table('business_risk_tiers', function (Blueprint $table) {
                if (!Schema::hasColumn('business_risk_tiers', 'interest_frequency')) {
                    $table->enum('interest_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annual'])
                          ->default('annual')
                          ->after('interest_rate');
                }
            });
        }

        // Add indexes for performance with custom shorter names
        if (Schema::hasTable('businesses')) {
            if (Schema::hasColumn('businesses', 'custom_interest_frequency') && Schema::hasColumn('businesses', 'last_interest_applied_at')) {
                try {
                    Schema::table('businesses', function (Blueprint $table) {
                        $table->index(['custom_interest_frequency', 'last_interest_applied_at'], 'businesses_interest_freq_applied_idx');
                    });
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }

        if (Schema::hasTable('business_risk_tiers')) {
            if (Schema::hasColumn('business_risk_tiers', 'interest_frequency') && Schema::hasColumn('business_risk_tiers', 'is_active')) {
                try {
                    Schema::table('business_risk_tiers', function (Blueprint $table) {
                        $table->index(['interest_frequency', 'is_active'], 'risk_tiers_freq_active_idx');
                    });
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('balance_transactions', function (Blueprint $table) {
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN transaction_type ENUM('credit', 'debit') NOT NULL");
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN balance_type ENUM('available', 'current', 'credit', 'treasury_collateral') NOT NULL");
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['risk_tier_id']);
            $table->dropColumn(['risk_tier_id', 'custom_interest_rate']);
        });

        // Revert purchase order updates
        DB::statement('UPDATE purchase_orders SET outstanding_amount = 0');
        DB::statement("UPDATE purchase_orders SET payment_status = 'unpaid'");

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('businesses_interest_freq_applied_idx');
            $table->dropColumn(['custom_interest_frequency', 'last_interest_applied_at']);
        });

        Schema::table('business_risk_tiers', function (Blueprint $table) {
            $table->dropIndex('risk_tiers_freq_active_idx');
            $table->dropColumn('interest_frequency');
        });
    }
};
