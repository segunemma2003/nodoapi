<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InterestRateHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate_type',
        'previous_rate',
        'new_rate',
        'reason',
        'effective_date',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'previous_rate' => 'decimal:2',
            'new_rate' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    // Relationships
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // Helper methods
    public static function logRateChange($rateType, $previousRate, $newRate, $reason = null, $effectiveDate = null)
    {
        return self::create([
            'rate_type' => $rateType,
            'previous_rate' => $previousRate,
            'new_rate' => $newRate,
            'reason' => $reason,
            'effective_date' => $effectiveDate ?? now()->toDateString(),
            'changed_by' => Auth::id(),
        ]);
    }

    public static function getRateHistory($rateType, $limit = 10)
    {
        return self::where('rate_type', $rateType)
                  ->with('changedBy')
                  ->orderBy('created_at', 'desc')
                  ->limit($limit)
                  ->get();
    }

    public function getChangePercentage()
    {
        if ($this->previous_rate == 0) return 0;

        return (($this->new_rate - $this->previous_rate) / $this->previous_rate) * 100;
    }

    public function getChangeType()
    {
        if ($this->new_rate > $this->previous_rate) {
            return 'increase';
        } elseif ($this->new_rate < $this->previous_rate) {
            return 'decrease';
        }
        return 'no_change';
    }
}
