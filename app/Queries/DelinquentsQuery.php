<?php

namespace App\Queries;

use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Billing\DelinquentRow;
use App\Support\Format;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delinquents (US-8.5): individual holders with at least one overdue invoice,
 * including those whose card was cancelled with the invoices kept, plus one
 * line per company with overdue bills, in place of its employees (who never
 * show up on their own). Each line is a {@see DelinquentRow} with the count
 * of overdue invoices (bills, for a company), the overdue amount, the oldest
 * due date, the longest delay and the holder's card status (current card,
 * or the last one); a company's amount is the fixed amount of each overdue
 * bill. Loaded in a single query, ordered by the longest delay. Shared by
 * the Inadimplentes screen, its exports, the dashboard and the reports.
 *
 * Bound as a scoped instance, so the menu counter runs once per request.
 *
 * @phpstan-type Summary array{holders: int, companies: int, amount: string}
 */
class DelinquentsQuery
{
    protected ?int $count = null;

    /**
     * Holders and companies with their overdue aggregates, longest delay first.
     */
    public function rows(): QueryBuilder
    {
        return DB::query()
            ->fromSub($this->holderRows()->unionAll($this->companyRows()), 'delinquents')
            ->orderBy('oldest_due_date')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * The delinquent lines; `$limit` keeps only the longest delays (dashboard).
     *
     * @return Collection<int, DelinquentRow>
     */
    public function get(?int $limit = null): Collection
    {
        return $this->rows()
            ->when($limit, fn (QueryBuilder $query, int $limit) => $query->limit($limit))
            ->get()
            ->map(fn (object $record): DelinquentRow => DelinquentRow::fromRecord($record));
    }

    /**
     * Number of delinquent holders and companies and the total amount overdue.
     *
     * @return Summary
     */
    public function summary(): array
    {
        $row = DB::query()
            ->fromSub($this->holderRows()->unionAll($this->companyRows()), 'delinquents')
            ->selectRaw('count(*) filter (where kind = ?) as holders, count(*) filter (where kind = ?) as companies', [DelinquentRow::KindHolder, DelinquentRow::KindCompany])
            ->selectRaw('coalesce(sum(overdue_amount), 0)::numeric(12, 2) as amount')
            ->first();

        $this->count = (int) $row->holders + (int) $row->companies;

        return ['holders' => (int) $row->holders, 'companies' => (int) $row->companies, 'amount' => (string) $row->amount];
    }

    /**
     * Summary sentence, e.g. "2 titulares · 1 empresa · R$ 1.379,80 em atraso".
     *
     * @param  Summary  $summary
     */
    public static function summaryLabel(array $summary): string
    {
        return collect([
            $summary['holders'] === 1 ? '1 titular' : "{$summary['holders']} titulares",
            match ($summary['companies']) {
                0 => null,
                1 => '1 empresa',
                default => "{$summary['companies']} empresas",
            },
            Format::money($summary['amount']).' em atraso',
        ])->filter()->implode(' · ');
    }

    /**
     * Number of delinquent lines (holders plus companies) for the menu badge,
     * memoized for the request.
     */
    public function count(): int
    {
        return $this->count ??= DB::query()->fromSub($this->holderRows()->unionAll($this->companyRows()), 'delinquents')->count();
    }

    /**
     * Individual holders (not deleted) with their own overdue invoices.
     */
    protected function holderRows(): QueryBuilder
    {
        $overdue = Invoice::query()
            ->toBase()
            ->whereNull('company_id')
            ->where('invoice_status_id', InvoiceStatus::Overdue->id())
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('count(*) as overdue_invoices_count, sum(amount) as overdue_amount, min(due_date) as oldest_due_date');

        return Customer::query()
            ->toBase()
            ->joinSub($overdue, 'overdue', 'overdue.customer_id', '=', 'customers.id')
            ->selectRaw("'".DelinquentRow::KindHolder."' as kind, customers.id, customers.name, customers.phone")
            ->selectRaw('overdue.overdue_invoices_count::int as overdue_invoices_count, overdue.overdue_amount::numeric(12, 2) as overdue_amount, overdue.oldest_due_date')
            ->selectRaw('(?::date - overdue.oldest_due_date)::int as max_days_overdue', [today()->toDateString()])
            ->selectSub(Card::query()
                ->toBase()
                ->select('card_status_id')
                ->whereColumn('cards.customer_id', 'customers.id')
                ->orderByDesc('is_current')
                ->orderByRaw('assigned_at desc nulls last')
                ->orderByDesc('id')
                ->limit(1), 'card_status_id')
            ->selectRaw('0 as employees')
            ->whereNull('customers.deleted_at');
    }

    /**
     * Companies (not deleted) with overdue bills: one bill per reference month
     * with an overdue employee invoice, worth its frozen fixed amount (plus
     * the employee invoices of months billed before it).
     */
    protected function companyRows(): QueryBuilder
    {
        $overdueEmployeeInvoices = fn (): QueryBuilder => Invoice::query()
            ->toBase()
            ->whereNotNull('company_id')
            ->where('invoice_status_id', InvoiceStatus::Overdue->id());

        $bills = $overdueEmployeeInvoices()
            ->groupBy('company_id', 'reference_month')
            ->select(['company_id', 'reference_month'])
            ->selectRaw('min(due_date) as due_date, sum(amount) as invoices_amount');

        $employees = $overdueEmployeeInvoices()
            ->groupBy('company_id')
            ->select('company_id')
            ->selectRaw('count(distinct customer_id) as employees');

        return Company::query()
            ->toBase()
            ->joinSub($bills, 'bills', 'bills.company_id', '=', 'companies.id')
            ->joinSub($employees, 'late_employees', 'late_employees.company_id', '=', 'companies.id')
            ->leftJoin('company_bills', fn ($join) => $join
                ->on('company_bills.company_id', '=', 'bills.company_id')
                ->on('company_bills.reference_month', '=', 'bills.reference_month'))
            ->whereNull('companies.deleted_at')
            ->groupBy('companies.id', 'late_employees.employees')
            ->selectRaw("'".DelinquentRow::KindCompany."' as kind, companies.id, coalesce(nullif(companies.trade_name, ''), companies.name) as name, companies.phone")
            ->selectRaw('count(*)::int as overdue_invoices_count, sum(coalesce(company_bills.amount, 0) + bills.invoices_amount)::numeric(12, 2) as overdue_amount, min(bills.due_date) as oldest_due_date')
            ->selectRaw('(?::date - min(bills.due_date))::int as max_days_overdue', [today()->toDateString()])
            ->selectRaw('null::bigint as card_status_id, late_employees.employees::int as employees');
    }
}
