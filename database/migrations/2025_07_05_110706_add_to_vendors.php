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
             $table->string('account_number', 10)->nullable()->after('payment_terms');
            $table->string('bank_code', 3)->nullable()->after('account_number');
            $table->string('bank_name')->nullable()->after('bank_code');
            $table->string('account_holder_name')->nullable()->after('bank_name');
            $table->string('recipient_code')->nullable()->after('account_holder_name');

            // Add index for faster lookups
            $table->index(['account_number', 'bank_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
           $table->dropColumn([
                'account_number',
                'bank_code',
                'bank_name',
                'account_holder_name',
                'recipient_code'
            ]);
        });
    }
};
