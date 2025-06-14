<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'business_id',
        'vendor_id',
        'total_amount',
        'tax_amount',
        'discount_amount',
        'net_amount',
        'status',
        'order_date',
        'expected_delivery_date',
        'notes',
        'items',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    // Relationships - FIXED to use User model
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    // Generate unique PO number
    public static function generatePoNumber($businessId)
    {
        $prefix = 'PO';
        $year = date('Y');
        $businessCode = str_pad($businessId, 3, '0', STR_PAD_LEFT);

        $lastPo = self::where('business_id', $businessId)
                     ->where('po_number', 'like', $prefix . $year . $businessCode . '%')
                     ->orderBy('po_number', 'desc')
                     ->first();

        if ($lastPo) {
            $lastNumber = intval(substr($lastPo->po_number, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $year . $businessCode . $newNumber;
    }
}
