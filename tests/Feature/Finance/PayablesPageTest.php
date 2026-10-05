<?php

use App\Models\FinancialEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00');
});

test('groups pending expenses by due date with the total of each group', function () {
    actingAsRole('financeiro');
    $overdue = FinancialEntry::factory()->expense()->pending()->create(['description' => 'Conta de luz', 'due_date' => '2026-10-04', 'amount' => '120.50']);
    $dueSoon = FinancialEntry::factory()->expense()->pending()->create(['description' => 'Internet', 'due_date' => '2026-10-08', 'amount' => '99.90']);
    $future = FinancialEntry::factory()->expense()->pending()->create(['description' => 'Aluguel', 'due_date' => '2026-10-25', 'amount' => '1500.00']);

    Livewire::test('pages::finance.payables')
        ->assertViewHas('groups', fn (array $groups): bool => $groups['overdue']['entries']->modelKeys() === [$overdue->id]
            && $groups['due_soon']['entries']->modelKeys() === [$dueSoon->id]
            && $groups['future']['entries']->modelKeys() === [$future->id]
            && $groups['overdue']['total'] === '120.50'
            && $groups['due_soon']['total'] === '99.90'
            && $groups['future']['total'] === '1500.00')
        ->assertSeeInOrder(['Vencidas', 'Conta de luz', 'Vencendo em 7 dias', 'Internet', 'Futuras', 'Aluguel'])
        ->assertSee('R$ 1.500,00');
});

test('paid, cancelled and income entries do not appear', function () {
    actingAsRole('financeiro');
    FinancialEntry::factory()->expense()->paid()->create(['description' => 'Despesa paga', 'due_date' => '2026-10-04']);
    FinancialEntry::factory()->expense()->cancelled()->create(['description' => 'Despesa cancelada', 'due_date' => '2026-10-04']);
    FinancialEntry::factory()->income()->pending()->create(['description' => 'Receita pendente', 'due_date' => '2026-10-04']);

    Livewire::test('pages::finance.payables')
        ->assertDontSee(['Despesa paga', 'Despesa cancelada', 'Receita pendente']);
});

test('settling an expense removes it from the list', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->expense()->pending()->create(['description' => 'Conta de água', 'due_date' => '2026-10-03']);

    $page = Livewire::test('pages::finance.payables')
        ->assertSee('Conta de água')
        ->assertSeeHtml("open-settle-entry', { entry: {$entry->id} }");

    Livewire::test('finance.settle-modal')
        ->dispatch('open-settle-entry', entry: $entry->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('financial-entry-saved');

    $page->dispatch('financial-entry-saved', entry: $entry->id)
        ->assertDontSee('Conta de água');
});

test('a user with only finance.view does not get the settle action', function () {
    actingAsFinanceViewer();
    FinancialEntry::factory()->expense()->pending()->create(['description' => 'Conta de luz']);

    Livewire::test('pages::finance.payables')
        ->assertSee('Conta de luz')
        ->assertDontSee('Dar baixa');
});

test('atendente gets 403 and the payables route renders the page', function () {
    actingAsRole('atendente');
    Livewire::test('pages::finance.payables')->assertForbidden();

    actingAsRole('financeiro');
    $this->withoutVite()->get(route('finance.payables'))->assertOk()->assertSee('Vencendo em 7 dias');
});
