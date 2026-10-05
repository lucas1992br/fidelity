<?php

use App\Enums\EntryStatus;
use App\Enums\PaymentMethod;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00');
});

test('the categories follow the chosen type and inactive ones are hidden', function () {
    actingAsRole('financeiro');
    $inactive = FinancialCategory::factory()->expense()->inactive()->create(['name' => 'Categoria antiga']);

    Livewire::test('finance.entry-modal')
        ->dispatch('open-financial-entry')
        ->assertSet('isOpen', true)
        ->assertSee('Aluguel')
        ->assertDontSee('Outras receitas')
        ->assertDontSee($inactive->name)
        ->set('form.financial_category_id', FinancialCategory::where('name', 'Aluguel')->value('id'))
        ->call('chooseType', 'income')
        ->assertSet('form.financial_category_id', null)
        ->assertSee('Outras receitas')
        ->assertDontSee('Aluguel');
});

test('"Já pago?" reveals the date and method and the entry is created paid', function () {
    $user = actingAsRole('financeiro');
    $category = FinancialCategory::where('name', 'Materiais')->sole();

    Livewire::test('finance.entry-modal')
        ->dispatch('open-financial-entry')
        ->assertDontSeeHtml('data-slot="paid-fields"')
        ->set('form.financial_category_id', $category->id)
        ->set('form.description', 'Luvas e máscaras')
        ->set('form.amount', '1.234,50')
        ->set('form.is_paid', true)
        ->assertSeeHtml('data-slot="paid-fields"')
        ->set('form.payment_method', 'card')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('financial-entry-saved')
        ->assertDispatched('notify');

    $entry = FinancialEntry::where('description', 'Luvas e máscaras')->sole();

    expect($entry->amount)->toBe('1234.50')
        ->and($entry->entry_status_id)->toBe(EntryStatus::Paid->id())
        ->and($entry->paid_at->toDateString())->toBe('2026-10-05')
        ->and($entry->payment_method_id)->toBe(PaymentMethod::Card->id())
        ->and($entry->user_id)->toBe($user->id);
});

test('a category of the other type shows the validation error on the form', function () {
    actingAsRole('financeiro');

    Livewire::test('finance.entry-modal')
        ->dispatch('open-financial-entry')
        ->set('form.financial_category_id', FinancialCategory::monthlyFees()->id)
        ->set('form.description', 'Conta de água')
        ->set('form.amount', '120,00')
        ->call('save')
        ->assertHasErrors(['form.financial_category_id']);

    expect(FinancialEntry::count())->toBe(0);
});

test('editing loads the manual entry and saves the changes', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->pending()->create(['amount' => '300.00']);

    Livewire::test('finance.entry-modal')
        ->dispatch('open-financial-entry', entry: $entry->id)
        ->assertSet('form.entryId', $entry->id)
        ->assertSet('form.amount', '300,00')
        ->set('form.amount', '350,00')
        ->call('save')
        ->assertHasNoErrors();

    expect($entry->refresh()->amount)->toBe('350.00');
});

test('opening an automatic entry for editing returns 403', function () {
    actingAsRole('admin');
    $entry = FinancialEntry::factory()->automatic()->create();

    Livewire::test('finance.entry-modal')
        ->dispatch('open-financial-entry', entry: $entry->id)
        ->assertForbidden();
});

test('a user with only finance.view cannot open or save the modals', function () {
    actingAsFinanceViewer();
    $entry = FinancialEntry::factory()->pending()->create();

    Livewire::test('finance.entry-modal')->dispatch('open-financial-entry')->assertForbidden();
    Livewire::test('finance.entry-modal')->call('save')->assertForbidden();
    Livewire::test('finance.settle-modal')->dispatch('open-settle-entry', entry: $entry->id)->assertForbidden();
    Livewire::test('finance.settle-modal')->set('entryId', $entry->id)->call('confirm')->assertForbidden();

    expect($entry->refresh()->entry_status_id)->toBe(EntryStatus::Pending->id());
});

test('the settle modal marks the pending entry as paid', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->pending()->create();

    Livewire::test('finance.settle-modal')
        ->dispatch('open-settle-entry', entry: $entry->id)
        ->assertSet('paidAt', '05/10/2026')
        ->assertSee('Confirmar baixa')
        ->set('paidAt', '04/10/2026')
        ->set('method', 'boleto')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('entryId', null)
        ->assertDispatched('financial-entry-saved');

    expect($entry->refresh()->entry_status_id)->toBe(EntryStatus::Paid->id())
        ->and($entry->paid_at->toDateString())->toBe('2026-10-04')
        ->and($entry->payment_method_id)->toBe(PaymentMethod::Boleto->id());
});

test('the settle modal refuses an entry already paid', function () {
    actingAsRole('financeiro');
    $entry = FinancialEntry::factory()->paid()->create();

    Livewire::test('finance.settle-modal')
        ->dispatch('open-settle-entry', entry: $entry->id)
        ->assertSet('entryId', null)
        ->assertDispatched('notify', type: 'error', title: 'Este lançamento já foi baixado ou cancelado.');
});
