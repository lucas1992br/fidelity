<?php

namespace App\Queries;

use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Holders with at least one overdue invoice (US-8.5), including those whose
 * card was cancelled with the invoices kept. Each row is a Customer with the
 * aggregates `overdue_invoices_count`, `overdue_amount`, `oldest_due_date`,
 * `max_days_overdue` and `card_status_id` (current card, or the last one),
 * loaded in a single query and ordered by the longest delay. Shared by the
 * Inadimplentes screen, its exports, the dashboard and the reports.
 *
 * Bound as a scoped instance, so the menu counter runs once per request.
 *
 * @phpstan-type Summary array{holders: int, amount: string}
 */
class DelinquentsQuery
{
    protected ?int $holdersCount = null;

    /**
     * Holders with their overdue aggregates, longest delay first.
     *
     * @return Builder<Customer>
     */
    public function builder(): Builder
    {
        return Customer::query()
            ->joinSub($this->overdueInvoices(), 'overdue', 'overdue.customer_id', '=', 'customers.id')
            ->select(['customers.id', 'customers.name', 'customers.phone'])
            ->addSelect(['overdue.overdue_invoices_count', 'overdue.overdue_amount', 'overdue.oldest_due_date'])
            ->selectRaw('(?::date - overdue.oldest_due_date) as max_days_overdue', [today()->toDateString()])
            ->addSelect(['card_status_id' => Card::query()
                ->select('card_status_id')
                ->whereColumn('cards.customer_id', 'customers.id')
                ->orderByDesc('is_current')
                ->orderByRaw('assigned_at desc nulls last')
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->withCasts([
                'overdue_invoices_count' => 'integer',
                'overdue_amount' => 'decimal:2',
                'oldest_due_date' => 'date',
                'max_days_overdue' => 'integer',
                'card_status_id' => 'integer',
            ])
            ->orderBy('overdue.oldest_due_date')
            ->orderBy('customers.name')
            ->orderBy('customers.id');
    }

    /**
     * The delinquent holders; `$limit` keeps only the longest delays (dashboard).
     *
     * @return Collection<int, Customer>
     */
    public function get(?int $limit = null): Collection
    {
        return $this->builder()->when($limit, fn (Builder $query, int $limit) => $query->limit($limit))->get();
    }

    /**
     * Number of holders and total amount overdue, in one aggregate query.
     *
     * @return Summary
     */
    public function summary(): array
    {
        $row = $this->overdueInvoicesOfHolders()
            ->selectRaw('count(distinct invoices.customer_id) as holders, coalesce(sum(invoices.amount), 0)::numeric(12, 2) as amount')
            ->first();

        $this->holdersCount = (int) $row->holders;

        return ['holders' => $this->holdersCount, 'amount' => (string) $row->amount];
    }

    /**
     * Number of delinquent holders for the menu badge, memoized for the request.
     */
    public function holdersCount(): int
    {
        return $this->holdersCount ??= (int) $this->overdueInvoicesOfHolders()->distinct()->count('invoices.customer_id');
    }

    /**
     * Overdue invoices grouped by holder.
     */
    protected function overdueInvoices(): QueryBuilder
    {
        return Invoice::query()
            ->toBase()
            ->where('invoice_status_id', InvoiceStatus::Overdue->id())
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('count(*) as overdue_invoices_count, sum(amount) as overdue_amount, min(due_date) as oldest_due_date');
    }

    /**
     * Overdue invoices of holders that are not deleted, as the listing shows.
     */
    protected function overdueInvoicesOfHolders(): QueryBuilder
    {
        return Invoice::query()
            ->toBase()
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNull('customers.deleted_at')
            ->where('invoices.invoice_status_id', InvoiceStatus::Overdue->id());
    }
}
