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
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Snapshot of the vendor's payment terms at the time this PO was raised
            // (defaults from the vendor, but can be overridden per PO).
            $table->enum('payment_type', ['instant', 'credit'])->default('instant')->after('vendor_id');
            $table->unsignedSmallInteger('credit_days')->nullable()->after('payment_type');

            $table->index(['payment_type', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['payment_type', 'due_date']);
            $table->dropColumn(['payment_type', 'credit_days']);
        });
    }
};
