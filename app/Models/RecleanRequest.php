<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'client_id', 'reported_at', 'areas_missed', 'photo_paths',
    'status', 'resolution_booking_id', 'handled_by_user_id',
])]
class RecleanRequest extends Model
{
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'photo_paths' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // the free return visit that fixes the problem
    public function resolutionBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'resolution_booking_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}