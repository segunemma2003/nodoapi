<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
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
}
