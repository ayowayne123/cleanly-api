<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'reference', 'client_id', 'plan_id', 'service_id', 'address_id',
    'address_snapshot', 'visit_snapshot', 'home_size_tier_id',
    'cleaners_required', 'cleaner_selection', 'est_duration_minutes',
    'start_date', 'ends_at', 'current_period_start', 'current_period_end', 'next_billing_date',
    'visits_per_week', 'weeks_per_period', 'discount_percent',
    'price_per_visit_k', 'period_subtotal_k', 'period_discount_k', 'period_total_k',
    'authorization_id', 'status', 'paused_at', 'cancelled_at', 'cancel_reason',
])]
class Subscription extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'address_snapshot' => 'array',
            'visit_snapshot' => 'array',
            'start_date' => 'date',
            'ends_at' => 'date',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'next_billing_date' => 'date',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The reference needs the row id (SUB-000123), but the column can't be empty on insert.
        // So: a temporary unique value first, then the real reference right after.
        static::creating(function (Subscription $subscription) {
            $subscription->reference ??= 'TMP-'.Str::uuid();
        });

        static::created(function (Subscription $subscription) {
            $subscription->updateQuietly([
                'reference' => sprintf('SUB-%06d', $subscription->id),
            ]);
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function homeSizeTier(): BelongsTo
    {
        return $this->belongsTo(HomeSizeTier::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'authorization_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(SubscriptionSlot::class);
    }

    // The usual crew (every row, including removed cleaners)
    public function crew(): HasMany
    {
        return $this->hasMany(SubscriptionCleaner::class);
    }

    public function activeCrew(): HasMany
    {
        return $this->hasMany(SubscriptionCleaner::class)->where('status', 'active');
    }

    // The one cleaner the client chose, if any (at most one, enforced in code)
    public function preferredCleaner(): HasOne
    {
        return $this->hasOne(SubscriptionCleaner::class)
            ->where('chosen_by', 'client')
            ->where('status', 'active');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // Subscriptions the daily renewal job should bill today
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('next_billing_date', '<=', today());
    }
}