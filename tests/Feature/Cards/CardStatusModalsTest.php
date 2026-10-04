<?php

use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('the summary buttons follow the card status', function (CardStatus $status, array $visible, array $hidden) {
    actingAsRole('admin');
    $customer = Customer::factory()->withCard($status)->create();

    $component = Livewire::test('pages::customers.show', ['customer' => $customer]);

    foreach ($visible as $label) {
        $component->assertSee($label);
    }

    foreach ($hidden as $label) {
        $component->assertDontSee($label);
    }
})->with([
    'active' => [CardStatus::Active, ['Suspender', 'Cancelar cartão'], ['Reativar']],
    'suspended' => [CardStatus::Suspended, ['Reativar', 'Cancelar cartão'], ['Suspender']],
    'cancelled' => [CardStatus::Cancelled, ['Reingressar'], ['Reativar', 'Suspender', 'Cancelar cartão']],
]);

test('the "Reativar" button is missing for a cancelled card for every profile', function (string $role) {
    actingAsRole($role);
    $customer = Customer::factory()->withCard(CardStatus::Cancelled)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])->assertDontSee('Reativar');
})->with(['admin', 'financeiro']);

test('suspending and reactivating through the modal require the reason', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create(['number' => '000123']);

    $component = Livewire::test('cards.card-status-modal', ['customerId' => $customer->id])
        ->dispatch('open-suspend-card')
        ->assertSet('action', 'suspend')
        ->assertSee('Suspender cartão')
        ->call('confirm')
        ->assertHasErrors('reason')
        ->set('reason', 'Viagem prolongada')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('action', null)
        ->assertDispatched('notify', title: 'Cartão suspenso')
        ->assertDispatched('customer-card-updated');

    expect($card->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue();

    $component->dispatch('open-reactivate-card')
        ->assertSet('action', 'reactivate')
        ->assertSee('Reativar cartão')
        ->set('reason', 'Retorno da viagem')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($card->statusLogs()->pluck('reason')->all())->toBe(['Viagem prolongada', 'Retorno da viagem'])
        ->and($card->statusLogs()->pluck('user_id')->unique()->all())->toBe([$user->id]);
});

test('the cancel modal shows the pending count and total and keeps the invoices overdue with "manter"', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create();
    $first = Invoice::factory()->for($customer)->forMonth('2026-07')->create([
        'amount' => '89.90',
        'invoice_status_id' => InvoiceStatus::Overdue->id(),
        'due_date' => today()->subDays(60)->toDateString(),
    ]);
    $second = Invoice::factory()->for($customer)->forMonth('2026-08')->create([
        'amount' => '89.90',
        'invoice_status_id' => InvoiceStatus::Overdue->id(),
        'due_date' => today()->subDays(30)->toDateString(),
    ]);
    Invoice::factory()->for($customer)->forMonth('2026-06')->paid()->create(['amount' => '89.90']);

    Livewire::test('cards.cancel-card-modal', ['customerId' => $customer->id])
        ->dispatch('open-cancel-card')
        ->assertSee('O cancelamento é definitivo')
        ->assertSee('Cancelar as 2 faturas pendentes (R$ 179,80)')
        ->assertSee('Manter as faturas (continuam cobráveis)')
        ->set('reason', 'Mudou de cidade')
        ->call('confirm')
        ->assertHasErrors('pendingInvoices')
        ->set('pendingInvoices', 'keep')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('customer-card-updated');

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($first->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($second->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue();
});

test('the cancel option of the modal cancels the pending invoices', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $card = Card::factory()->suspended()->for($customer)->create();
    $invoice = Invoice::factory()->for($customer)->overdue(40)->create();

    Livewire::test('cards.cancel-card-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('reason', 'Desistência')
        ->set('pendingInvoices', 'cancel')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Cancelled))->toBeTrue();
});

test('financeiro without cards.manage gets 403 opening and confirming', function (string $component, string $event, array $state) {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create();
    $modal = Livewire::test($component, ['customerId' => $customer->id])->dispatch($event);

    foreach ($state as $property => $value) {
        $modal->set($property, $value);
    }

    Role::findByName('financeiro')->revokePermissionTo('cards.manage');
    auth()->user()->refresh();

    $modal->call('confirm')->assertForbidden();
    Livewire::test($component, ['customerId' => $customer->id])->dispatch($event)->assertForbidden();

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
})->with([
    'suspend' => ['cards.card-status-modal', 'open-suspend-card', ['reason' => 'Motivo']],
    'cancel' => ['cards.cancel-card-modal', 'open-cancel-card', ['reason' => 'Motivo', 'pendingInvoices' => 'keep']],
]);

test('the sheet mounts the status modals only for cards.manage', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSeeLivewire('cards.card-status-modal')
        ->assertSeeLivewire('cards.cancel-card-modal');
});
