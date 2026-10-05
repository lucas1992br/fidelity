<?php

namespace App\Exports;

use App\Enums\InvoiceStatus;
use App\Exports\Concerns\WithReportHeader;
use App\Models\Invoice;
use App\Reports\InvoicesReport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Invoices report spreadsheet: the rows of InvoicesReport.
 *
 * @implements WithMapping<Invoice>
 */
class InvoicesExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public InvoicesReport $report) {}

    /**
     * @return Builder<Invoice>
     */
    public function query(): Builder
    {
        return $this->report->query();
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->reportHeadings($this->report, ['Titular', 'Cartão', 'Plano', 'Referência', 'Vencimento', 'Status', 'Valor', 'Pago em']);
    }

    /**
     * @param  Invoice  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        return [
            $row->customer->name,
            ($row->customer->currentCard ?? $row->customer->lastCard)?->number,
            $row->plan->name,
            $row->reference_month->format('m/Y'),
            Date::dateTimeToExcel($row->due_date),
            InvoiceStatus::fromId($row->invoice_status_id)->label(),
            (float) $row->amount,
            $row->paid_at ? Date::dateTimeToExcel($row->paid_at) : null,
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
