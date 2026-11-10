<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_cleaner_id', 'booking_id', 'subscription_id', 'reason', 'status',
    'replacement_booking_cleaner_id', 'client_notified_at', 'replacement_notified_at',
    'resolved_by_user_id', 'resolved_at',
])]
class ReplacementRequest extends Model
{
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'client_notified_at' => 'datetime',
            'replacement_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // the assignment that can't go ahead
    public function bookingCleaner(): BelongsTo
    {
        return $this->belongsTo(BookingCleaner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // the assignment that took over
    public function replacementAssignment(): BelongsTo
    {
        return $this->belongsTo(BookingCleaner::class, 'replacement_booking_cleaner_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    // the admin "Needs replacement" queue
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}