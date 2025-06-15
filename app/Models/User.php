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

    public function supportTicketsCreated()
{
    return $this->hasMany(SupportTicket::class, 'created_by');
}

public function assignedTickets()
{
    return $this->hasMany(SupportTicket::class, 'assigned_to');
}

public function resolvedTickets()
{
    return $this->hasMany(SupportTicket::class, 'resolved_by');
}

public function supportTicketResponses()
{
    return $this->hasMany(SupportTicketResponse::class);
}

public function supportTicketActivities()
{
    return $this->hasMany(SupportTicketActivity::class);
}

// Get admin's support performance metrics
public function getSupportPerformanceMetrics($days = 30)
{
    $startDate = now()->subDays($days);

    $assignedTickets = $this->assignedTickets()
        ->where('created_at', '>=', $startDate)
        ->get();

    $resolvedTickets = $assignedTickets->where('status', 'resolved');

    return [
        'assigned_tickets' => $assignedTickets->count(),
        'resolved_tickets' => $resolvedTickets->count(),
        'resolution_rate' => $assignedTickets->count() > 0 ?
            round(($resolvedTickets->count() / $assignedTickets->count()) * 100, 2) : 0,
        'avg_resolution_time_hours' => $resolvedTickets->isEmpty() ? null :
            round($resolvedTickets->avg(function($ticket) {
                return $ticket->created_at->diffInHours($ticket->resolved_at);
            }), 2),
        'avg_first_response_time_minutes' => $this->getAverageFirstResponseTime($startDate),
        'overdue_tickets' => $assignedTickets->filter(function($ticket) {
            return $ticket->isOverdue();
        })->count(),
    ];
}

private function getAverageFirstResponseTime($startDate)
{
    $responses = $this->supportTicketResponses()
        ->where('response_type', 'admin_response')
        ->where('created_at', '>=', $startDate)
        ->with('ticket')
        ->get();

    if ($responses->isEmpty()) {
        return null;
    }

    $totalMinutes = $responses->sum(function($response) {
        return $response->ticket->created_at->diffInMinutes($response->created_at);
    });

    return round($totalMinutes / $responses->count(), 2);
}
}
