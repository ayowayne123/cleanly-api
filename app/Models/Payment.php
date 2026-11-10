<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'booking_id', 'subscription_invoice_id', 'client_id', 'purpose', 'provider', 'method',
    'reference', 'provider_reference', 'authorization_id', 'amount_k', 'gateway_fee_k',
    'currency', 'status', 'paid_at', 'gateway_response',
])]
#[Hidden(['gateway_response'])]
class Payment extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'provider' => 'paystack',
        'currency' => 'NGN',
    ];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->reference ??= static::generateReference();
        });
    }

    // Our own reference, sent to Paystack when the payment is started
    public static function generateReference(): string
    {
        do {
            $reference = 'PAY-'.strtoupper(Str::random(12));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function subscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'authorization_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    // Money already given back (only refunds that actually went through), in kobo
    public function refundedTotalK(): int
    {
        return (int) $this->refunds()->where('status', 'processed')->sum('amount_k');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }
}