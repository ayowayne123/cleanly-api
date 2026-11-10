<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'addon_id', 'quantity', 'unit_price_k'])]
class BookingAddon extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    // price for this line, in kobo
    public function lineTotalK(): int
    {
        return $this->quantity * $this->unit_price_k;
    }
}