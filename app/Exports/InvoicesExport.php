<?php

namespace App\Exports;

use App\Exports\Concerns\WithReportHeader;
use App\Reports\InvoicesReport;
use App\Support\Billing\BillingRow;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Invoices report spreadsheet: the rows of InvoicesReport.
 *
 * @implements WithMapping<object>
 */
class InvoicesExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public InvoicesReport $report) {}

    public function query(): Builder
    {
        return $this->report->query();
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->reportHeadings($this->report, ['Titular / empresa', 'Cartão', 'Plano', 'Referência', 'Vencimento', 'Status', 'Valor', 'Pago em']);
    }

    /**
     * @param  object  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        $line = BillingRow::fromRecord($row);

        return [
            $line->isCompanyBill() ? "{$line->name} (empresa, {$line->employees} funcionários)" : $line->name,
            $line->cardNumber,
            $line->planName ?? 'Fatura da empresa',
            $line->referenceMonth->format('m/Y'),
            Date::dateTimeToExcel($line->dueDate),
            $line->status->label(),
            (float) $line->amount,
            $line->paidAt ? Date::dateTimeToExcel($line->paidAt) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'E' => self::DateFormat,
            'G' => self::MoneyFormat,
            'H' => self::DateFormat,
        ];
    }
}
