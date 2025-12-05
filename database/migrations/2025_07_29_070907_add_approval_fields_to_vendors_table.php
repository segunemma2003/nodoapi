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
                // Add approval status field
                if (!Schema::hasColumn('vendors', 'status')) {
                    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_active');
                }

                // Add approval tracking fields
                if (!Schema::hasColumn('vendors', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->constrained('users')->after('status');
                }
                if (!Schema::hasColumn('vendors', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }

                // Add rejection tracking
                if (!Schema::hasColumn('vendors', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->constrained('users')->after('approved_at');
                }
                if (!Schema::hasColumn('vendors', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                }
                if (!Schema::hasColumn('vendors', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('rejected_at');
                }

                // Add indexes for performance (only if columns exist)
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
                if (Schema::hasColumn('vendors', 'rejected_by') && Schema::hasColumn('vendors', 'rejected_at')) {
                    try {
                        $table->index(['rejected_by', 'rejected_at']);
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
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropIndex(['status', 'business_id']);
            $table->dropIndex(['approved_by', 'approved_at']);
            $table->dropIndex(['rejected_by', 'rejected_at']);
            $table->dropColumn([
                'status',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason'
            ]);
        });
    }
};
