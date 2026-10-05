<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Queries\DelinquentsQuery;
use App\Settings\ClinicSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard indicators (US-11.1). Amounts are summed in the database and
 * returned as decimal strings; the delinquency rate is a percentage with one
 * decimal place. Soft-deleted holders, dependents and entries never count.
 *
 * @phpstan-type CardsSummary array{active: int, in_stock: int, min_stock: int, low_stock: bool}
 * @phpstan-type MonthTotals array{income: string, expense: string}
 * @phpstan-type Delinquency array{delinquent: int, holders: int, rate: float}
 * @phpstan-type SeriesRow array{key: string, label: string, income: string, expense: string}
 */
class DashboardMetrics
{
    public function __construct(
        protected ClinicSettings $settings,
        protected DelinquentsQuery $delinquents,
        protected CashFlowReport $cashFlow,
    ) {}

    /**
     * Active cards and cards in stock, with the low stock alert (`< min_card_stock`).
     *
     * @return CardsSummary
     */
    public function cards(): array
    {
        $row = Card::query()
            ->toBase()
            ->selectRaw(
                'count(*) filter (where card_status_id = ?) as active, count(*) filter (where card_status_id = ?) as in_stock',
                [CardStatus::Active->id(), CardStatus::InStock->id()],
            )
            ->first();

        $inStock = (int) $row->in_stock;

        return [
            'active' => (int) $row->active,
            'in_stock' => $inStock,
            'min_stock' => $this->settings->min_card_stock,
            'low_stock' => $inStock < $this->settings->min_card_stock,
        ];
    }

    /**
     * Holders with an active card plus their active dependents.
     */
    public function livesServed(): int
    {
        $holders = Customer::query()->whereHas('currentCard', fn (Builder $cards) => $cards->withStatus(CardStatus::Active));

        $dependents = Dependent::query()
            ->where('is_active', true)
            ->whereIn('customer_id', $holders->clone()->select('customers.id'));

        return $holders->count() + $dependents->count();
    }

    /**
     * Paid income and expense with `paid_at` in the current month (cash basis).
     *
     * @return MonthTotals
     */
    public function monthTotals(): array
    {
        $row = FinancialEntry::query()
            ->where('entry_status_id', EntryStatus::Paid->id())
            ->whereBetween('paid_at', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])
            ->toBase()
            ->selectRaw(
                'coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as income,
                 coalesce(sum(amount) filter (where entry_type_id = ?), 0)::numeric(14, 2) as expense',
                [EntryType::Income->id(), EntryType::Expense->id()],
            )
            ->first();

        return ['income' => (string) $row->income, 'expense' => (string) $row->expense];
    }

    /**
     * Amount still to be received: open and overdue invoices.
     */
    public function openAmount(): string
    {
        return $this->invoicesAmount(InvoiceStatus::Open, InvoiceStatus::Overdue);
    }

    /**
     * Amount of the overdue invoices.
     */
    public function overdueAmount(): string
    {
        return $this->invoicesAmount(InvoiceStatus::Overdue);
    }

    /**
     * Holders in force with at least one overdue invoice over the holders in
     * force (active or suspended card); 0 when there are no holders.
     *
     * @return Delinquency
     */
    public function delinquency(): array
    {
        $holders = Customer::query()->whereHas('currentCard');

        $holdersCount = $holders->count();
        $delinquentCount = $holders
            ->whereHas('invoices', fn (Builder $invoices) => $invoices->where('invoice_status_id', InvoiceStatus::Overdue->id()))
            ->count();

        return [
            'delinquent' => $delinquentCount,
            'holders' => $holdersCount,
            'rate' => $holdersCount === 0 ? 0.0 : round($delinquentCount / $holdersCount * 100, 1),
        ];
    }

    /**
     * Paid income and expense per month, from 11 months ago to the current month.
     *
     * @return list<SeriesRow>
     */
    public function monthlySeries(int $months = 12): array
    {
        $end = CarbonImmutable::today()->endOfMonth();
        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

        return array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'income' => $row['income'],
            'expense' => $row['expense'],
        ], $this->cashFlow->between($start, $end, CashFlowReport::Monthly)['rows']);
    }

    /**
     * The most recent payments that were not refunded.
     *
     * @return Collection<int, Payment>
     */
    public function latestPayments(int $limit = 5): Collection
    {
        return Payment::query()
            ->whereNull('refunded_at')
            ->with(['invoice:id,customer_id,reference_month', 'invoice.customer:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('receipt_number')
            ->limit($limit)
            ->get();
    }

    /**
     * Holders with the longest delays (DelinquentsQuery order).
     *
     * @return Collection<int, Customer>
     */
    public function topDelinquents(int $limit = 5): Collection
    {
        return $this->delinquents->get($limit);
    }

    protected function invoicesAmount(InvoiceStatus ...$statuses): string
    {
        return (string) Invoice::query()
            ->whereIn('invoice_status_id', array_map(fn (InvoiceStatus $status): int => $status->id(), $statuses))
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14, 2) as amount')
            ->value('amount');
    }
}
