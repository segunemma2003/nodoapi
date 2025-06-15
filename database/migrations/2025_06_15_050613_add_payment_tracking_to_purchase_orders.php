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
        Schema::table('balance_transactions', function (Blueprint $table) {
            // Update transaction_type enum to include new types
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN transaction_type ENUM('credit', 'debit', 'pending', 'rejected', 'admin_assignment', 'interest_charge', 'treasury_update') NOT NULL");

            // Update balance_type enum for new balance types
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN balance_type ENUM('available', 'current', 'credit', 'treasury_collateral') NOT NULL");

            // Update reference_type enum
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN reference_type ENUM('purchase_order', 'payment', 'admin_assignment', 'admin_adjustment', 'interest_accrual', 'interest_charge', 'late_fee', 'treasury_management', 'withdrawal') NULL");
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('risk_tier_id')->nullable()->constrained('business_risk_tiers')->after('created_by');
            $table->decimal('custom_interest_rate', 5, 2)->nullable()->after('risk_tier_id');

            $table->index('risk_tier_id');
        });

        // Update existing purchase orders
        DB::statement('UPDATE purchase_orders SET outstanding_amount = net_amount WHERE outstanding_amount = 0');
        DB::statement("UPDATE purchase_orders SET payment_status = 'unpaid' WHERE payment_status IS NULL");

        Schema::table('businesses', function (Blueprint $table) {
            $table->enum('custom_interest_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annual'])
                  ->nullable()
                  ->after('custom_interest_rate');
            $table->timestamp('last_interest_applied_at')->nullable()->after('custom_interest_frequency');
        });

        // Add frequency fields to business_risk_tiers table
        Schema::table('business_risk_tiers', function (Blueprint $table) {
            $table->enum('interest_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annual'])
                  ->default('annual')
                  ->after('interest_rate');
        });

        // Add indexes for performance with custom shorter names
        Schema::table('businesses', function (Blueprint $table) {
            $table->index(['custom_interest_frequency', 'last_interest_applied_at'], 'businesses_interest_freq_applied_idx');
        });

        Schema::table('business_risk_tiers', function (Blueprint $table) {
            $table->index(['interest_frequency', 'is_active'], 'risk_tiers_freq_active_idx');
        });
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
