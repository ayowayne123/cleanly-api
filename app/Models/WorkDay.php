<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['work_date', 'is_open', 'opens_at', 'note'])]
class WorkDay extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_open' => 'boolean',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }
}