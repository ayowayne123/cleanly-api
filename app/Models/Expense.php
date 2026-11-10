<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'category_id', 'amount_k', 'expense_date', 'paid_to_user_id', 'payee_name', 'booking_id',
    'payment_method', 'reference', 'period_start', 'period_end', 'notes', 'receipt_path',
    'status', 'recorded_by_user_id',
])]
class Expense extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => 'recorded',
        'payment_method' => 'bank_transfer',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    // who was paid (a salary goes to the cleaner's user)
    public function paidTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_to_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    // Expenses dated within one calendar month
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        $start = Carbon::create($year, $month, 1);

        return $query->whereBetween('expense_date', [
            $start->toDateString(),
            $start->copy()->endOfMonth()->toDateString(),
        ]);
    }

    // Leaves out voided expenses. Use this for every report total.
    public function scopeRecorded(Builder $query): Builder
    {
        return $query->where('status', 'recorded');
    }
}