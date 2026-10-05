<?php

namespace App\Reports;

use App\Exports\DelinquentsExport;
use App\Models\Customer;
use App\Queries\DelinquentsQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Holders with overdue invoices, longest delay first (DelinquentsQuery, the
 * same rows as the Inadimplentes screen). A snapshot: the period does not apply.
 */
class DelinquencyReport extends Report
{
    public static function key(): string
    {
        return 'inadimplencia';
    }

    public static function title(): string
    {
        return 'Inadimplência';
    }

    public static function description(): string
    {
        return 'Titulares com mensalidades em atraso, pelo maior atraso.';
    }

    public static function filters(): array
    {
        return [];
    }

    /**
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        return app(DelinquentsQuery::class)->builder();
    }

    public function rowCount(): int
    {
        return app(DelinquentsQuery::class)->holdersCount();
    }

    public function pdfData(): array
    {
        return [
            'delinquents' => $this->query()->get(),
            'summary' => app(DelinquentsQuery::class)->summary(),
        ];
    }

    public function export(): DelinquentsExport
    {
        return new DelinquentsExport(app(DelinquentsQuery::class));
    }

    /**
     * The same PDF as the Inadimplentes screen.
     */
    public function pdfView(): string
    {
        return 'pdf.reports.delinquents';
    }
}
