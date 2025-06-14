<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Business extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'business_type',
        'registration_number',
        'password',
        'available_balance',
        'current_balance',
        'credit_balance',
        'treasury_collateral_balance',
        'credit_limit',
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'available_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'credit_balance' => 'decimal:2',
            'treasury_collateral_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    // Relationships - FIXED to use User model
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function balanceTransactions()
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helper methods
    public function canCreatePurchaseOrder($amount)
    {
        return $this->available_balance >= $amount;
    }

    public function updateBalance($type, $amount, $operation = 'add')
    {
        $balanceField = $type . '_balance';
        $oldBalance = $this->$balanceField;

        if ($operation === 'add') {
            $this->$balanceField += $amount;
        } else {
            $this->$balanceField -= $amount;
        }

        $this->save();

        // Log transaction
        BalanceTransaction::create([
            'business_id' => $this->id,
            'transaction_type' => $operation === 'add' ? 'credit' : 'debit',
            'balance_type' => $type,
            'amount' => $amount,
            'balance_before' => $oldBalance,
            'balance_after' => $this->$balanceField,
            'description' => "Balance {$operation} - {$type}"
        ]);
    }

    // Sanctum token abilities
    public function createTokenWithAbilities(array $abilities = ['*'])
    {
        return $this->createToken('business-token', $abilities);
    }
}
