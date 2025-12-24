<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'sku',
        'barcode',
        'price',
        'cost_price',
        'quantity',
        'min_stock_level',
        'category',
        'unit',
        'image_url',
        'is_active',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'quantity' => 'integer',
            'min_stock_level' => 'integer',
            'is_active' => 'boolean',
            'attributes' => 'array',
        ];
    }

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity', '<=', 'min_stock_level');
    }

    public function scopeInCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    // Helper methods
    public function isLowStock()
    {
        return $this->quantity <= $this->min_stock_level;
    }

    public function getStockStatus()
    {
        if ($this->quantity <= 0) {
            return 'out_of_stock';
        } elseif ($this->isLowStock()) {
            return 'low_stock';
        }
        return 'in_stock';
    }

    public function getProfitMargin()
    {
        if (!$this->cost_price || $this->cost_price <= 0) {
            return null;
        }
        return round((($this->price - $this->cost_price) / $this->cost_price) * 100, 2);
    }
}

