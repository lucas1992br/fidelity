<?php

namespace App\Exports;

use App\Queries\DelinquentsQuery;
use App\Settings\ClinicSettings;
use App\Support\Billing\DelinquentRow;
use App\Support\Format;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Delinquents spreadsheet (US-8.5 and the delinquency report of US-11.2):
 * the rows of DelinquentsQuery under a header with the clinic data and the
 * issue date. Settings are read when the file is written, so the export can
 * be serialized onto the queue.
 *
 * @implements WithMapping<object>
 */
class DelinquentsExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(protected DelinquentsQuery $delinquents) {}

    public function query(): Builder
    {
        return $this->delinquents->rows();
    }

    /**
     * Clinic header, issue date and the column titles.
     *
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $clinic = app(ClinicSettings::class);

        return [
            [$clinic->clinic_name],
            [collect([
                $clinic->clinic_cnpj ? "CNPJ {$clinic->clinic_cnpj}" : null,
                $clinic->clinic_address,
                $clinic->clinic_phone ? "Telefone {$clinic->clinic_phone}" : null,
            ])->filter()->implode(' · ')],
            ['Inadimplentes · emitido em '.now()->format('d/m/Y H:i')],
            [],
            ['Titular / empresa', 'Telefone', 'WhatsApp', 'Faturas em atraso', 'Valor em atraso', 'Vencimento mais antigo', 'Maior atraso (dias)', 'Cartão / situação'],
        ];
    }

    /**
     * @param  object  $row
     * @return list<string|int|float>
     */
    public function map($row): array
    {
        $line = DelinquentRow::fromRecord($row);

        return [
            $line->isCompany() ? "{$line->name} (empresa)" : $line->name,
            $line->phone ? Format::phone($line->phone) : '',
            $line->phone ? Format::whatsappUrl($line->phone) : '',
            $line->overdueCount,
            (float) $line->overdueAmount,
            Date::dateTimeToExcel($line->oldestDueDate),
            $line->maxDaysOverdue,
            $line->situation(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'E' => '"R$" #,##0.00',
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }
}
