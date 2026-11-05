<?php

namespace App\Reports;

use App\Actions\Cards\ReenrollCustomer;
use App\Actions\Customers\CreateCustomer;
use App\Enums\InvoiceStatus;
use App\Exports\SalesByUserExport;
use App\Models\Invoice;
use App\Models\PlanChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sales per user for commission analysis: every sign-up (Adesão) and
 * re-enrollment (Reingresso) in the period, by the user who made it, with the
 * holder, plan, company, the monthly amount sold and whether the first invoice
 * issued with the sale is already paid (a usual condition for commission).
 * Optionally filtered by one user.
 *
 * @phpstan-type UserTotals array{user: string, sales: int, reenrollments: int, monthly: string, paid_sales: int, paid_monthly: string}
 */
class SalesByUserReport extends Report
{
    public static function key(): string
    {
        return 'vendas-por-atendente';
    }

    public static function title(): string
    {
        return 'Vendas por atendente';
    }

    public static function description(): string
    {
        return 'Adesões e reingressos por atendente, com valor vendido, para comissões.';
    }

    public static function filters(): array
    {
        return [self::FilterPeriod, self::FilterUser];
    }

    /**
     * Sign-ups and re-enrollments of the period, by user then date.
     *
     * @return Builder<PlanChange>
     */
    public function query(): Builder
    {
        return PlanChange::query()
            ->with(['user:id,name', 'toPlan:id,name', 'customer', 'customer.company', 'customer.currentCard', 'customer.lastCard', 'customer.invoices'])
            ->whereIn('reason', [CreateCustomer::Reason, ReenrollCustomer::Reason])
            ->whereBetween('created_at', [$this->from->startOfDay(), $this->until->endOfDay()])
            ->when($this->userId, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->orderBy(fn ($query) => $query->select('name')->from('users')->whereColumn('users.id', 'plan_changes.user_id'))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function rowCount(): int
    {
        return $this->query()->count();
    }

    /**
     * First invoice issued with the sale (the earliest one created from the sale on).
     */
    public static function firstInvoiceOf(PlanChange $sale): ?Invoice
    {
        return $sale->customer->invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->created_at >= $sale->created_at->copy()->startOfSecond())
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->first();
    }

    public static function isFirstInvoicePaid(PlanChange $sale): bool
    {
        return self::firstInvoiceOf($sale)?->hasStatus(InvoiceStatus::Paid) ?? false;
    }

    /**
     * "Adesão" or "Reingresso".
     */
    public static function typeOf(PlanChange $sale): string
    {
        return $sale->reason === ReenrollCustomer::Reason ? 'Reingresso' : 'Adesão';
    }

    /**
     * Sales, re-enrollments and monthly amounts per user, from the rows of the query.
     *
     * @param  Collection<int, PlanChange>  $sales
     * @return list<UserTotals>
     */
    public function totalsByUser(Collection $sales): array
    {
        $sum = fn (Collection $rows): string => $rows->reduce(fn (string $total, PlanChange $sale): string => bcadd($total, $sale->to_price, 2), '0.00');

        return array_values($sales
            ->groupBy('user_id')
            ->map(function (Collection $rows) use ($sum): array {
                $paid = $rows->filter(fn (PlanChange $sale): bool => self::isFirstInvoicePaid($sale));

                return [
                    'user' => $rows->first()->user->name,
                    'sales' => $rows->where('reason', CreateCustomer::Reason)->count(),
                    'reenrollments' => $rows->where('reason', ReenrollCustomer::Reason)->count(),
                    'monthly' => $sum($rows),
                    'paid_sales' => $paid->count(),
                    'paid_monthly' => $sum($paid),
                ];
            })
            ->all());
    }

    public function pdfData(): array
    {
        $sales = $this->query()->get();

        return [
            'sales' => $sales,
            'totals' => $this->totalsByUser($sales),
        ];
    }

    public function export(): SalesByUserExport
    {
        return new SalesByUserExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.sales-by-user';
    }
}
