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
        // Cached copy of newinventory's product catalog + cost prices, refreshed
        // periodically by App\Services\InventorySyncService. Purchase order items
        // are validated against cost_price so a PO can never pay a vendor more
        // than what the invoicing system says the product should cost.
        Schema::create('synced_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id')->unique();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('category')->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('market_name')->nullable();
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->date('cost_price_effective_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('product_name');
            $table->index('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_products');
    }
};
