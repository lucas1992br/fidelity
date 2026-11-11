<?php

namespace App\Queries;

use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Payment;
use App\Models\Plan;
use App\Support\Billing\BillingRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Filtered billing lines and their summary (US-8.4), shared by the
 * Mensalidades screen and the invoices report: the invoices of individual
 * holders plus one line per company bill and reference month, in place of
 * the employees' invoices (which never show up on their own). A company
 * bill's amount is its frozen fixed amount (plus the employee invoices of
 * months billed before it), its due date the earliest of its invoices, and
 * its status follows them: overdue, else open, else paid, else cancelled.
 *
 * The period is either a reference month ("2026-10") or a due date interval;
 * the month wins when both are given. The plan filter keeps only holder
 * invoices; the customer filter also finds companies by name or CNPJ.
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
     * Invoices of individual holders matching every filter, without ordering.
     * Employee invoices are left out: they are shown as their company bill.
     *
     * @return Builder<Invoice>
     */
    public function builder(): Builder
    {
        $month = $this->validMonth();

        return Invoice::query()
            ->whereNull('invoices.company_id')
            ->when($month, fn (Builder $query, string $month) => $query->forMonth($month))
            ->when(! $month && $this->validDate($this->dueFrom), fn (Builder $query) => $query->whereDate('invoices.due_date', '>=', $this->dueFrom))
            ->when(! $month && $this->validDate($this->dueUntil), fn (Builder $query) => $query->whereDate('invoices.due_date', '<=', $this->dueUntil))
            ->when($this->status, fn (Builder $query, InvoiceStatus $status) => $query->where('invoices.invoice_status_id', $status->id()))
            ->when($this->planId, fn (Builder $query, int $planId) => $query->where('invoices.plan_id', $planId))
            ->when(filled($this->customer), fn (Builder $query) => $query->whereHas(
                'customer',
                fn (Builder $customers) => $customers->search((string) $this->customer),
            ));
    }

    /**
     * Company bills matching every filter, one row per company and reference
     * month, with the columns of {@see self::rows()}.
     */
    public function companyBills(): QueryBuilder
    {
        $month = $this->validMonth();
        $status = fn (InvoiceStatus $status): int => $status->id();

        $bills = Invoice::query()
            ->toBase()
            ->whereNotNull('company_id')
            ->groupBy('company_id', 'reference_month')
            ->select(['company_id', 'reference_month'])
            ->selectRaw('min(due_date) as due_date, max(paid_at) as paid_at')
            ->selectRaw('count(*) filter (where invoice_status_id <> ?) as employees', [$status(InvoiceStatus::Cancelled)])
            ->selectRaw('coalesce(sum(amount) filter (where invoice_status_id <> ?), 0) as invoices_amount', [$status(InvoiceStatus::Cancelled)])
            ->selectRaw(
                '(case when bool_or(invoice_status_id = ?) then ? when bool_or(invoice_status_id = ?) then ? when bool_or(invoice_status_id = ?) then ? else ? end)::bigint as invoice_status_id',
                [
                    $status(InvoiceStatus::Overdue), $status(InvoiceStatus::Overdue),
                    $status(InvoiceStatus::Open), $status(InvoiceStatus::Open),
                    $status(InvoiceStatus::Paid), $status(InvoiceStatus::Paid),
                    $status(InvoiceStatus::Cancelled),
                ],
            );

        return DB::query()
            ->fromSub($bills, 'bills')
            ->join('companies', 'companies.id', '=', 'bills.company_id')
            ->leftJoin('company_bills', fn ($join) => $join
                ->on('company_bills.company_id', '=', 'bills.company_id')
                ->on('company_bills.reference_month', '=', 'bills.reference_month'))
            ->selectRaw("'".BillingRow::KindCompanyBill."' as kind, bills.company_id as id, null::bigint as customer_id, bills.company_id as company_id")
            ->selectRaw("coalesce(nullif(companies.trade_name, ''), companies.name) as name, null::varchar as card_number, null::varchar as plan_name")
            ->selectRaw('bills.reference_month, bills.due_date')
            ->selectRaw('(case when bills.invoice_status_id = ? then 0 else coalesce(company_bills.amount, 0) + bills.invoices_amount end)::numeric(12, 2) as amount', [$status(InvoiceStatus::Cancelled)])
            ->selectRaw('bills.invoice_status_id, bills.paid_at')
            ->selectSub(Payment::query()
                ->toBase()
                ->select('payments.id')
                ->join('invoices as paid_invoices', 'paid_invoices.id', '=', 'payments.invoice_id')
                ->whereColumn('payments.company_id', 'bills.company_id')
                ->whereColumn('paid_invoices.reference_month', 'bills.reference_month')
                ->whereNull('payments.refunded_at')
                ->orderByDesc('payments.id')
                ->limit(1), 'payment_id')
            ->selectRaw('bills.employees::int as employees')
            ->when($this->planId, fn (QueryBuilder $query) => $query->whereRaw('false'))
            ->when($month, fn (QueryBuilder $query, string $month) => $query->whereDate('bills.reference_month', Invoice::referenceMonth($month)))
            ->when(! $month && $this->validDate($this->dueFrom), fn (QueryBuilder $query) => $query->whereDate('bills.due_date', '>=', $this->dueFrom))
            ->when(! $month && $this->validDate($this->dueUntil), fn (QueryBuilder $query) => $query->whereDate('bills.due_date', '<=', $this->dueUntil))
            ->when($this->status, fn (QueryBuilder $query, InvoiceStatus $status) => $query->where('bills.invoice_status_id', $status->id()))
            ->when(filled($this->customer), fn (QueryBuilder $query) => $query->whereIn(
                'bills.company_id',
                Company::withTrashed()->search((string) $this->customer)->select('id'),
            ));
    }

    /**
     * Holder invoices and company bills as one listing of
     * {@see BillingRow} records: due date, then name, most recent first.
     */
    public function rows(): QueryBuilder
    {
        return DB::query()
            ->fromSub($this->holderInvoiceRows()->unionAll($this->companyBills()), 'billing_rows')
            ->orderByDesc('due_date')
            ->orderBy('name')
            ->orderByDesc('id');
    }

    /**
     * Amount and count of the filtered lines (holder invoices and company
     * bills) by status in a single aggregate query; cancelled lines are left
     * out of the total.
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

        $row = DB::query()
            ->fromSub($this->holderInvoiceRows()->unionAll($this->companyBills()), 'billing_rows')
            ->selectRaw(implode(', ', $columns), $bindings)
            ->first();

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

    /**
     * Holder invoices with the columns of {@see self::rows()}.
     */
    protected function holderInvoiceRows(): QueryBuilder
    {
        return $this->builder()
            ->toBase()
            ->selectRaw("'".BillingRow::KindInvoice."' as kind, invoices.id as id, invoices.customer_id as customer_id, null::bigint as company_id")
            ->selectSub(Customer::withTrashed()->toBase()->select('name')->whereColumn('customers.id', 'invoices.customer_id'), 'name')
            ->selectSub(Card::query()
                ->toBase()
                ->select('number')
                ->whereColumn('cards.customer_id', 'invoices.customer_id')
                ->orderByDesc('is_current')
                ->orderByRaw('assigned_at desc nulls last')
                ->orderByDesc('id')
                ->limit(1), 'card_number')
            ->selectSub(Plan::withTrashed()->toBase()->select('name')->whereColumn('plans.id', 'invoices.plan_id'), 'plan_name')
            ->selectRaw('invoices.reference_month, invoices.due_date, invoices.amount::numeric(12, 2) as amount, invoices.invoice_status_id, invoices.paid_at')
            ->selectSub(DB::table((new InvoicePayment)->getTable())
                ->join('payments', 'payments.id', '=', 'invoice_payment.payment_id')
                ->select('payments.id')
                ->whereColumn('invoice_payment.invoice_id', 'invoices.id')
                ->whereNull('payments.refunded_at')
                ->orderByDesc('payments.id')
                ->limit(1), 'payment_id')
            ->selectRaw('0 as employees');
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
