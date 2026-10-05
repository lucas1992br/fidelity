<?php

namespace App\Reports;

use App\Exports\CashFlowExport;
use App\Services\CashFlowReport;

/**
 * Cash flow of the period (CashFlowReport, the same numbers as the Fluxo de
 * caixa screen): daily up to 31 days, monthly for longer periods.
 *
 * @phpstan-import-type CashFlow from CashFlowReport
 */
class CashFlowReportExport extends Report
{
    public static function key(): string
    {
        return 'fluxo-de-caixa';
    }

    public static function title(): string
    {
        return 'Fluxo de caixa';
    }

    public static function description(): string
    {
        return 'Entradas, saídas e saldo acumulado no regime de caixa.';
    }

    public static function filters(): array
    {
        return [self::FilterPeriod];
    }

    public function granularity(): string
    {
        return $this->from->diffInDays($this->until) < 31 ? CashFlowReport::Daily : CashFlowReport::Monthly;
    }

    /**
     * @return CashFlow
     */
    public function data(): array
    {
        return app(CashFlowReport::class)->between($this->from, $this->until, $this->granularity());
    }

    public function rowCount(): int
    {
        return count($this->data()['rows']);
    }

    public function pdfData(): array
    {
        return ['cashFlow' => $this->data()];
    }

    public function export(): CashFlowExport
    {
        return new CashFlowExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.cash-flow';
    }
}
