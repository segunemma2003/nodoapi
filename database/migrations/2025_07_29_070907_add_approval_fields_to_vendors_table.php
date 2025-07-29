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
        Schema::table('vendors', function (Blueprint $table) {
            // Add approval status field
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_active');

            // Add approval tracking fields
            $table->foreignId('approved_by')->nullable()->constrained('users')->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            // Add rejection tracking
            $table->foreignId('rejected_by')->nullable()->constrained('users')->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');

            // Add indexes for performance
            $table->index(['status', 'business_id']);
            $table->index(['approved_by', 'approved_at']);
            $table->index(['rejected_by', 'rejected_at']);
        });
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
