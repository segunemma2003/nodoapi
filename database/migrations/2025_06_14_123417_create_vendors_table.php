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
        Schema::create('vendors', function (Blueprint $table) {
           $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('vendor_code')->unique();
            $table->string('category')->nullable();
            $table->json('payment_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->timestamps();

            // Performance indexes
            $table->index(['business_id', 'is_active']);
            $table->index('vendor_code');
            $table->index(['email', 'business_id']);
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
