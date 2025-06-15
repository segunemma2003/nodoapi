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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('cascade');
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_type', [
                'business_payment',
                'admin_adjustment',
                'system_credit',
                'refund',
                'late_fee_waiver',
                'interest_adjustment'
            ])->default('business_payment');
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('payment_date');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            // Performance indexes
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['purchase_order_id', 'status']);
            $table->index(['payment_reference']);
            $table->index(['status', 'payment_date']);
            $table->index(['payment_type', 'status']);
            $table->index(['confirmed_by', 'confirmed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
