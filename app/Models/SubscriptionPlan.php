<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'visits_per_week', 'weeks_per_period', 'discount_percent', 'is_active'])]
class SubscriptionPlan extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}