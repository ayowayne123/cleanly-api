<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'type', 'value', 'min_total_k', 'max_uses', 'uses_count',
    'client_id', 'starts_at', 'expires_at', 'is_active',
])]
class Coupon extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // Can this coupon be used on an order of this size (in kobo) right now?
    public function isRedeemable(int $orderTotalK = 0): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->expires_at || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses_count < $this->max_uses)
            && $orderTotalK >= $this->min_total_k;
    }
}