<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login tied to a Business, for teams that need more than one person
 * creating/managing purchase orders on the same business account.
 *
 * Staff carry no balance/credit fields of their own — every business
 * route resolves the acting Business via business() so staff always act
 * on the parent account's data. See App\Support\BusinessContext::current().
 */
class BusinessStaff extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'business_staff';

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
