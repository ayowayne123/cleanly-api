<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'reference', 'client_id', 'service_id', 'subscription_id', 'address_id', 'address_snapshot',
    'zone_id', 'work_day_id', 'start_at', 'end_at', 'est_duration_minutes',
    'bedrooms', 'bathrooms', 'is_flat', 'multi_floor', 'has_bq', 'home_size_tier_id',
    'cleaners_recommended', 'cleaners_extra', 'cleaners_required',
    'off_limit_areas', 'special_instructions', 'assignment_mode', 'status', 'payment_status',
    'subtotal_k', 'addons_total_k', 'extra_cleaners_total_k', 'transport_fee_k',
    'service_fee_k', 'discount_k', 'total_k', 'coupon_id', 'source',
    'cancelled_at', 'cancelled_by_user_id', 'cancel_reason', 'completed_at',
])]
class Booking extends Model
{
    use HasFactory, SoftDeletes;

    // Starting values, so the first status-history entry knows the status
    protected $attributes = [
        'status' => 'pending_payment',
        'payment_status' => 'unpaid',
        'assignment_mode' => 'admin',
        'source' => 'web',
    ];

    protected function casts(): array
    {
        return [
            'address_snapshot' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_flat' => 'boolean',
            'multi_floor' => 'boolean',
            'has_bq' => 'boolean',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->reference ??= static::generateReference();
        });

        // first entry in the status history
        static::created(function (Booking $booking) {
            $booking->statusHistory()->create([
                'from_status' => null,
                'to_status' => $booking->status,
            ]);
        });
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'CLN-'.random_int(100000, 999999);
        } while (static::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    // The ONLY way a booking's status should change: it keeps the history in step.
    public function changeStatus(string $to, ?User $by = null, ?string $note = null): void
    {
        DB::transaction(function () use ($to, $by, $note) {
            $from = $this->status;

            if ($from === $to) {
                return;
            }

            $attributes = ['status' => $to];

            if ($to === 'completed') {
                $attributes['completed_at'] = now();
            }

            if ($to === 'cancelled') {
                $attributes['cancelled_at'] = now();
                $attributes['cancelled_by_user_id'] = $by?->id;
            }

            $this->update($attributes);

            $this->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $by?->id,
                'note' => $note,
            ]);
        });
    }

    // Has the full crew been assigned? (declined, replaced and removed rows don't count)
    public function isFullyStaffed(): bool
    {
        return $this->activeAssignments()->count() >= $this->cleaners_required;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(WorkDay::class);
    }

    public function homeSizeTier(): BelongsTo
    {
        return $this->belongsTo(HomeSizeTier::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }

    // Every assignment row, including declined and replaced ones
    public function assignments(): HasMany
    {
        return $this->hasMany(BookingCleaner::class);
    }

    // Only the cleaners actually doing the job
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(BookingCleaner::class)
            ->whereIn('status', BookingCleaner::ACTIVE_STATUSES);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function recleanRequests(): HasMany
    {
        return $this->hasMany(RecleanRequest::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    // The admin queue
    public function scopeAwaitingAssignment(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_assignment')->orderBy('start_at');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_at', '>=', now())->orderBy('start_at');
    }
}