<?php

namespace App\Reports;

use App\Exports\DelinquentsExport;
use App\Queries\DelinquentsQuery;
use App\Support\Billing\DelinquentRow;
use Illuminate\Database\Query\Builder;

/**
 * Holders with overdue invoices and companies with overdue bills, longest
 * delay first (DelinquentsQuery, the same rows as the Inadimplentes screen). A snapshot: the period does not apply.
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
        return 'Titulares e empresas com faturas em atraso, pelo maior atraso.';
    }

    public static function filters(): array
    {
        return [];
    }

    /**
     * Rows of {@see DelinquentRow} records, longest delay first.
     */
    public function query(): Builder
    {
        return app(DelinquentsQuery::class)->rows();
    }

    public function rowCount(): int
    {
        return app(DelinquentsQuery::class)->count();
    }

    public function pdfData(): array
    {
        return [
            'delinquents' => app(DelinquentsQuery::class)->get(),
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
