<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'cleaner_id', 'start_date', 'end_date', 'type', 'reason',
    'status', 'approved_by_user_id',
])]
class CleanerTimeOff extends Model
{
    protected $table = 'cleaner_time_off';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(Cleaner::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    // Periods that include this date (both ends count)
    public function scopeCovering(Builder $query, $date): Builder
    {
        $day = Carbon::parse($date)->toDateString();

        return $query->where('start_date', '<=', $day)->where('end_date', '>=', $day);
    }
}