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
        Schema::create('interest_rate_histories', function (Blueprint $table) {
             $table->id();
            $table->string('rate_type'); // 'base_rate', 'tier_1', 'business_123', etc.
            $table->decimal('previous_rate', 5, 2);
            $table->decimal('new_rate', 5, 2);
            $table->text('reason')->nullable();
            $table->date('effective_date');
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            $table->index(['rate_type', 'effective_date']);
            $table->index('changed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_rate_histories');
    }
};
