<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'booking_id', 'cleaner_id', 'role', 'status', 'work_date', 'start_at', 'end_at',
    'assigned_by_user_id', 'replaces_id', 'replacement_reason', 'responded_at',
])]
class BookingCleaner extends Model
{
    // While an assignment is in one of these statuses, the cleaner "owns" that day.
    // For any other status (declined, needs_replacement, replaced, removed) the day is freed.
    public const ACTIVE_STATUSES = ['offered', 'accepted', 'assigned', 'completed'];

    protected $attributes = [
        'role' => 'member',
        'status' => 'offered',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'active_work_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BookingCleaner $assignment) {
            // copy the date and times from the booking, so callers don't have to
            if (! $assignment->work_date || ! $assignment->start_at || ! $assignment->end_at) {
                $booking = Booking::with('workDay')->findOrFail($assignment->booking_id);

                $assignment->work_date ??= $booking->workDay->work_date;
                $assignment->start_at ??= $booking->start_at;
                $assignment->end_at ??= $booking->end_at;
            }

            // keep the unique (cleaner_id, active_work_date) guard in step with the status
            $assignment->active_work_date = in_array($assignment->status, self::ACTIVE_STATUSES, true)
                ? $assignment->work_date->toDateString()
                : null;
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(Cleaner::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    // the assignment this one replaced
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(BookingCleaner::class, 'replaces_id');
    }

    // the assignment that replaced this one
    public function replacedBy(): HasOne
    {
        return $this->hasOne(BookingCleaner::class, 'replaces_id');
    }

    public function replacementRequests(): HasMany
    {
        return $this->hasMany(ReplacementRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('work_date', $date);
    }
}