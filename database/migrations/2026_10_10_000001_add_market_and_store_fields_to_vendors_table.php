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
            // The physical market where the vendor is located
            $table->string('market')->nullable()->after('category');
            // Store/business name, used when the vendor is an external supplier
            $table->string('store_name')->nullable()->after('market');

            $table->index('market');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['market']);
            $table->dropColumn(['market', 'store_name']);
        });
    }
};
