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
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'account_number')) {
            Schema::table('vendors', function (Blueprint $table) {
                // Increase account_number field length to accommodate 10-11 digit account numbers
                try {
                    $table->string('account_number', 15)->nullable()->change();
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
            // Revert account_number field back to 10 characters
            $table->string('account_number', 10)->nullable()->change();
        });
    }
};
