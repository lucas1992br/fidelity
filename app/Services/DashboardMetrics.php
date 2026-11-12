<?php

namespace App\Services;

use App\Actions\Cards\ReenrollCustomer;
use App\Actions\Customers\CreateCustomer;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Company;
use App\Models\CompanyBill;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanChange;
use App\Queries\BoletoInstallments;
use App\Queries\DelinquentsQuery;
use App\Settings\ClinicSettings;
use App\Support\Billing\DelinquentRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard indicators (US-11.1). Amounts are summed in the database and
 * returned as decimal strings; the delinquency rate is a percentage with one
 * decimal place. Soft-deleted holders, dependents and entries never count.
 *
 * Month indicators take the month to show (the current month when none is
 * given); cards, lives, delinquency and open amounts are always the current
 * position, since their past states are not kept.
 *
 * @phpstan-type CardsSummary array{active: int, in_stock: int, min_stock: int, low_stock: bool}
 * @phpstan-type MonthTotals array{income: string, expense: string}
 * @phpstan-type MonthSales array{sales: int, reenrollments: int}
 * @phpstan-type MonthBilling array{billed: string, received: string}
 * @phpstan-type Delinquency array{delinquent: int, holders: int, rate: float} `holders` counts the payers: individual holders plus companies.
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
     * Paid income and expense with `paid_at` in the month (cash basis).
     *
     * @return MonthTotals
     */
    public function monthTotals(?CarbonImmutable $month = null): array
    {
        $month = self::monthOrCurrent($month);

        $row = FinancialEntry::query()
            ->where('entry_status_id', EntryStatus::Paid->id())
            ->whereBetween('paid_at', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
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
     * Sign-ups and re-enrollments made in the month.
     *
     * @return MonthSales
     */
    public function monthSales(?CarbonImmutable $month = null): array
    {
        $month = self::monthOrCurrent($month);

        $row = PlanChange::query()
            ->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->toBase()
            ->selectRaw(
                'count(*) filter (where reason = ?) as sales, count(*) filter (where reason = ?) as reenrollments',
                [CreateCustomer::Reason, ReenrollCustomer::Reason],
            )
            ->first();

        return ['sales' => (int) $row->sales, 'reenrollments' => (int) $row->reenrollments];
    }

    /**
     * Amount billed for the reference month (holder invoices plus the fixed
     * amount of the company bills, cancelled ones left out) and how much of it
     * is already paid.
     *
     * @return MonthBilling
     */
    public function monthBilling(?CarbonImmutable $month = null): array
    {
        $month = self::monthOrCurrent($month);
        $sum = fn (InvoiceStatus ...$statuses): string => (string) Invoice::query()
            ->forMonth($month)
            ->whereIn('invoice_status_id', array_map(fn (InvoiceStatus $status): int => $status->id(), $statuses))
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14, 2) as amount')
            ->value('amount');
        $bills = fn (Builder $query): string => (string) $query
            ->whereDate('reference_month', $month->startOfMonth()->toDateString())
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14, 2) as amount')
            ->value('amount');

        $billedBills = $bills(CompanyBill::query()->withInvoiceStatus(InvoiceStatus::Open, InvoiceStatus::Overdue, InvoiceStatus::Paid));
        $paidBills = $bills(CompanyBill::query()
            ->withInvoiceStatus(InvoiceStatus::Paid)
            ->whereNot(fn (Builder $query) => $query->withInvoiceStatus(InvoiceStatus::Open, InvoiceStatus::Overdue)));

        return [
            'billed' => bcadd($sum(InvoiceStatus::Open, InvoiceStatus::Overdue, InvoiceStatus::Paid), $billedBills, 2),
            'received' => bcadd($sum(InvoiceStatus::Paid), $paidBills, 2),
        ];
    }

    /**
     * Amount still to be received: open and overdue invoices and pending
     * boleto installments.
     */
    public function openAmount(): string
    {
        return bcadd(
            $this->invoicesAmount(InvoiceStatus::Open, InvoiceStatus::Overdue),
            BoletoInstallments::amountOf(BoletoInstallments::pending()),
            2,
        );
    }

    /**
     * Amount of the overdue invoices and late boleto installments.
     */
    public function overdueAmount(): string
    {
        return bcadd(
            $this->invoicesAmount(InvoiceStatus::Overdue),
            BoletoInstallments::amountOf(BoletoInstallments::dueBefore(CarbonImmutable::today())),
            2,
        );
    }

    /**
     * Payers in force that are late over the payers in force, counting each
     * individual holder (active or suspended card) and each company with an
     * employee in force once, as the Inadimplentes screen does: a holder is
     * late with an overdue invoice or late boleto installment of their own,
     * a company with an overdue bill or late boleto installment of its bill
     * payments. 0 when there are no payers.
     *
     * @return Delinquency
     */
    public function delinquency(): array
    {
        $today = CarbonImmutable::today();
        $holders = Customer::query()->whereHas('currentCard')->whereNull('company_id');
        $companies = Company::query()->whereHas('employees', fn (Builder $employees) => $employees->whereHas('currentCard'));

        $payersCount = $holders->clone()->count() + $companies->clone()->count();

        $lateHolders = $holders
            ->where(fn (Builder $query) => $query
                ->whereHas('invoices', fn (Builder $invoices) => $invoices->whereNull('company_id')->where('invoice_status_id', InvoiceStatus::Overdue->id()))
                ->orWhereIn('customers.id', BoletoInstallments::dueBefore($today)
                    ->whereNull('payments.company_id')
                    ->join('invoices as paid', 'paid.id', '=', 'payments.invoice_id')
                    ->select('paid.customer_id')))
            ->count();

        $lateCompanies = $companies
            ->where(fn (Builder $query) => $query
                ->whereHas('invoices', fn (Builder $invoices) => $invoices->where('invoice_status_id', InvoiceStatus::Overdue->id()))
                ->orWhereIn('companies.id', BoletoInstallments::dueBefore($today)->whereNotNull('payments.company_id')->select('payments.company_id')))
            ->count();

        $delinquentCount = $lateHolders + $lateCompanies;

        return [
            'delinquent' => $delinquentCount,
            'holders' => $payersCount,
            'rate' => $payersCount === 0 ? 0.0 : round($delinquentCount / $payersCount * 100, 1),
        ];
    }

    /**
     * Paid income and expense per month, from 11 months before the given
     * month (the current one when none is given) to that month.
     *
     * @return list<SeriesRow>
     */
    public function monthlySeries(int $months = 12, ?CarbonImmutable $until = null): array
    {
        $end = self::monthOrCurrent($until)->endOfMonth();
        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

        return array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'income' => $row['income'],
            'expense' => $row['expense'],
        ], $this->cashFlow->between($start, $end, CashFlowReport::Monthly)['rows']);
    }

    /**
     * The most recent payments that were not refunded, made in the given
     * month when one is given.
     *
     * @return Collection<int, Payment>
     */
    public function latestPayments(int $limit = 5, ?CarbonImmutable $month = null): Collection
    {
        return Payment::query()
            ->whereNull('refunded_at')
            ->when($month, fn (Builder $query, CarbonImmutable $month) => $query->whereBetween('paid_at', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]))
            ->with(['invoice:id,customer_id,reference_month', 'invoice.customer:id,name', 'company:id,name,trade_name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('receipt_number')
            ->limit($limit)
            ->get();
    }

    /**
     * Holders and companies with the longest delays (DelinquentsQuery order).
     *
     * @return Collection<int, DelinquentRow>
     */
    public function topDelinquents(int $limit = 5): Collection
    {
        return $this->delinquents->get($limit);
    }

    /**
     * Invoices in the given statuses plus the fixed amount of the company
     * bills in them (employee invoices are zero).
     */
    protected function invoicesAmount(InvoiceStatus ...$statuses): string
    {
        $invoices = (string) Invoice::query()
            ->whereIn('invoice_status_id', array_map(fn (InvoiceStatus $status): int => $status->id(), $statuses))
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14, 2) as amount')
            ->value('amount');

        return bcadd($invoices, CompanyBill::amountWithInvoiceStatus(...$statuses), 2);
    }

    protected static function monthOrCurrent(?CarbonImmutable $month): CarbonImmutable
    {
        return ($month ?? CarbonImmutable::today())->startOfMonth();
    }
}
