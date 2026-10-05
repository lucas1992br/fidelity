<?php

namespace App\Exports;

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Exports\Concerns\WithReportHeader;
use App\Models\FinancialEntry;
use App\Reports\EntriesReport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Entries report spreadsheet: entries of the period by type and category.
 *
 * @implements WithMapping<FinancialEntry>
 */
class EntriesExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public EntriesReport $report) {}

    /**
     * @return Builder<FinancialEntry>
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
        return $this->reportHeadings($this->report, ['Tipo', 'Categoria', 'Descrição', 'Fornecedor / pagador', 'Vencimento', 'Status', 'Pago em', 'Valor']);
    }

    /**
     * @param  FinancialEntry  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        return [
            EntryType::fromId($row->entry_type_id)->label(),
            $row->category->name,
            $row->description,
            $row->supplier_or_payer,
            Date::dateTimeToExcel($row->due_date),
            EntryStatus::fromId($row->entry_status_id)->label(),
            $row->paid_at ? Date::dateTimeToExcel($row->paid_at) : null,
            (float) $row->amount,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'E' => self::DateFormat,
            'G' => self::DateFormat,
            'H' => self::MoneyFormat,
        ];
    }
}
