<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'message',
        'response_type',
        'attachments',
        'is_solution',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_solution' => 'boolean',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($response) {
            $ticket = $response->ticket;

            // Update response count
            $ticket->increment('response_count');

            // Set first response time
            if (!$ticket->first_response_at && $response->response_type === 'admin_response') {
                $ticket->update(['first_response_at' => now()]);
            }

            // Update last response time
            $ticket->update(['last_response_at' => now()]);

            // Log activity
            $ticket->logActivity('commented');
        });
    }
}
