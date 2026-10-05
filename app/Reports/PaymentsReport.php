<?php

namespace App\Reports;

use App\Enums\PaymentMethod;
use App\Exports\PaymentsExport;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments received in the period (by `paid_at`) grouped by payment method.
 * Refunded payments are listed and identified, but stay out of the totals.
 *
 * @phpstan-type MethodTotals array{method: PaymentMethod, count: int, amount: string}
 * @phpstan-type Totals array{methods: list<MethodTotals>, total: array{count: int, amount: string}, refunded: array{count: int, amount: string}}
 */
class PaymentsReport extends Report
{
    public static function key(): string
    {
        return 'pagamentos';
    }

    public static function title(): string
    {
        return 'Pagamentos recebidos';
    }

    public static function description(): string
    {
        return 'Pagamentos do período agrupados por forma de pagamento.';
    }

    public static function filters(): array
    {
        return [self::FilterPeriod];
    }

    /**
     * Payments of the period, by method, date and receipt.
     *
     * @return Builder<Payment>
     */
    public function query(): Builder
    {
        return Payment::query()
            ->with(['invoice:id,customer_id,reference_month', 'invoice.customer:id,name'])
            ->whereBetween('paid_at', [$this->from->toDateString(), $this->until->toDateString()])
            ->orderBy('payment_method_id')
            ->orderBy('paid_at')
            ->orderBy('receipt_number');
    }

    public function rowCount(): int
    {
        return $this->query()->count();
    }

    /**
     * Count and amount per method of the payments not refunded, plus the
     * grand total and the refunded ones (the rollup row), in a single
     * aggregate over the same query.
     *
     * @return Totals
     */
    public function totals(): array
    {
        $rows = $this->query()
            ->reorder()
            ->toBase()
            ->selectRaw(
                'payment_method_id,
                 count(*) filter (where refunded_at is null) as count,
                 coalesce(sum(amount) filter (where refunded_at is null), 0)::numeric(14, 2) as amount,
                 count(*) filter (where refunded_at is not null) as refunded_count,
                 coalesce(sum(amount) filter (where refunded_at is not null), 0)::numeric(14, 2) as refunded_amount',
            )
            ->groupByRaw('rollup (payment_method_id)')
            ->orderByRaw('payment_method_id nulls last')
            ->get();

        $total = $rows->firstWhere('payment_method_id', null);

        return [
            'methods' => array_values($rows
                ->filter(fn (object $row): bool => $row->payment_method_id !== null && (int) $row->count > 0)
                ->map(fn (object $row): array => [
                    'method' => PaymentMethod::fromId((int) $row->payment_method_id),
                    'count' => (int) $row->count,
                    'amount' => (string) $row->amount,
                ])
                ->all()),
            'total' => ['count' => (int) ($total->count ?? 0), 'amount' => (string) ($total->amount ?? '0.00')],
            'refunded' => ['count' => (int) ($total->refunded_count ?? 0), 'amount' => (string) ($total->refunded_amount ?? '0.00')],
        ];
    }

    public function pdfData(): array
    {
        return [
            'payments' => $this->query()->get()->groupBy('payment_method_id'),
            'totals' => $this->totals(),
        ];
    }

    public function export(): PaymentsExport
    {
        return new PaymentsExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.payments';
    }
}
