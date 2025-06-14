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
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->enum('transaction_type', ['credit', 'debit']);
            $table->enum('balance_type', ['available', 'current', 'credit', 'treasury_collateral']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference_type')->nullable(); // purchase_order, adjustment, etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description');
            $table->timestamps();

            // Performance indexes
            $table->index(['business_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['balance_type', 'business_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
    }
};
