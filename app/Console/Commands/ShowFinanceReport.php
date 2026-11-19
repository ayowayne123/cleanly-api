<?php

namespace App\Console\Commands;

use App\Exceptions\FinanceException;
use App\Services\FinanceReport;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ShowFinanceReport extends Command
{
    protected $signature = 'finance:report {year? : For example 2026} {month? : 1 to 12}';

    protected $description = 'Show money in, money out and net for a month (this month by default)';

    public function handle(FinanceReport $report): int
    {
        $year = (int) ($this->argument('year') ?: now()->year);
        $month = (int) ($this->argument('month') ?: now()->month);

        try {
            $m = $report->month($year, $month);
        } catch (FinanceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Money report for '.Carbon::create($year, $month, 1)->format('F Y'));

        $this->table(['', 'Amount'], [
            ['Money in (payments)', $this->naira($m['money_in_k'])],
            ['Refunds', $this->naira($m['refunds_k'])],
            ['Net in', $this->naira($m['net_in_k'])],
            ['Money out (expenses)', $this->naira($m['money_out_k'])],
            ['Net', $this->naira($m['net_k'])],
        ]);

        if ($m['expenses_by_category']) {
            $this->table(
                ['Expense category', 'Total'],
                array_map(fn ($r) => [$r['category'], $this->naira($r['total_k'])], $m['expenses_by_category']),
            );
        }

        if ($m['salaries_by_person']) {
            $this->table(
                ['Salaries paid to', 'Total'],
                array_map(fn ($r) => [$r['name'], $this->naira($r['total_k'])], $m['salaries_by_person']),
            );
        }

        return self::SUCCESS;
    }

    private function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }
}