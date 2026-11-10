<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
}