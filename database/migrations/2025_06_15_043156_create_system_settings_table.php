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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'base_interest_rate', 'late_payment_rate'
            $table->text('value'); // JSON for complex values, string for simple ones
            $table->string('type'); // 'percentage', 'amount', 'boolean', 'json'
            $table->string('category'); // 'interest_rates', 'fees', 'limits', 'general'
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

             $table->index(['category', 'is_active']);
            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
