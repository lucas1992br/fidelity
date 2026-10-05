<?php

use App\Models\FinancialEntry;
use Livewire\Livewire;

test('filters by type and status', function () {
    actingAsRole('financeiro');
    $pendingExpense = FinancialEntry::factory()->expense()->pending()->create(['description' => 'Conta de luz']);
    FinancialEntry::factory()->expense()->paid()->create(['description' => 'Material de escritório']);
    FinancialEntry::factory()->income()->pending()->create(['description' => 'Aluguel de sala']);

    Livewire::test('pages::finance.entries')
        ->assertSee(['Conta de luz', 'Material de escritório', 'Aluguel de sala'])
        ->set('type', 'expense')
        ->assertSee(['Conta de luz', 'Material de escritório'])
        ->assertDontSee('Aluguel de sala')
        ->set('status', 'pending')
        ->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$pendingExpense->id])
        ->assertDontSee('Material de escritório');
});

test('filters by category and by payment period', function () {
    actingAsRole('financeiro');
    $inPeriod = FinancialEntry::factory()->paid()->create(['description' => 'Pago em outubro', 'due_date' => '2026-09-20', 'paid_at' => '2026-10-02']);
    FinancialEntry::factory()->paid()->create(['description' => 'Pago em setembro', 'due_date' => '2026-10-02', 'paid_at' => '2026-09-25']);

    Livewire::test('pages::finance.entries')
        ->set('periodBy', 'paid')
        ->set('from', '2026-10-01')
        ->set('until', '2026-10-31')
        ->assertSee('Pago em outubro')
        ->assertDontSee('Pago em setembro')
        ->set('periodBy', 'due')
        ->assertSee('Pago em setembro')
        ->assertDontSee('Pago em outubro')
        ->call('clearFilters')
        ->set('category', (string) $inPeriod->financial_category_id)
        ->assertSee('Pago em outubro')
        ->assertDontSee('Pago em setembro');
});

test('an automatic entry shows the lock and links the holder without offering edit', function () {
    actingAsRole('admin');
    $entry = FinancialEntry::factory()->automatic()->create();
    $customer = $entry->payment->invoice->customer;

    Livewire::test('pages::finance.entries')
        ->assertSee($entry->description)
        ->assertSeeHtml('data-slot="automatic-lock"')
        ->assertSeeHtml(route('customers.show', $customer))
        ->assertDontSeeHtml("entry: {$entry->id}")
        ->assertDontSeeHtml("delete({$entry->id})");
});

test('a manual entry offers edit, delete and settle to finance.manage', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->pending()->create();

    Livewire::test('pages::finance.entries')
        ->assertSee(['Novo lançamento', 'Dar baixa', 'Editar'])
        ->assertSeeHtml("delete({$entry->id})");
});

test('calling delete of an automatic entry through the component returns 403', function () {
    actingAsRole('admin');
    $entry = FinancialEntry::factory()->automatic()->create();

    Livewire::test('pages::finance.entries')
        ->call('delete', $entry->id)
        ->assertForbidden();

    expect($entry->refresh()->trashed())->toBeFalse();
});

test('deleting a manual entry soft deletes it and removes it from the list', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->create(['description' => 'Lançamento duplicado']);

    Livewire::test('pages::finance.entries')
        ->call('delete', $entry->id)
        ->assertDispatched('notify')
        ->assertDontSee('Lançamento duplicado');

    expect($entry->refresh()->trashed())->toBeTrue();
});

test('a user with only finance.view sees the list but gets 403 when deleting', function () {
    actingAsFinanceViewer();
    $entry = FinancialEntry::factory()->create();

    Livewire::test('pages::finance.entries')
        ->assertSee($entry->description)
        ->assertDontSee(['Novo lançamento', 'Dar baixa'])
        ->call('delete', $entry->id)
        ->assertForbidden();

    expect($entry->refresh()->trashed())->toBeFalse();
});

test('atendente gets 403 and the entries route renders the page', function () {
    actingAsRole('atendente');
    Livewire::test('pages::finance.entries')->assertForbidden();

    actingAsRole('financeiro');
    $this->withoutVite()->get(route('finance.entries'))->assertOk()->assertSee('Novo lançamento');
});
