<?php

use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Livewire\Livewire;

test('the categories route renders the page grouped by type with a lock on system categories', function () {
    actingAsRole('financeiro');

    $this->withoutVite()->get(route('finance.categories'))
        ->assertOk()
        ->assertSeeInOrder(['Receitas', 'Mensalidades', 'Outras receitas', 'Despesas', 'Aluguel'])
        ->assertSee('data-slot="system-lock"', escape: false)
        ->assertSee('Nova categoria');
});

test('a user with only finance.view gets 403 when saving', function () {
    actingAsFinanceViewer();

    Livewire::test('pages::finance.categories')
        ->assertOk()
        ->assertDontSee('Nova categoria')
        ->set('name', 'Copa')
        ->call('save')
        ->assertForbidden();

    expect(FinancialCategory::where('name', 'Copa')->exists())->toBeFalse();
});

test('a user with only finance.view cannot call the change methods', function (string $method) {
    actingAsFinanceViewer();
    $category = FinancialCategory::factory()->create();

    Livewire::test('pages::finance.categories')
        ->call($method, ...($method === 'create' ? [] : [$category->id]))
        ->assertForbidden();

    expect($category->refresh()->is_active)->toBeTrue();
})->with(['create', 'edit', 'toggleActive', 'delete']);

test('atendente gets 403 on mount', function () {
    actingAsRole('atendente');

    Livewire::test('pages::finance.categories')->assertForbidden();
});

test('financeiro creates, edits and deactivates a category', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::finance.categories')
        ->call('create', 'income')
        ->set('name', 'Doações')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('isModalOpen', false)
        ->assertDispatched('notify');

    $category = FinancialCategory::where('name', 'Doações')->sole();

    Livewire::test('pages::finance.categories')
        ->call('edit', $category->id)
        ->assertSet('entry_type', 'income')
        ->set('name', 'Doações e patrocínios')
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggleActive', $category->id)
        ->assertSee('Inativa');

    expect($category->refresh()->name)->toBe('Doações e patrocínios')
        ->and($category->is_active)->toBeFalse();
});

test('system categories and categories with entries hide the blocked actions', function () {
    actingAsRole('financeiro');
    $used = FinancialCategory::factory()->create(['name' => 'Categoria usada']);
    FinancialEntry::factory()->for($used, 'category')->create();
    $unused = FinancialCategory::factory()->create(['name' => 'Categoria livre']);
    $monthlyFees = FinancialCategory::monthlyFees();

    Livewire::test('pages::finance.categories')
        ->assertSeeHtml("wire:click=\"delete({$unused->id})\"")
        ->assertDontSeeHtml("wire:click=\"delete({$used->id})\"")
        ->assertSeeHtml("wire:click=\"toggleActive({$used->id})\"")
        ->assertDontSeeHtml("wire:click=\"delete({$monthlyFees->id})\"")
        ->assertDontSeeHtml("wire:click=\"toggleActive({$monthlyFees->id})\"");
});

test('deleting a category with entries shows the error and keeps it', function () {
    actingAsRole('financeiro');
    $category = FinancialCategory::factory()->create();
    FinancialEntry::factory()->for($category, 'category')->create();

    Livewire::test('pages::finance.categories')
        ->call('delete', $category->id)
        ->assertDispatched('notify', type: 'error');

    expect(FinancialCategory::whereKey($category->id)->exists())->toBeTrue();
});
