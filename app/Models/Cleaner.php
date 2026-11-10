<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'photo_path', 'whatsapp_number', 'date_of_birth', 'home_address',
    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    'status', 'weekly_off_day', 'bank_name', 'account_number', 'account_name',
    'avg_rating', 'rating_count', 'jobs_completed', 'hired_at',
])]
// never leak these if a cleaner is ever returned in an API response
#[Hidden([
    'home_address', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone',
    'emergency_contact_relationship', 'bank_name', 'account_number', 'account_name',
])]
class Cleaner extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hired_at' => 'date',
            'weekly_off_day' => 'integer',
            'avg_rating' => 'decimal:2',
            'account_number' => 'encrypted',   // stored encrypted in the database
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'cleaner_zones');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BookingCleaner::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(CleanerTimeOff::class);
    }

    public function subscriptionCrews(): HasMany
    {
        return $this->hasMany(SubscriptionCleaner::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // Carbon's dayOfWeek is 0 = Sunday ... 6 = Saturday, same as weekly_off_day
    public function isOffOn(CarbonInterface $date): bool
    {
        return $this->weekly_off_day !== null
            && $this->weekly_off_day === $date->dayOfWeek;
    }
}