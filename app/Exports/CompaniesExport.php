<?php

namespace App\Exports;

use App\Exports\Concerns\WithReportHeader;
use App\Models\Company;
use App\Reports\CompaniesReport;
use App\Reports\CustomersReport;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Companies report spreadsheet: one row per company (with its totals),
 * followed by one row per employee and one per active dependent. CPFs are
 * masked (LGPD).
 *
 * @implements WithMapping<Company>
 */
class CompaniesExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public CompaniesReport $report) {}

    /**
     * @return Builder<Company>
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
        return $this->reportHeadings($this->report, ['Tipo', 'Empresa', 'Nome', 'Cartão / identificação', 'CPF / CNPJ', 'Parentesco', 'Plano', 'Status', 'Valor mensal']);
    }

    /**
     * @param  Company  $row
     * @return list<list<string|float|null>>
     */
    public function map($row): array
    {
        $totals = CompaniesReport::totalsOf($row);

        $rows = [[
            'Empresa',
            $row->display_name,
            "{$totals['employees']} funcionário(s) ativo(s) · {$totals['dependents']} dependente(s)",
            null,
            $row->formatted_cnpj,
            null,
            null,
            $row->is_active ? 'Ativa' : 'Inativa',
            (float) $totals['monthly'],
        ]];

        foreach ($row->employees as $employee) {
            $card = $employee->currentCard ?? $employee->lastCard;
            $status = CustomersReport::cardStatusOf($employee)?->label() ?? 'Sem cartão';

            $rows[] = ['Funcionário', $row->display_name, $employee->name, $card?->number, $employee->masked_cpf, null, $employee->plan->name, $status, (float) $employee->monthly_amount];

            foreach ($employee->activeDependents as $dependent) {
                $rows[] = [
                    'Dependente',
                    $row->display_name,
                    $dependent->name,
                    $card ? $card->number.'-'.str_pad((string) $dependent->sequence, 2, '0', STR_PAD_LEFT) : null,
                    $dependent->cpf ? Format::maskCpf($dependent->cpf) : null,
                    $dependent->kinship->name,
                    $employee->plan->name,
                    $status,
                    null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['I' => self::MoneyFormat];
    }
}
