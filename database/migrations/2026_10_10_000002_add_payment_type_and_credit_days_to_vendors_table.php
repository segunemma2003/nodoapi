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
            // Default payment arrangement for this vendor: do we pay instantly,
            // or does the vendor extend us credit_days before payment is due?
            $table->enum('payment_type', ['instant', 'credit'])->default('instant')->after('store_name');
            $table->unsignedSmallInteger('credit_days')->nullable()->after('payment_type');

            $table->index('payment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['payment_type']);
            $table->dropColumn(['payment_type', 'credit_days']);
        });
    }
};
