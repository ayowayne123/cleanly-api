<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'referral_code', 'referred_by_client_id', 'preferred_contact',
    'paystack_customer_code', 'admin_notes', 'marketing_opt_in',
])]
class Client extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['marketing_opt_in' => 'boolean'];
    }

    protected static function booted(): void
    {
        // every client gets a unique referral code automatically
        static::creating(function (Client $client) {
            $client->referral_code ??= static::generateReferralCode();
        });
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referred_by_client_id');
    }

    public function referredClients(): HasMany
    {
        return $this->hasMany(Client::class, 'referred_by_client_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentAuthorizations(): HasMany
    {
        return $this->hasMany(PaymentAuthorization::class);
    }
}