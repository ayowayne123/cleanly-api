<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'value'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    // Setting::get('extra_cleaner_fee_k', 0)
    public static function get(string $name, mixed $default = null): mixed
    {
        return static::where('name', $name)->first()?->value ?? $default;
    }
}