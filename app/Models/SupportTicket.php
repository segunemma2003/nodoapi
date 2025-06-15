<?php

// app/Models/SupportTicket.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'title',
        'description',
        'status',
        'priority',
        'category',
        'business_id',
        'created_by',
        'assigned_to',
        'resolved_by',
        'resolution',
        'resolved_at',
        'closed_at',
        'first_response_at',
        'last_response_at',
        'tags',
        'attachments',
        'is_internal',
        'response_count',
        'estimated_hours',
        'actual_hours',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'attachments' => 'array',
            'is_internal' => 'boolean',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_response_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
        ];
    }

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function responses()
    {
        return $this->hasMany(SupportTicketResponse::class);
    }

    public function activities()
    {
        return $this->hasMany(SupportTicketActivity::class);
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeOverdue($query)
    {
        // Tickets older than SLA based on priority
        return $query->where(function($q) {
            $q->where('priority', 'urgent')->where('created_at', '<', now()->subHours(2))
              ->orWhere(function($q2) {
                  $q2->where('priority', 'high')->where('created_at', '<', now()->subHours(8));
              })
              ->orWhere(function($q3) {
                  $q3->where('priority', 'medium')->where('created_at', '<', now()->subDays(1));
              })
              ->orWhere(function($q4) {
                  $q4->where('priority', 'low')->where('created_at', '<', now()->subDays(3));
              });
        })->whereNotIn('status', ['resolved', 'closed']);
    }

    // Helper methods
    public function isOpen()
    {
        return $this->status === 'open';
    }

    public function isInProgress()
    {
        return $this->status === 'in_progress';
    }

    public function isResolved()
    {
        return $this->status === 'resolved';
    }

    public function isClosed()
    {
        return $this->status === 'closed';
    }

    public function isOverdue()
    {
        if (in_array($this->status, ['resolved', 'closed'])) {
            return false;
        }

        $slaHours = match($this->priority) {
            'urgent' => 2,
            'high' => 8,
            'medium' => 24,
            'low' => 72,
            default => 24,
        };

        return $this->created_at->addHours($slaHours)->isPast();
    }

    public function getHoursOpen()
    {
        $endTime = $this->closed_at ?? now();
        return $this->created_at->diffInHours($endTime);
    }

    public function getResponseTime()
    {
        if (!$this->first_response_at) {
            return null;
        }

        return $this->created_at->diffInMinutes($this->first_response_at);
    }

    public function getSlaStatus()
    {
        if (in_array($this->status, ['resolved', 'closed'])) {
            return 'met'; // Assume SLA met if resolved/closed
        }

        return $this->isOverdue() ? 'breached' : 'on_track';
    }

    public function getPriorityColor()
    {
        return match($this->priority) {
            'urgent' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'green',
            default => 'gray',
        };
    }

    public function getStatusColor()
    {
        return match($this->status) {
            'open' => 'blue',
            'in_progress' => 'orange',
            'pending_customer' => 'purple',
            'resolved' => 'green',
            'closed' => 'gray',
            default => 'gray',
        };
    }

    // Generate unique ticket number
    public static function generateTicketNumber()
    {
        $prefix = 'ST';
        $year = date('Y');
        $month = date('m');

        $lastTicket = self::where('ticket_number', 'like', $prefix . $year . $month . '%')
                         ->orderBy('ticket_number', 'desc')
                         ->first();

        if ($lastTicket) {
            $lastNumber = intval(substr($lastTicket->ticket_number, -5));
            $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '00001';
        }

        return $prefix . $year . $month . $newNumber;
    }

    // Log activity
    public function logActivity($action, $oldValues = null, $newValues = null, $description = null)
    {
        SupportTicketActivity::create([
            'support_ticket_id' => $this->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description ?? $this->generateActivityDescription($action, $oldValues, $newValues),
        ]);
    }

    private function generateActivityDescription($action, $oldValues, $newValues)
    {
        $user = Auth::user()->name ?? 'System';

        return match($action) {
            'created' => "{$user} created the ticket",
            'assigned' => "{$user} assigned ticket to " . User::find($newValues['assigned_to'])?->name,
            'status_changed' => "{$user} changed status from {$oldValues['status']} to {$newValues['status']}",
            'priority_changed' => "{$user} changed priority from {$oldValues['priority']} to {$newValues['priority']}",
            'resolved' => "{$user} resolved the ticket",
            'closed' => "{$user} closed the ticket",
            'reopened' => "{$user} reopened the ticket",
            'commented' => "{$user} added a comment",
            default => "{$user} updated the ticket",
        };
    }

    // Boot method for model events
    protected static function boot()
    {
        parent::boot();

        // Auto-generate ticket number
        static::creating(function ($ticket) {
            if (!$ticket->ticket_number) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });

        // Log ticket creation
        static::created(function ($ticket) {
            $ticket->logActivity('created');
        });

        // Log status changes
        static::updating(function ($ticket) {
            if ($ticket->isDirty('status')) {
                $oldStatus = $ticket->getOriginal('status');
                $newStatus = $ticket->status;

                $ticket->logActivity('status_changed',
                    ['status' => $oldStatus],
                    ['status' => $newStatus]
                );

                // Set resolved_at timestamp
                if ($newStatus === 'resolved' && !$ticket->resolved_at) {
                    $ticket->resolved_at = now();
                    $ticket->resolved_by = Auth::id();
                }

                // Set closed_at timestamp
                if ($newStatus === 'closed' && !$ticket->closed_at) {
                    $ticket->closed_at = now();
                }
            }

            if ($ticket->isDirty('priority')) {
                $oldPriority = $ticket->getOriginal('priority');
                $newPriority = $ticket->priority;

                $ticket->logActivity('priority_changed',
                    ['priority' => $oldPriority],
                    ['priority' => $newPriority]
                );
            }

            if ($ticket->isDirty('assigned_to')) {
                $oldAssignedTo = $ticket->getOriginal('assigned_to');
                $newAssignedTo = $ticket->assigned_to;

                $ticket->logActivity('assigned',
                    ['assigned_to' => $oldAssignedTo],
                    ['assigned_to' => $newAssignedTo]
                );
            }
        });
    }
}
