<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscription_id', 'period_start', 'period_end', 'visits_count',
    'subtotal_k', 'discount_k', 'total_k', 'status', 'attempts',
    'last_attempt_at', 'next_retry_at', 'paid_at',
])]
class SubscriptionInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    // Failed invoices whose retry time has arrived
    public function scopeRetryDue(Builder $query): Builder
    {
        return $query->where('status', 'failed')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now());
    }
}