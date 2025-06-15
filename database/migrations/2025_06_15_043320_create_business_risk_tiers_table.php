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
        Schema::create('business_risk_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_name'); // 'Premium', 'Standard', 'High Risk'
            $table->string('tier_code')->unique(); // 'TIER_1', 'TIER_2', 'TIER_3'
            $table->decimal('interest_rate', 5, 2)->nullable(); // Interest rate for this tier
            $table->decimal('credit_limit_multiplier', 3, 2)->default(1.00); // 1.5x, 0.8x etc.
            $table->json('criteria')->nullable(); // Criteria for auto-assignment
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'tier_code']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_risk_tiers');
    }
};
