<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'weekday'])]
class SubscriptionSlot extends Model
{
    protected function casts(): array
    {
        return ['weekday' => 'integer'];   // 0 = Sunday ... 6 = Saturday
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}