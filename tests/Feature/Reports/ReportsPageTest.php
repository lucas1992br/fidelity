<?php

use App\Exports\DelinquentsExport;
use App\Exports\PaymentsExport;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('the financeiro downloads the delinquency report as PDF and Excel', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->overdue(30)->create();

    Livewire::test('pages::reports')
        ->call('selectReport', 'inadimplencia')
        ->assertSee('Posição atual')
        ->call('downloadPdf')
        ->assertHasNoErrors()
        ->assertFileDownloaded('relatorio-inadimplencia-2026-10-15.pdf', contentType: 'application/pdf');

    Excel::fake();

    Livewire::test('pages::reports', ['report' => 'inadimplencia'])
        ->call('downloadExcel')
        ->assertFileDownloaded();

    Excel::assertDownloaded('relatorio-inadimplencia-2026-10-15.xlsx', fn (DelinquentsExport $export): bool => $export->query()->pluck('customers.id')->all() === [$customer->id]);
});

test('the period defaults to the current month and the filters reach the report', function () {
    actingAsRole('financeiro');
    Payment::factory()->create(['paid_at' => '2026-10-05']);
    Payment::factory()->create(['paid_at' => '2026-09-05']);
    Excel::fake();

    Livewire::test('pages::reports')
        ->assertSet('from', '2026-10-01')
        ->assertSet('until', '2026-10-31')
        ->call('selectReport', 'pagamentos')
        ->set('from', '2026-09-01')
        ->call('downloadExcel')
        ->assertFileDownloaded();

    Excel::assertDownloaded('relatorio-pagamentos-2026-10-15.xlsx', fn (PaymentsExport $export): bool => $export->query()->count() === 2);
});

test('an invalid period is rejected', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::reports', ['report' => 'pagamentos'])
        ->set('from', '2026-10-20')
        ->set('until', '2026-10-01')
        ->call('downloadPdf')
        ->assertHasErrors(['until' => 'after_or_equal']);
});

test('a large spreadsheet shows as processing and becomes a download link when ready', function () {
    actingAsRole('financeiro');
    config(['reports.queue_after_rows' => 1]);
    Storage::fake('exports');
    Payment::factory()->count(2)->create(['paid_at' => '2026-10-05']);
    Excel::fake();

    $page = Livewire::test('pages::reports', ['report' => 'pagamentos'])
        ->call('downloadExcel')
        ->assertDispatched('notify')
        ->assertSee('Processando…')
        ->assertSeeHtml('wire:poll.5s="checkQueuedExports"');

    $path = $page->get('queuedExports')[0]['path'];
    Excel::assertQueued($path, 'exports');

    $page->call('checkQueuedExports')->assertSee('Processando…');

    Storage::disk('exports')->put($path, 'xlsx');

    $page->call('checkQueuedExports')
        ->assertDontSee('Processando…')
        ->assertSee('Baixar')
        ->assertDontSeeHtml('wire:poll.5s')
        ->call('downloadQueued', 0)
        ->assertFileDownloaded(basename($path));
});

test('the atendente gets 403 on mount', function () {
    actingAsRole('atendente');

    Livewire::test('pages::reports')->assertForbidden();
    $this->get(route('reports.index'))->assertForbidden();
});

test('the atendente gets 403 on every download method', function (string $method, array $arguments) {
    actingAsRole('financeiro');
    $page = Livewire::test('pages::reports', ['report' => 'inadimplencia']);

    actingAsRole('atendente');

    $page->call($method, ...$arguments)->assertForbidden();
})->with([
    'pdf' => ['downloadPdf', []],
    'excel' => ['downloadExcel', []],
    'queued file' => ['downloadQueued', [0]],
    'polling' => ['checkQueuedExports', []],
]);

test('the reports route renders the page with every report', function () {
    actingAsRole('financeiro');

    $this->withoutVite()->get(route('reports.index'))
        ->assertOk()
        ->assertSee(['Clientes e dependentes', 'Mensalidades', 'Inadimplência', 'Pagamentos recebidos', 'Lançamentos por categoria', 'Fluxo de caixa', 'Empresas e funcionários', 'Vendas por atendente']);
});
