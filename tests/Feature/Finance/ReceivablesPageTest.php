<?php

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

    Livewire::test('pages::finance.receivables')
        ->assertViewHas('receivables', fn ($rows): bool => $rows->pluck('key')->all() === ["invoice-{$invoice->id}", "entry-{$income->id}"])
        ->assertViewHas('totals', ['entries' => '300.00', 'invoices' => '89.90'])
        ->assertSeeInOrder(['Mensalidade 09/2026', 'Em atraso', 'Aluguel de sala'])
        ->assertSee($invoice->customer->name)
        ->assertSeeHtml(route('customers.show', [$invoice->customer, 'tab' => 'mensalidades']))
        ->assertSee('R$ 389,90');
});

test('an invoice does not offer the settle action while a manual income does', function () {
    actingAsRole('financeiro');
    $income = FinancialEntry::factory()->income()->pending()->create();
    Invoice::factory()->open()->create();

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
