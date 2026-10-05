<?php

namespace App\Exports;

use App\Exports\Concerns\WithReportHeader;
use App\Models\Customer;
use App\Reports\CustomersReport;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Customers report spreadsheet: one row per holder followed by one row per
 * active dependent. CPFs are masked (LGPD).
 *
 * @implements WithMapping<Customer>
 */
class CustomersExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public CustomersReport $report) {}

    /**
     * @return Builder<Customer>
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
        return $this->reportHeadings($this->report, ['Tipo', 'Nome', 'Cartão / identificação', 'CPF', 'Parentesco', 'Plano', 'Status do cartão', 'Telefone', 'Adesão']);
    }

    /**
     * @param  Customer  $row
     * @return list<list<string|float|null>>
     */
    public function map($row): array
    {
        $card = $row->currentCard ?? $row->lastCard;

        $rows = [[
            'Titular',
            $row->name,
            $card?->number,
            $row->masked_cpf,
            null,
            $row->plan->name,
            CustomersReport::cardStatusOf($row)?->label() ?? 'Sem cartão',
            Format::phone($row->phone),
            Date::dateTimeToExcel($row->joined_at),
        ]];

        foreach ($row->activeDependents as $dependent) {
            $rows[] = [
                'Dependente',
                $dependent->name,
                $card ? $card->number.'-'.str_pad((string) $dependent->sequence, 2, '0', STR_PAD_LEFT) : null,
                $dependent->cpf ? Format::maskCpf($dependent->cpf) : null,
                $dependent->kinship->name,
                $row->plan->name,
                CustomersReport::cardStatusOf($row)?->label() ?? 'Sem cartão',
                null,
                null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['I' => self::DateFormat];
    }
}
