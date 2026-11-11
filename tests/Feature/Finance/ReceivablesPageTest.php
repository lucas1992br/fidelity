<?php

use App\Actions\Billing\IssueInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00');
});

test('shows a pending manual income and an overdue invoice ordered by due date with totals per origin', function () {
    actingAsRole('financeiro');
    $income = FinancialEntry::factory()->income()->pending()->create(['description' => 'Aluguel de sala', 'due_date' => '2026-10-12', 'amount' => '300.00']);
    $invoice = Invoice::factory()->overdue(5)->create(['amount' => '89.90']);

    Livewire::withQueryParams(['vencidas' => '1'])
        ->test('pages::finance.receivables')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["invoice-{$invoice->id}", "entry-{$income->id}"])
        ->assertViewHas('totals', ['entries' => '300.00', 'invoices' => '89.90'])
        ->assertSeeInOrder(['Mensalidade 09/2026', 'Em atraso', 'Aluguel de sala'])
        ->assertSee($invoice->customer->name)
        ->assertSeeHtml(route('customers.show', [$invoice->customer, 'tab' => 'mensalidades']))
        ->assertSee('R$ 389,90');
});

test('an invoice does not offer the settle action while a manual income does', function () {
    actingAsRole('financeiro');
    $income = FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-10-12']);
    Invoice::factory()->forMonth('2026-10')->open()->create(['due_date' => '2026-10-20']);

    $page = Livewire::test('pages::finance.receivables')
        ->assertSeeHtml("open-settle-entry', { entry: {$income->id} }")
        ->assertSee('Somente leitura');

    expect(substr_count($page->html(), "open-settle-entry', { entry:"))->toBe(1);
});

test('paid and cancelled invoices and settled incomes do not appear', function () {
    actingAsRole('financeiro');
    Invoice::factory()->forMonth('2026-08')->paid()->create();
    Invoice::factory()->forMonth('2026-07')->cancelled()->create();
    FinancialEntry::factory()->income()->paid()->create(['description' => 'Receita recebida']);
    FinancialEntry::factory()->expense()->pending()->create(['description' => 'Despesa pendente']);

    Livewire::test('pages::finance.receivables')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->isEmpty())
        ->assertDontSee(['Mensalidade 08/2026', 'Mensalidade 07/2026', 'Receita recebida', 'Despesa pendente']);
});

test('atendente gets 403 and the receivables route renders the page', function () {
    actingAsRole('atendente');
    Livewire::test('pages::finance.receivables')->assertForbidden();

    actingAsRole('financeiro');
    $this->withoutVite()->get(route('finance.receivables'))->assertOk()->assertSee('Total a receber');
});

test('the current month is the default period and earlier overdue items only show when checked', function () {
    actingAsRole('financeiro');
    $october = FinancialEntry::factory()->income()->pending()->create(['description' => 'Receita de outubro', 'due_date' => '2026-10-20']);
    $november = FinancialEntry::factory()->income()->pending()->create(['description' => 'Receita de novembro', 'due_date' => '2026-11-10']);
    $lateInvoice = Invoice::factory()->overdue(40)->create(['amount' => '89.90']);

    Livewire::test('pages::finance.receivables')
        ->assertSet('from', '2026-10-01')
        ->assertSet('until', '2026-10-31')
        ->assertSee('Outubro de 2026')
        ->assertSet('includeEarlierOverdue', false)
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["entry-{$october->id}"])
        ->set('includeEarlierOverdue', true)
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["invoice-{$lateInvoice->id}", "entry-{$october->id}"])
        ->set('includeEarlierOverdue', false)
        ->call('nextMonth')
        ->assertSet('from', '2026-11-01')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["entry-{$november->id}"]);
});

test('an interval of days narrows the list and an inverted interval is swapped', function () {
    actingAsRole('financeiro');
    $inside = FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-10-10']);
    FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-10-20']);

    Livewire::withQueryParams(['de' => '2026-10-15', 'ate' => '2026-10-08', 'vencidas' => '0'])
        ->test('pages::finance.receivables')
        ->assertSet('from', '2026-10-08')
        ->assertSet('until', '2026-10-15')
        ->assertSee('08/10/2026 a 15/10/2026')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["entry-{$inside->id}"]);
});

test('an open company bill is listed in place of its employee invoices, linking to the company', function () {
    actingAsRole('financeiro');
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA', 'monthly_amount' => '1200.00', 'due_day' => 20]);
    foreach (range(1, 2) as $employee) {
        app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), today());
    }

    Livewire::test('pages::finance.receivables')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["company-{$company->id}-2026-10"])
        ->assertViewHas('totals', ['entries' => '0.00', 'invoices' => '1200.00'])
        ->assertSee(['Fatura da empresa 10/2026', 'Metalúrgica Souza LTDA'])
        ->assertSeeHtml(route('companies.show', ['company' => $company->id, 'tab' => 'faturas']));
});
