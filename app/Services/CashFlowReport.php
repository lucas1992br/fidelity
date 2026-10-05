<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash flow (US-10.6) on a cash basis: paid financial entries counted on
 * `paid_at`, ignoring cancelled and soft-deleted ones. The opening balance
 * is everything paid before the period; each row carries the inflows,
 * outflows, its balance and the cumulative balance. The optional forecast
 * adds, by `due_date`, the pending entries and the open or overdue invoices.
 * Amounts are summed in the database and returned as decimal strings.
 *
 * @phpstan-type CashFlowRow array{key: string, label: string, income: numeric-string, expense: numeric-string, balance: numeric-string, cumulative: numeric-string, forecast_income: numeric-string|null, forecast_expense: numeric-string|null}
 * @phpstan-type CashFlow array{granularity: string, start: CarbonImmutable, end: CarbonImmutable, opening_balance: numeric-string, rows: list<CashFlowRow>, total_income: numeric-string, total_expense: numeric-string, total_balance: numeric-string, closing_balance: numeric-string, with_forecast: bool}
 */
class CashFlowReport
{
    public const string Daily = 'daily';

    public const string Monthly = 'monthly';

    /**
     * Every day of the given month.
     *
     * @return CashFlow
     */
    public function daily(int $year, int $month, bool $withForecast = false): array
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return $this->build(self::Daily, $start, $start->endOfMonth()->startOfDay(), $withForecast);
    }

    /**
     * The 12 months of the given year.
     *
     * @return CashFlow
     */
    public function monthly(int $year, bool $withForecast = false): array
    {
        $start = CarbonImmutable::create($year, 1, 1);

        return $this->build(self::Monthly, $start, $start->endOfYear()->startOfDay(), $withForecast);
    }

    /**
     * Any period, bucketed by day or by month (dashboard series and the
     * cash flow report); monthly buckets cover whole calendar months.
     *
     * @return CashFlow
     */
    public function between(CarbonImmutable $start, CarbonImmutable $end, string $granularity = self::Monthly, bool $withForecast = false): array
    {
        return $this->build($granularity, $start->startOfDay(), $end->startOfDay(), $withForecast);
    }

    /**
     * @return CashFlow
     */
    protected function build(string $granularity, CarbonImmutable $start, CarbonImmutable $end, bool $withForecast): array
    {
        $realized = $this->realized($granularity, $start, $end);
        $forecast = $withForecast ? $this->forecast($granularity, $start, $end) : [];

        $openingBalance = $this->openingBalance($start);
        $cumulative = $openingBalance;
        $totalIncome = '0.00';
        $totalExpense = '0.00';
        $rows = [];

        foreach ($this->buckets($granularity, $start, $end) as $key => $label) {
            $income = $realized[$key]['income'] ?? '0.00';
            $expense = $realized[$key]['expense'] ?? '0.00';
            $balance = bcsub($income, $expense, 2);
            $cumulative = bcadd($cumulative, $balance, 2);
            $totalIncome = bcadd($totalIncome, $income, 2);
            $totalExpense = bcadd($totalExpense, $expense, 2);

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'income' => $income,
                'expense' => $expense,
                'balance' => $balance,
                'cumulative' => $cumulative,
                'forecast_income' => $withForecast ? ($forecast[$key]['income'] ?? '0.00') : null,
                'forecast_expense' => $withForecast ? ($forecast[$key]['expense'] ?? '0.00') : null,
            ];
        }

        return [
            'granularity' => $granularity,
            'start' => $start,
            'end' => $end,
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'total_balance' => bcsub($totalIncome, $totalExpense, 2),
            'closing_balance' => $cumulative,
            'with_forecast' => $withForecast,
        ];
    }

    /**
     * Paid incomes minus paid expenses before the period.
     *
     * @return numeric-string
     */
    public function openingBalance(CarbonImmutable $start): string
    {
        return $this->decimal($this->paidEntries()
            ->whereDate('paid_at', '<', $start->toDateString())
            ->toBase()
            ->selectRaw(
                'coalesce(sum(case when entry_type_id = ? then amount else -amount end), 0)::numeric(14, 2) as balance',
                [EntryType::Income->id()],
            )
            ->value('balance'));
    }

    /**
     * Paid inflows and outflows per bucket, keyed by bucket.
     *
     * @return array<string, array{income: numeric-string, expense: numeric-string}>
     */
    protected function realized(string $granularity, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $bucket = $this->bucketExpression($granularity, 'paid_at');

        $rows = $this->paidEntries()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->toBase()
            ->selectRaw(
                "{$bucket} as bucket,
                 coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as income,
                 coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as expense",
                [EntryType::Income->id(), EntryType::Expense->id()],
            )
            ->groupByRaw($bucket)
            ->get();

        $realized = [];

        foreach ($rows as $row) {
            $realized[(string) $row->bucket] = ['income' => $this->decimal($row->income), 'expense' => $this->decimal($row->expense)];
        }

        return $realized;
    }

    /**
     * Pending entries and payable invoices per bucket of their due date.
     *
     * @return array<string, array{income: numeric-string, expense: numeric-string}>
     */
    protected function forecast(string $granularity, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $bucket = $this->bucketExpression($granularity, 'due_date');
        $period = [$start->toDateString(), $end->toDateString()];

        $entries = FinancialEntry::query()
            ->where('entry_status_id', EntryStatus::Pending->id())
            ->whereBetween('due_date', $period)
            ->toBase()
            ->selectRaw(
                "{$bucket} as bucket,
                 coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as income,
                 coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as expense",
                [EntryType::Income->id(), EntryType::Expense->id()],
            )
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('bucket');

        $invoices = Invoice::query()
            ->payable()
            ->whereBetween('due_date', $period)
            ->toBase()
            ->selectRaw("{$bucket} as bucket, coalesce(sum(amount), 0)::numeric(14, 2) as income")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('bucket');

        $forecast = [];

        foreach ($entries->keys()->merge($invoices->keys())->unique() as $key) {
            $forecast[(string) $key] = [
                'income' => $this->decimal(bcadd($this->decimal($entries[$key]->income ?? '0'), $this->decimal($invoices[$key]->income ?? '0'), 2)),
                'expense' => $this->decimal(bcadd($this->decimal($entries[$key]->expense ?? '0'), '0', 2)),
            ];
        }

        return $forecast;
    }

    /**
     * Paid, non-deleted entries (cancelled ones never have the paid status).
     *
     * @return Builder<FinancialEntry>
     */
    protected function paidEntries(): Builder
    {
        return FinancialEntry::query()
            ->where('entry_status_id', EntryStatus::Paid->id())
            ->whereNotNull('paid_at');
    }

    /**
     * SQL expression of the bucket key; the column is always one of ours.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    protected function bucketExpression(string $granularity, string $column): string
    {
        return $granularity === self::Monthly
            ? "to_char({$column}, 'YYYY-MM')"
            : "to_char({$column}, 'YYYY-MM-DD')";
    }

    /**
     * Decimal string of an aggregate returned by the database.
     *
     * @return numeric-string
     */
    protected function decimal(mixed $value): string
    {
        $decimal = (string) $value;

        return is_numeric($decimal) ? $decimal : '0.00';
    }

    /**
     * Every bucket of the period in order: key => label ("05/10" or "out/2026").
     *
     * @return array<string, string>
     */
    protected function buckets(string $granularity, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        if ($granularity === self::Monthly) {
            foreach (CarbonPeriod::create($start->startOfMonth(), '1 month', $end) as $month) {
                $buckets[$month->format('Y-m')] = $month->locale('pt_BR')->translatedFormat('M/Y');
            }

            return $buckets;
        }

        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $buckets[$day->format('Y-m-d')] = $day->format('d/m');
        }

        return $buckets;
    }
}
