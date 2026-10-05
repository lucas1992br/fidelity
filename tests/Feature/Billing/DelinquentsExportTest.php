<?php

use App\Exports\DelinquentsExport;
use App\Models\Customer;
use App\Models\Invoice;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->maria = Customer::factory()->withCard()->create(['name' => 'Maria Conceição', 'phone' => '11987654321']);
    Invoice::factory()->for($this->maria)->overdue(55)->create(['amount' => '89.90']);
    Invoice::factory()->for($this->maria)->overdue(24)->create(['amount' => '89.90']);
    $this->jose = Customer::factory()->withCard()->create(['name' => 'José Ribeiro']);
    Invoice::factory()->for($this->jose)->overdue(10)->create(['amount' => '50.00']);
});

test('the Excel export downloads the rows of the query', function () {
    actingAsRole('financeiro');
    settings(['clinic_name' => 'Policlínica Central']);
    Excel::fake();

    $this->get(route('billing.delinquents.excel'))->assertOk();

    Excel::assertDownloaded('inadimplentes-2026-10-15.xlsx', function (DelinquentsExport $export): bool {
        $rows = $export->query()->get();

        return $rows->pluck('id')->all() === [$this->maria->id, $this->jose->id]
            && $export->headings()[0] === ['Policlínica Central']
            && str_contains($export->headings()[2][0], 'emitido em 15/10/2026')
            && array_slice($export->map($rows->first()), 0, 5) === ['Maria Conceição', '(11) 98765-4321', 'https://wa.me/5511987654321', 2, 179.8]
            && $export->map($rows->first())[6] === 55;
    });
});

test('the PDF export returns a PDF', function () {
    actingAsRole('financeiro');

    $this->get(route('billing.delinquents.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('the atendente gets 403 on both downloads', function () {
    actingAsRole('atendente');

    $this->get(route('billing.delinquents.excel'))->assertForbidden();
    $this->get(route('billing.delinquents.pdf'))->assertForbidden();
});

test('the Excel file is generated with the column formats', function () {
    actingAsRole('financeiro');

    $this->get(route('billing.delinquents.excel'))
        ->assertOk()
        ->assertDownload('inadimplentes-2026-10-15.xlsx');
});
