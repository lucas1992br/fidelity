<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Exports\InvoicesExport;
use App\Queries\InvoicesQuery;
use App\Support\Billing\BillingRow;
use Illuminate\Database\Query\Builder;

/**
 * Holder invoices and company bills (in place of the employees) due in the
 * period, by status and plan, with the summary of the Mensalidades screen
 * (InvoicesQuery); cancelled lines stay out of the total.
 */
class InvoicesReport extends Report
{
    public static function key(): string
    {
        return 'mensalidades';
    }

    public static function title(): string
    {
        return 'Mensalidades';
    }

    public static function description(): string
    {
        return 'Mensalidades com vencimento no período, por status e plano.';
    }

    public static function filters(): array
    {
        return [self::FilterPeriod, self::FilterPlan, self::FilterStatus];
    }

    public static function statusOptions(): array
    {
        return collect(InvoiceStatus::cases())
            ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    public function invoices(): InvoicesQuery
    {
        return new InvoicesQuery(
            dueFrom: $this->from->toDateString(),
            dueUntil: $this->until->toDateString(),
            status: InvoiceStatus::tryFrom((string) $this->status),
            planId: $this->planId,
        );
    }

    /**
     * Rows of {@see BillingRow} records, in the listing order.
     */
    public function query(): Builder
    {
        return $this->invoices()->rows();
    }

    public function rowCount(): int
    {
        return $this->query()->count();
    }

    public function pdfData(): array
    {
        return [
            'invoices' => $this->query()->get()->map(fn (object $record): BillingRow => BillingRow::fromRecord($record)),
            'summary' => $this->invoices()->summary(),
        ];
    }

    public function export(): InvoicesExport
    {
        return new InvoicesExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.invoices';
    }
}
