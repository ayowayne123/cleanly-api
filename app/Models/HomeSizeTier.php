<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'service_id', 'label', 'min_score', 'max_score',
    'recommended_cleaners', 'est_duration_minutes',
])]
class HomeSizeTier extends Model
{
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // Tiers for one service, plus the "all services" tiers (service_id = null).
    // A tier made for that exact service comes first.
    public function scopeForService(Builder $query, Service|int $service): Builder
    {
        $id = $service instanceof Service ? $service->id : $service;

        return $query
            ->where(fn (Builder $q) => $q->where('service_id', $id)->orWhereNull('service_id'))
            ->orderByRaw('service_id is null');
    }

    // The tier whose score range contains this size score
    public function scopeForScore(Builder $query, int $score): Builder
    {
        return $query->where('min_score', '<=', $score)->where('max_score', '>=', $score);
    }
}