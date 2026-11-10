<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'price_k', 'extra_minutes', 'is_active'])]
class RoomType extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}