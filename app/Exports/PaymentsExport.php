<?php

namespace App\Exports;

use App\Enums\PaymentMethod;
use App\Exports\Concerns\WithReportHeader;
use App\Models\Payment;
use App\Reports\PaymentsReport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Payments report spreadsheet: payments of the period by method, refunded
 * ones flagged in the "Situação" column.
 *
 * @implements WithMapping<Payment>
 */
class PaymentsExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public PaymentsReport $report) {}

    /**
     * @return Builder<Payment>
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
        return $this->reportHeadings($this->report, ['Forma', 'Recibo', 'Data', 'Titular', 'Referência', 'Valor', 'Situação']);
    }

    /**
     * @param  Payment  $row
     * @return list<string|int|float>
     */
    public function map($row): array
    {
        return [
            PaymentMethod::fromId($row->payment_method_id)->label(),
            $row->receipt_number,
            Date::dateTimeToExcel($row->paid_at),
            $row->invoice->customer->name,
            $row->invoice->reference_month->format('m/Y'),
            (float) $row->amount,
            $row->isRefunded() ? 'Estornado em '.$row->refunded_at->format('d/m/Y') : 'Recebido',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'C' => self::DateFormat,
            'F' => self::MoneyFormat,
        ];
    }
}
