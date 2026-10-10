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
        if (Schema::hasTable('businesses')) {
            Schema::table('businesses', function (Blueprint $table) {
                if (!Schema::hasColumn('businesses', 'wallet_balance')) {
                    $table->decimal('wallet_balance', 15, 2)->default(0)->after('treasury_collateral_balance');
                }
                if (!Schema::hasColumn('businesses', 'paystack_customer_code')) {
                    $table->string('paystack_customer_code')->nullable()->after('wallet_balance');
                }
                if (!Schema::hasColumn('businesses', 'virtual_account_number')) {
                    $table->string('virtual_account_number')->nullable()->unique()->after('paystack_customer_code');
                }
                if (!Schema::hasColumn('businesses', 'virtual_account_bank_name')) {
                    $table->string('virtual_account_bank_name')->nullable()->after('virtual_account_number');
                }
                if (!Schema::hasColumn('businesses', 'virtual_account_name')) {
                    $table->string('virtual_account_name')->nullable()->after('virtual_account_bank_name');
                }
                if (!Schema::hasColumn('businesses', 'paystack_dva_id')) {
                    $table->string('paystack_dva_id')->nullable()->after('virtual_account_name');
                }
            });
        }

        // "wallet" is a new balance_type for wallet funding/settlement BalanceTransaction entries
        if (Schema::hasTable('balance_transactions') && Schema::hasColumn('balance_transactions', 'balance_type')) {
            try {
                DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN balance_type ENUM('available', 'current', 'credit', 'treasury_collateral', 'wallet') NOT NULL");
            } catch (\Exception $e) {
                // Column might already have this enum value
            }
        }

        // "wallet_settlement" is a new payment_type for POs paid down from the wallet balance
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'payment_type')) {
            try {
                DB::statement("ALTER TABLE payments MODIFY COLUMN payment_type ENUM('business_payment', 'admin_adjustment', 'system_credit', 'refund', 'late_fee_waiver', 'interest_adjustment', 'wallet_settlement') NOT NULL DEFAULT 'business_payment'");
            } catch (\Exception $e) {
                // Column might already have this enum value
            }
        }

        if (!Schema::hasTable('wallet_fundings')) {
            Schema::create('wallet_fundings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('paystack_reference')->unique();
                $table->decimal('amount', 15, 2);
                $table->string('channel')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_fundings');

        if (Schema::hasTable('payments')) {
            DB::statement("ALTER TABLE payments MODIFY COLUMN payment_type ENUM('business_payment', 'admin_adjustment', 'system_credit', 'refund', 'late_fee_waiver', 'interest_adjustment') NOT NULL DEFAULT 'business_payment'");
        }

        if (Schema::hasTable('balance_transactions')) {
            DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN balance_type ENUM('available', 'current', 'credit', 'treasury_collateral') NOT NULL");
        }

        if (Schema::hasTable('businesses')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn([
                    'wallet_balance',
                    'paystack_customer_code',
                    'virtual_account_number',
                    'virtual_account_bank_name',
                    'virtual_account_name',
                    'paystack_dva_id',
                ]);
            });
        }
    }
};
