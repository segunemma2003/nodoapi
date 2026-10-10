<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('vendors')) {
            Schema::table('vendors', function (Blueprint $table) {
                if (!Schema::hasColumn('vendors', 'account_number')) {
                    $table->string('account_number', 10)->nullable()->after('payment_terms');
                }
                if (!Schema::hasColumn('vendors', 'bank_code')) {
                    $table->string('bank_code', 3)->nullable()->after('account_number');
                }
                if (!Schema::hasColumn('vendors', 'bank_name')) {
                    $table->string('bank_name')->nullable()->after('bank_code');
                }
                if (!Schema::hasColumn('vendors', 'account_holder_name')) {
                    $table->string('account_holder_name')->nullable()->after('bank_name');
                }
                if (!Schema::hasColumn('vendors', 'recipient_code')) {
                    $table->string('recipient_code')->nullable()->after('account_holder_name');
                }

                // Add approval-related fields
                if (!Schema::hasColumn('vendors', 'status')) {
                    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_active');
                }
                if (!Schema::hasColumn('vendors', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->constrained('users')->after('status');
                }
                if (!Schema::hasColumn('vendors', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('vendors', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('approved_at');
                }

                // Add index for faster lookups (only if columns exist)
                if (Schema::hasColumn('vendors', 'account_number') && Schema::hasColumn('vendors', 'bank_code')) {
                    try {
                        $table->index(['account_number', 'bank_code']);
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
                if (Schema::hasColumn('vendors', 'status') && Schema::hasColumn('vendors', 'business_id')) {
                    try {
                        $table->index(['status', 'business_id']);
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
                if (Schema::hasColumn('vendors', 'approved_by') && Schema::hasColumn('vendors', 'approved_at')) {
                    try {
                        $table->index(['approved_by', 'approved_at']);
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
           $table->dropColumn([
                'account_number',
                'bank_code',
                'bank_name',
                'account_holder_name',
                'recipient_code',
                'status',
                'approved_by',
                'approved_at',
                'rejection_reason'
            ]);
        });
    }
};
