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
        Schema::create('purchase_orders', function (Blueprint $table) {
             $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'completed', 'cancelled'])->default('draft');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('items')->nullable(); // Made nullable for draft orders
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->decimal('total_paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'fully_paid'])->default('unpaid');
            $table->decimal('interest_rate', 5, 2)->default(0); // annual interest rate
            $table->date('due_date')->nullable();
            $table->decimal('late_fee_amount', 15, 2)->default(0);
            // Performance indexes
            $table->index(['business_id', 'status']);
            $table->index(['vendor_id', 'status']);
            $table->index('po_number');
            $table->index(['order_date', 'status']);
            $table->index(['net_amount', 'business_id']);
            $table->index(['payment_status', 'outstanding_amount']);
            $table->index(['due_date', 'payment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
