<?php

namespace App\Services;

use App\Exceptions\FinanceException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Cleaner;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public const SALARIES = 'Salaries';

    private const FIELDS = [
        'category_id', 'amount_k', 'expense_date', 'paid_to_user_id', 'payee_name', 'booking_id',
        'payment_method', 'reference', 'period_start', 'period_end', 'notes', 'receipt_path',
    ];

    /**
     * Record an expense. A salary is the category "Salaries" plus the person paid and the amount (in kobo).
     *
     * @param array{
     *   category_id:int, amount_k:int, expense_date?:string, paid_to_user_id?:?int, payee_name?:?string,
     *   booking_id?:?int, payment_method?:string, reference?:?string,
     *   period_start?:?string, period_end?:?string, notes?:?string, receipt_path?:?string
     * } $input
     *
     * @throws FinanceException
     */
    public function record(array $input, User $by): Expense
    {
        $data = $this->validated($input);

        $expense = DB::transaction(function () use ($data, $by) {
            $this->guardSalaryOverlap($data, null);

            return Expense::create($data + ['status' => 'recorded', 'recorded_by_user_id' => $by->id]);
        });

        AuditLog::record('expense.recorded', $expense, null, Arr::only($expense->toArray(), self::FIELDS), $by);

        return $expense;
    }

    /**
     * Correct a mistake. Only the fields you pass change; the old values go in the audit log.
     *
     * @throws FinanceException
     */
    public function update(Expense|int $expense, array $changes, User $by): Expense
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;

        [$updated, $old] = DB::transaction(function () use ($id, $changes) {
            $current = Expense::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($current->status !== 'recorded') {
                throw new FinanceException('A voided expense cannot be changed.');
            }

            $old = Arr::only($current->toArray(), self::FIELDS);
            $data = $this->validated(array_merge($current->only(self::FIELDS), Arr::only($changes, self::FIELDS)));

            $this->guardSalaryOverlap($data, $current->id);

            $current->update($data);

            return [$current, $old];
        });

        AuditLog::record('expense.updated', $updated, $old, Arr::only($updated->toArray(), self::FIELDS), $by);

        return $updated;
    }

    /** Cancel an expense that should never have been recorded. A voided expense never counts in any total. */
    public function void(Expense|int $expense, User $by, ?string $reason = null): Expense
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;

        $voided = DB::transaction(function () use ($id, $reason) {
            $expense = Expense::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($expense->status === 'void') {
                throw new FinanceException('That expense has already been voided.');
            }

            $note = $reason ? 'Voided: '.$reason : null;

            $expense->update([
                'status' => 'void',
                'notes' => trim(($expense->notes ? $expense->notes."\n" : '').$note) ?: null,
            ]);

            return $expense;
        });

        AuditLog::record('expense.voided', $voided, null, ['reason' => $reason], $by);

        return $voided;
    }

    /**
     * Active cleaners with no salary recorded for that month: the month-end reminder.
     *
     * @return Collection<int, Cleaner>
     */
    public function cleanersWithoutSalary(int $year, int $month): Collection
    {
        $first = Carbon::create($year, $month, 1)->startOfDay();
        $last = $first->copy()->endOfMonth()->startOfDay();
        $category = ExpenseCategory::where('name', self::SALARIES)->first();

        if (! $category) {
            return collect();
        }

        $paidUserIds = Expense::recorded()
            ->where('category_id', $category->id)
            ->whereNotNull('paid_to_user_id')
            ->whereBetween('expense_date', [$first->copy()->subDays(62)->toDateString(), $last->copy()->addDays(62)->toDateString()])
            ->get()
            ->filter(function (Expense $e) use ($first, $last) {
                [$start, $end] = $this->effectivePeriod($e->period_start, $e->period_end, $e->expense_date);

                return $start->lte($last) && $end->gte($first);
            })
            ->pluck('paid_to_user_id')
            ->unique()
            ->all();

        return Cleaner::active()
            ->with('user')
            ->whereNotIn('user_id', $paidUserIds)
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhere('hired_at', '<=', $last->toDateString()))
            ->get();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string, mixed> the cleaned-up values for every field */
    private function validated(array $input): array
    {
        $category = ExpenseCategory::active()->find($input['category_id'] ?? null)
            ?? throw new FinanceException('Please choose a category.');

        $amount = $input['amount_k'] ?? null;

        if (! is_numeric($amount) || (int) $amount != $amount || (int) $amount <= 0) {
            throw new FinanceException('Please enter an amount greater than zero.');
        }

        $date = $this->parseDate($input['expense_date'] ?? today()->toDateString(), 'expense date');

        if ($date->isFuture()) {
            throw new FinanceException('The expense date cannot be in the future.');
        }

        $user = null;

        if (! empty($input['paid_to_user_id'])) {
            $user = User::find($input['paid_to_user_id'])
                ?? throw new FinanceException('We could not find the person who was paid.');
        }

        if ($category->name === self::SALARIES) {
            if (! $user || ! in_array($user->role, ['cleaner', 'admin'], true)) {
                throw new FinanceException('A salary must say which staff member was paid.');
            }
        } elseif ($user && $user->role === 'client') {
            throw new FinanceException('Clients cannot be paid from expenses; record a refund instead.');
        }

        $start = filled($input['period_start'] ?? null) ? $this->parseDate($input['period_start'], 'period') : null;
        $end = filled($input['period_end'] ?? null) ? $this->parseDate($input['period_end'], 'period') : null;

        if (($start === null) !== ($end === null)) {
            throw new FinanceException('Please give both ends of the period, or neither.');
        }

        if ($start && $end->lt($start)) {
            throw new FinanceException('The period cannot end before it starts.');
        }

        $bookingId = $input['booking_id'] ?? null;

        if ($bookingId && ! Booking::whereKey($bookingId)->exists()) {
            throw new FinanceException('We could not find that booking.');
        }

        $method = $input['payment_method'] ?? 'bank_transfer';

        if (! in_array($method, ['cash', 'bank_transfer', 'other'], true)) {
            throw new FinanceException('Please choose how it was paid.');
        }

        return [
            'category_id' => $category->id,
            'amount_k' => (int) $amount,
            'expense_date' => $date,
            'paid_to_user_id' => $user?->id,
            'payee_name' => filled($input['payee_name'] ?? null) ? trim($input['payee_name']) : null,
            'booking_id' => $bookingId ?: null,
            'payment_method' => $method,
            'reference' => filled($input['reference'] ?? null) ? trim($input['reference']) : null,
            'period_start' => $start,
            'period_end' => $end,
            'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : null,
            'receipt_path' => $input['receipt_path'] ?? null,
        ];
    }

    // The same person cannot be paid twice for the same period (a salary typed in twice by mistake)
    private function guardSalaryOverlap(array $data, ?int $ignoreId): void
    {
        $category = ExpenseCategory::find($data['category_id']);

        if (! $category || $category->name !== self::SALARIES || ! $data['paid_to_user_id']) {
            return;
        }

        [$start, $end] = $this->effectivePeriod($data['period_start'], $data['period_end'], $data['expense_date']);

        $clash = Expense::recorded()
            ->where('category_id', $category->id)
            ->where('paid_to_user_id', $data['paid_to_user_id'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereBetween('expense_date', [$start->copy()->subDays(62)->toDateString(), $end->copy()->addDays(62)->toDateString()])
            ->get()
            ->first(function (Expense $e) use ($start, $end) {
                [$s, $en] = $this->effectivePeriod($e->period_start, $e->period_end, $e->expense_date);

                return $s->lte($end) && $en->gte($start);
            });

        if ($clash) {
            throw new FinanceException('A salary for that person already covers part of this period.');
        }
    }

    // A salary with no period given counts as covering the calendar month of its date
    private function effectivePeriod(?Carbon $start, ?Carbon $end, Carbon $date): array
    {
        if ($start && $end) {
            return [$start->copy()->startOfDay(), $end->copy()->startOfDay()];
        }

        return [$date->copy()->startOfMonth()->startOfDay(), $date->copy()->endOfMonth()->startOfDay()];
    }

    private function parseDate(mixed $value, string $label): Carbon
    {
        if (blank($value)) {
            throw new FinanceException("Please choose a valid {$label}.");
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new FinanceException("Please choose a valid {$label}.");
        }
    }
}