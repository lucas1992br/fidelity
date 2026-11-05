<?php

namespace App\Exports;

use App\Enums\InvoiceStatus;
use App\Exports\Concerns\WithReportHeader;
use App\Models\PlanChange;
use App\Reports\CustomersReport;
use App\Reports\SalesByUserReport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Sales by user spreadsheet: one row per sign-up or re-enrollment, with the
 * seller, the monthly amount sold and the first invoice status, ready to be
 * summed for commissions. CPFs are masked (LGPD).
 *
 * @implements WithMapping<PlanChange>
 */
class SalesByUserExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    use Exportable, WithReportHeader;

    public function __construct(public SalesByUserReport $report) {}

    /**
     * @return Builder<PlanChange>
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
        return $this->reportHeadings($this->report, ['Data', 'Atendente', 'Tipo', 'Cliente', 'CPF', 'Cartão', 'Plano', 'Empresa', 'Valor mensal', '1ª mensalidade', 'Status do cartão']);
    }

    /**
     * @param  PlanChange  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        $customer = $row->customer;
        $firstInvoice = SalesByUserReport::firstInvoiceOf($row);

        return [
            Date::dateTimeToExcel($row->created_at),
            $row->user->name,
            SalesByUserReport::typeOf($row),
            $customer->name,
            $customer->masked_cpf,
            ($customer->currentCard ?? $customer->lastCard)?->number,
            $row->toPlan->name,
            $customer->company?->display_name,
            (float) $row->to_price,
            $firstInvoice ? InvoiceStatus::fromId($firstInvoice->invoice_status_id)->label() : '—',
            CustomersReport::cardStatusOf($customer)?->label() ?? 'Sem cartão',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['A' => self::DateFormat, 'I' => self::MoneyFormat];
    }
}
