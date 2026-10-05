<?php

namespace App\Exports\Concerns;

use App\Reports\Report;
use App\Settings\ClinicSettings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Header rows shared by the report spreadsheets: clinic name and data, the
 * report title with its period and issue date, a blank line and the column
 * titles. Settings are read when the file is written, so the export can be
 * serialized onto the queue.
 */
trait WithReportHeader
{
    public const string MoneyFormat = '"R$" #,##0.00';

    public const string DateFormat = NumberFormat::FORMAT_DATE_DDMMYYYY;

    /**
     * @param  list<string>  $columns
     * @return list<list<string>>
     */
    protected function reportHeadings(Report $report, array $columns): array
    {
        $clinic = app(ClinicSettings::class);

        return [
            [$clinic->clinic_name],
            [collect([
                $clinic->clinic_cnpj ? "CNPJ {$clinic->clinic_cnpj}" : null,
                $clinic->clinic_address,
                $clinic->clinic_phone ? "Telefone {$clinic->clinic_phone}" : null,
            ])->filter()->implode(' · ')],
            [$report::title().' · '.$report->periodLabel().' · emitido em '.now()->format('d/m/Y H:i')],
            [],
            $columns,
        ];
    }
}
