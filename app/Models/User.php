<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'user_type',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function createdBusinesses()
    {
        return $this->hasMany(Business::class, 'created_by');
    }

    public function approvedPurchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'approved_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmins($query)
    {
        return $query->where('user_type', 'admin');
    }

    public function scopeBusinessUsers($query)
    {
        return $query->where('user_type', 'business');
    }

    public function scopeSuperAdmins($query)
    {
        return $query->where('role', 'super_admin');
    }

    // Helper methods
    public function isAdmin()
    {
        return $this->user_type === 'admin';
    }

    public function isBusiness()
    {
        return $this->user_type === 'business';
    }

    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    public function hasRole($role)
    {
        return $this->role === $role;
    }

    // Sanctum token abilities
    public function createTokenWithAbilities(array $abilities = ['*'])
    {
        $tokenName = $this->isAdmin() ? 'admin-token' : 'business-token';
        return $this->createToken($tokenName, $abilities);
    }
}
