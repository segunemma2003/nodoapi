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
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'bank_code')) {
            Schema::table('vendors', function (Blueprint $table) {
                // Modify bank_code field to allow up to 10 characters for fintech bank codes
                try {
                    $table->string('bank_code', 10)->nullable()->change();
                } catch (\Exception $e) {
                    // Column might already have this length
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Revert bank_code field back to 3 characters
            $table->string('bank_code', 3)->nullable()->change();
        });
    }
};
