<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'client_id', 'provider', 'channel', 'authorization_code', 'signature',
    'last4', 'brand', 'exp_month', 'exp_year', 'bank',
    'reusable', 'is_default', 'status',
])]
// the token must never appear in an API response or a log dump
#[Hidden(['authorization_code', 'signature'])]
class PaymentAuthorization extends Model
{
    protected function casts(): array
    {
        return [
            'authorization_code' => 'encrypted',   // stored encrypted in the database
            'reusable' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'authorization_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'authorization_id');
    }

    // Cards we can still charge
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('reusable', true);
    }

    // A card is good through the last day of its expiry month
    public function isExpired(): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;   // direct debit has no expiry
        }

        return Carbon::createFromDate((int) $this->exp_year, (int) $this->exp_month, 1)
            ->endOfMonth()
            ->isPast();
    }
}