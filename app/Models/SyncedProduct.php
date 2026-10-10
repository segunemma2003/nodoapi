<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached copy of a newinventory product + its current cost price.
 * Kept up to date by App\Services\InventorySyncService.
 */
class SyncedProduct extends Model
{
    protected $fillable = [
        'inventory_product_id',
        'product_name',
        'sku',
        'category',
        'unit_of_measure',
        'market_id',
        'market_name',
        'cost_price',
        'cost_price_effective_date',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'cost_price_effective_date' => 'date',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('product_name', 'like', "%{$term}%")
              ->orWhere('sku', 'like', "%{$term}%");
        });
    }
}
