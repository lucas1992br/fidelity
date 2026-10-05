<?php

namespace App\Exports;

use App\Exports\Concerns\WithReportHeader;
use App\Reports\CashFlowReportExport;
use App\Services\CashFlowReport;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Cash flow spreadsheet: the opening balance, one row per day or month and
 * the period totals. The cash flow is an aggregate, so it comes from an
 * array instead of a query.
 *
 * @phpstan-import-type CashFlowRow from CashFlowReport
 *
 * @implements WithMapping<CashFlowRow>
 */
class CashFlowExport implements FromArray, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public CashFlowReportExport $report) {}

    /**
     * Opening balance, the period rows and the totals, shaped as rows.
     *
     * @return list<CashFlowRow>
     */
    public function array(): array
    {
        $cashFlow = $this->report->data();
        $blank = ['key' => '', 'forecast_income' => null, 'forecast_expense' => null];

        return [
            [...$blank, 'label' => 'Saldo inicial', 'income' => '0.00', 'expense' => '0.00', 'balance' => '0.00', 'cumulative' => $cashFlow['opening_balance']],
            ...$cashFlow['rows'],
            [
                ...$blank,
                'label' => 'Total',
                'income' => $cashFlow['total_income'],
                'expense' => $cashFlow['total_expense'],
                'balance' => $cashFlow['total_balance'],
                'cumulative' => $cashFlow['closing_balance'],
            ],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $period = $this->report->granularity() === CashFlowReport::Monthly ? 'Mês' : 'Dia';

        return $this->reportHeadings($this->report, [$period, 'Entradas', 'Saídas', 'Saldo', 'Saldo acumulado']);
    }

    /**
     * @param  CashFlowRow  $row
     * @return list<string|float>
     */
    public function map($row): array
    {
        return [
            $row['label'],
            (float) $row['income'],
            (float) $row['expense'],
            (float) $row['balance'],
            (float) $row['cumulative'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'B' => self::MoneyFormat,
            'C' => self::MoneyFormat,
            'D' => self::MoneyFormat,
            'E' => self::MoneyFormat,
        ];
    }
}
