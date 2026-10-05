<?php

namespace App\Queries;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtered invoices and their summary (US-8.4), shared by the Mensalidades
 * screen and the invoices report. The period is either a reference month
 * ("2026-10") or a due date interval; the month wins when both are given.
 * Filters that do not parse are ignored, since they come from the URL.
 *
 * @phpstan-type SummaryLine array{amount: string, count: int}
 * @phpstan-type Summary array{total: SummaryLine, paid: SummaryLine, open: SummaryLine, overdue: SummaryLine}
 */
class InvoicesQuery
{
    public function __construct(
        public ?string $month = null,
        public ?string $dueFrom = null,
        public ?string $dueUntil = null,
        public ?InvoiceStatus $status = null,
        public ?int $planId = null,
        public ?string $customer = null,
    ) {}

    /**
     * Invoices matching every filter, without ordering.
     *
     * @return Builder<Invoice>
     */
    public function builder(): Builder
    {
        $month = $this->validMonth();

        return Invoice::query()
            ->when($month, fn (Builder $query, string $month) => $query->forMonth($month))
            ->when(! $month && $this->validDate($this->dueFrom), fn (Builder $query) => $query->whereDate('due_date', '>=', $this->dueFrom))
            ->when(! $month && $this->validDate($this->dueUntil), fn (Builder $query) => $query->whereDate('due_date', '<=', $this->dueUntil))
            ->when($this->status, fn (Builder $query, InvoiceStatus $status) => $query->where('invoice_status_id', $status->id()))
            ->when($this->planId, fn (Builder $query, int $planId) => $query->where('plan_id', $planId))
            ->when(filled($this->customer), fn (Builder $query) => $query->whereHas(
                'customer',
                fn (Builder $customers) => $customers->search((string) $this->customer),
            ));
    }

    /**
     * Listing order: due date, then holder, most recent first.
     *
     * @return Builder<Invoice>
     */
    public function ordered(): Builder
    {
        return $this->builder()
            ->with(['customer:id,name', 'customer.currentCard', 'customer.lastCard', 'activePayment'])
            ->orderByDesc('due_date')
            ->orderBy(Customer::select('name')->whereColumn('customers.id', 'invoices.customer_id'))
            ->orderByDesc('id');
    }

    /**
     * Amount and count of the filtered invoices by status in a single
     * aggregate query; cancelled invoices are left out of the total.
     *
     * @return Summary
     */
    public function summary(): array
    {
        $statusIds = [
            'paid' => InvoiceStatus::Paid->id(),
            'open' => InvoiceStatus::Open->id(),
            'overdue' => InvoiceStatus::Overdue->id(),
        ];

        $columns = ['count(*) filter (where invoice_status_id <> ?) as total_count', 'coalesce(sum(amount) filter (where invoice_status_id <> ?), 0)::numeric(12, 2) as total_amount'];
        $bindings = [InvoiceStatus::Cancelled->id(), InvoiceStatus::Cancelled->id()];

        foreach ($statusIds as $key => $statusId) {
            $columns[] = "count(*) filter (where invoice_status_id = ?) as {$key}_count";
            $columns[] = "coalesce(sum(amount) filter (where invoice_status_id = ?), 0)::numeric(12, 2) as {$key}_amount";
            array_push($bindings, $statusId, $statusId);
        }

        $row = $this->builder()->toBase()->selectRaw(implode(', ', $columns), $bindings)->first();

        $line = fn (string $key): array => [
            'amount' => (string) $row->{"{$key}_amount"},
            'count' => (int) $row->{"{$key}_count"},
        ];

        return [
            'total' => $line('total'),
            'paid' => $line('paid'),
            'open' => $line('open'),
            'overdue' => $line('overdue'),
        ];
    }

    protected function validMonth(): ?string
    {
        return is_string($this->month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? $this->month : null;
    }

    protected function validDate(?string $date): bool
    {
        return is_string($date)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
