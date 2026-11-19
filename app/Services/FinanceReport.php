<?php

namespace App\Services;

use App\Exceptions\FinanceException;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;

// Read-only. Cash basis: payments count by paid_at, refunds by the date they were recorded,
// expenses by expense_date. Voided expenses never count.
class FinanceReport
{
    /**
     * Money in, money out and net for one month.
     *
     * @return array{
     *   period: array, money_in_k:int, refunds_k:int, net_in_k:int, money_out_k:int, net_k:int,
     *   in_by_method: array, expenses_by_category: array, salaries_by_person: array
     * }
     *
     * @throws FinanceException
     */
    public function month(int $year, int $month): array
    {
        [$from, $to] = $this->bounds($year, $month);

        $inByMethod = Payment::successful()
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method, sum(amount_k) as total_k')
            ->groupBy('method')
            ->orderByDesc('total_k')
            ->orderBy('method')
            ->get()
            ->map(fn ($row) => ['method' => $row->method, 'total_k' => (int) $row->total_k])
            ->all();

        $in = array_sum(array_column($inByMethod, 'total_k'));

        $refunds = (int) Refund::where('status', 'processed')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_k');

        $byCategory = Expense::recorded()
            ->forMonth($year, $month)
            ->selectRaw('category_id, sum(amount_k) as total_k')
            ->groupBy('category_id')
            ->orderByDesc('total_k')
            ->get();

        $categoryNames = ExpenseCategory::whereIn('id', $byCategory->pluck('category_id'))->pluck('name', 'id');

        $out = (int) $byCategory->sum('total_k');

        $salaryCategoryId = ExpenseCategory::where('name', ExpenseService::SALARIES)->value('id');

        $salaryRows = $salaryCategoryId
            ? Expense::recorded()
                ->forMonth($year, $month)
                ->where('category_id', $salaryCategoryId)
                ->whereNotNull('paid_to_user_id')
                ->selectRaw('paid_to_user_id, sum(amount_k) as total_k')
                ->groupBy('paid_to_user_id')
                ->orderByDesc('total_k')
                ->get()
            : collect();

        $names = User::withTrashed()->whereIn('id', $salaryRows->pluck('paid_to_user_id'))->pluck('name', 'id');

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'money_in_k' => $in,
            'refunds_k' => $refunds,
            'net_in_k' => $in - $refunds,
            'money_out_k' => $out,
            'net_k' => $in - $refunds - $out,
            'in_by_method' => $inByMethod,
            'expenses_by_category' => $byCategory->map(fn ($row) => [
                'category' => $categoryNames[$row->category_id] ?? 'Unknown',
                'total_k' => (int) $row->total_k,
            ])->all(),
            'salaries_by_person' => $salaryRows->map(fn ($row) => [
                'user_id' => (int) $row->paid_to_user_id,
                'name' => $names[$row->paid_to_user_id] ?? 'Unknown',
                'total_k' => (int) $row->total_k,
            ])->all(),
        ];
    }

    /**
     * The same figures for each of the 12 months of a year, with totals.
     *
     * @return array{year:int, months: array<int, array>, totals: array}
     *
     * @throws FinanceException
     */
    public function year(int $year): array
    {
        $this->bounds($year, 1);

        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $in = Payment::successful()
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('MONTH(paid_at) as m, sum(amount_k) as total_k')
            ->groupBy('m')
            ->pluck('total_k', 'm');

        $refunds = Refund::where('status', 'processed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('MONTH(created_at) as m, sum(amount_k) as total_k')
            ->groupBy('m')
            ->pluck('total_k', 'm');

        $out = Expense::recorded()
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('MONTH(expense_date) as m, sum(amount_k) as total_k')
            ->groupBy('m')
            ->pluck('total_k', 'm');

        $months = [];
        $totals = ['money_in_k' => 0, 'refunds_k' => 0, 'money_out_k' => 0, 'net_k' => 0];

        for ($m = 1; $m <= 12; $m++) {
            $row = [
                'month' => $m,
                'money_in_k' => (int) ($in[$m] ?? 0),
                'refunds_k' => (int) ($refunds[$m] ?? 0),
                'money_out_k' => (int) ($out[$m] ?? 0),
            ];
            $row['net_k'] = $row['money_in_k'] - $row['refunds_k'] - $row['money_out_k'];

            foreach ($totals as $key => $value) {
                $totals[$key] += $row[$key];
            }

            $months[] = $row;
        }

        return ['year' => $year, 'months' => $months, 'totals' => $totals];
    }

    /**
     * One job: what it was worth, what came in, and what it cost.
     * expected margin = booking total minus linked expenses; actual margin = money received minus refunds minus expenses.
     *
     * @return array<string, int|string>
     */
    public function job(Booking|int $booking): array
    {
        $booking = $booking instanceof Booking ? $booking : Booking::findOrFail($booking);

        $paid = (int) $booking->payments()->successful()->sum('amount_k');

        $refunded = (int) Refund::whereIn('payment_id', $booking->payments()->select('id'))
            ->where('status', 'processed')
            ->sum('amount_k');

        $expenses = (int) $booking->expenses()->recorded()->sum('amount_k');

        return [
            'booking' => $booking->reference,
            'total_k' => (int) $booking->total_k,
            'paid_k' => $paid,
            'refunded_k' => $refunded,
            'expenses_k' => $expenses,
            'expected_margin_k' => (int) $booking->total_k - $expenses,
            'actual_margin_k' => $paid - $refunded - $expenses,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} first and last moment of the month */
    private function bounds(int $year, int $month): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new FinanceException('Please choose a valid month.');
        }

        $from = Carbon::create($year, $month, 1)->startOfDay();

        return [$from, $from->copy()->endOfMonth()];
    }
}