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
        Schema::create('credit_histories', function (Blueprint $table) {
           $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->decimal('previous_credit_limit', 15, 2);
            $table->decimal('new_credit_limit', 15, 2);
            $table->decimal('credit_score', 5, 2)->nullable();
            $table->enum('reason', ['payment_history', 'admin_adjustment', 'default', 'late_payment', 'business_growth']);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index('updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_histories');
    }
};
