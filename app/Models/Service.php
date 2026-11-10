<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'is_active', 'is_coming_soon', 'sort_order'])]
class Service extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_coming_soon' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ServiceTask::class)->orderBy('sort_order');
    }

    public function homeSizeTiers(): HasMany
    {
        return $this->hasMany(HomeSizeTier::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // Services a client can actually book right now (live, and not "coming soon")
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_coming_soon', false);
    }
}