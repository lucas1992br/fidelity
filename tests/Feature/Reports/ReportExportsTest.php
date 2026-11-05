<?php

use App\Enums\PaymentMethod;
use App\Exports\PaymentsExport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanChange;
use App\Reports\PaymentsReport;
use App\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->from = CarbonImmutable::parse('2026-10-01');
    $this->until = CarbonImmutable::parse('2026-10-31');
});

test('the payments spreadsheet downloads with the clinic header and the expected columns', function () {
    settings(['clinic_name' => 'Policlínica Central']);
    $customer = Customer::factory()->create(['name' => 'Maria Conceição']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->paid()->create(['amount' => '89.90']);
    $payment = Payment::factory()->for($invoice)->method(PaymentMethod::Pix)->create(['paid_at' => '2026-10-02', 'amount' => '89.90']);
    Excel::fake();

    (new PaymentsReport($this->from, $this->until))->downloadExcel();

    Excel::assertDownloaded('relatorio-pagamentos-2026-10-15.xlsx', function (PaymentsExport $export) use ($payment): bool {
        $headings = $export->headings();
        $row = $export->map($export->query()->sole());

        return $headings[0] === ['Policlínica Central']
            && $headings[2][0] === 'Pagamentos recebidos · 01/10/2026 a 31/10/2026 · emitido em 15/10/2026 10:00'
            && $headings[4] === ['Forma', 'Recibo', 'Data', 'Titular', 'Referência', 'Valor', 'Situação']
            && $row[0] === 'PIX'
            && $row[1] === $payment->refresh()->receipt_number
            && array_slice($row, 3) === ['Maria Conceição', '10/2026', 89.9, 'Recebido']
            && $export->columnFormats() === ['C' => 'dd/mm/yyyy', 'F' => '"R$" #,##0.00'];
    });
});

test('a spreadsheet above the row limit is queued to the exports disk', function () {
    config(['reports.queue_after_rows' => 2]);
    Payment::factory()->count(3)->create(['paid_at' => '2026-10-05']);
    Excel::fake();

    $report = new PaymentsReport($this->from, $this->until);

    expect($report->shouldQueueExcel())->toBeTrue();

    $path = $report->queueExcel();

    expect($path)->toStartWith('relatorio-pagamentos-2026-10-15-')->toEndWith('.xlsx');
    Excel::assertQueued($path, 'exports', fn (PaymentsExport $export): bool => $export->query()->count() === 3);
});

test('a spreadsheet within the limit is not queued', function () {
    Payment::factory()->count(3)->create(['paid_at' => '2026-10-05']);

    expect((new PaymentsReport($this->from, $this->until))->shouldQueueExcel())->toBeFalse();
});

test('the queued spreadsheet is written to the exports disk', function () {
    Storage::fake('exports');
    Payment::factory()->count(2)->create(['paid_at' => '2026-10-05']);

    $path = (new PaymentsReport($this->from, $this->until))->queueExcel();

    Storage::disk('exports')->assertExists($path);
});

test('every report downloads as a PDF and as a real spreadsheet', function (string $key) {
    $customer = Customer::factory()->withCard()->create();
    Dependent::factory()->for($customer)->create();
    Invoice::factory()->for($customer)->overdue(20)->create();
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->paid()->create();
    $payment = Payment::factory()->for($invoice)->create(['paid_at' => '2026-10-10']);
    FinancialEntry::factory()->automatic($payment)->create();
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-10-03', 'paid_at' => '2026-10-03']);
    $employee = Customer::factory()->forCompany(Company::factory()->create())->withCard()->create();
    Dependent::factory()->for($employee)->create();
    PlanChange::factory()->for($employee)->sale()->create(['created_at' => '2026-10-05 09:00']);

    $report = new (Report::catalog()[$key])($this->from, $this->until);
    $pdf = $report->downloadPdf();

    ob_start();
    $pdf->sendContent();
    $content = ob_get_clean();

    expect($pdf)->toBeInstanceOf(StreamedResponse::class)
        ->and($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($content)->toStartWith('%PDF')
        ->and($report->downloadExcel()->getFile()->getSize())->toBeGreaterThan(0);
})->with(['clientes', 'empresas', 'mensalidades', 'inadimplencia', 'pagamentos', 'lancamentos', 'fluxo-de-caixa', 'vendas-por-atendente']);
