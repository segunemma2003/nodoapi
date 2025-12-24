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
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->decimal('price', 15, 2)->default(0);
                $table->decimal('cost_price', 15, 2)->nullable();
                $table->integer('quantity')->default(0);
                $table->integer('min_stock_level')->default(0);
                $table->string('category')->nullable();
                $table->string('unit')->nullable(); // e.g., 'piece', 'kg', 'liter'
                $table->string('image_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('attributes')->nullable(); // Additional product attributes
                $table->timestamps();

                // Indexes for performance
                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'category']);
                $table->index('sku');
                $table->index('barcode');
                $table->index(['business_id', 'name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

