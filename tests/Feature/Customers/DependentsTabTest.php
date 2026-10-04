<?php

use App\Enums\Kinship;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use Livewire\Livewire;

test('adding through the component respects the plan limit', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->withDependents(2)->create(['name' => 'Duo']);
    $customer = Customer::factory()->withCard()->for($plan)->create();
    Dependent::factory()->for($customer)->create();

    $modal = Livewire::test('customers.dependent-modal', ['customerId' => $customer->id]);

    $fill = fn (string $name) => $modal->call('open')
        ->set('form.name', $name)
        ->set('form.birth_date', '2015-03-10')
        ->set('form.kinship_id', Kinship::Child->id())
        ->call('save');

    $fill('Ana Souza')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('customer-dependents-updated');

    $fill('Bruno Souza')
        ->assertHasErrors(['dependents' => 'O plano Duo permite até 2 dependentes.'])
        ->assertSet('isOpen', true);

    expect($customer->dependents()->pluck('name')->last())->toBe('Ana Souza')
        ->and($customer->dependents()->count())->toBe(2);
});

test('editing through the component keeps the sequence', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $dependent = Dependent::factory()->for($customer)->create();

    Livewire::test('customers.dependent-modal', ['customerId' => $customer->id])
        ->call('open', $dependent->id)
        ->assertSet('form.name', $dependent->name)
        ->set('form.name', 'Nome Corrigido')
        ->call('save')
        ->assertHasNoErrors();

    expect($dependent->refresh()->name)->toBe('Nome Corrigido')
        ->and($dependent->sequence)->toBe(1);
});

test('atendente cannot call save', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->withDependents(2)->create();
    $customer = Customer::factory()->for($plan)->create();
    $modal = Livewire::test('customers.dependent-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('form.name', 'Ana Souza')
        ->set('form.birth_date', '2015-03-10')
        ->set('form.kinship_id', Kinship::Child->id());

    actingAsRole('atendente');

    $modal->call('save')->assertForbidden();

    Livewire::test('customers.dependent-modal', ['customerId' => $customer->id])
        ->call('open')
        ->assertForbidden();

    expect($customer->dependents()->count())->toBe(0);
});

test('the add button is disabled with the plan message when the limit is reached', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->withDependents(1)->create(['name' => 'Duo']);
    $customer = Customer::factory()->withCard()->for($plan)->create();
    Dependent::factory()->for($customer)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSeeLivewire('customers.dependent-modal')
        ->set('tab', 'dependentes')
        ->assertSet('addDependentBlockedMessage', 'O plano Duo permite até 1 dependentes.')
        ->assertSee('O plano Duo permite até 1 dependentes.');
});

test('a plan without dependents explains that the plan must be changed', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    $customer = Customer::factory()->withCard()->for($plan)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->set('tab', 'dependentes')
        ->assertSee('O plano Individual não inclui dependentes. Troque de plano para incluir.');
});

test('deactivating from the tab frees the slot', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->withDependents(1)->create();
    $customer = Customer::factory()->withCard()->for($plan)->create();
    $dependent = Dependent::factory()->for($customer)->create();

    $sheet = Livewire::test('pages::customers.show', ['customer' => $customer])
        ->set('tab', 'dependentes')
        ->call('deactivateDependent', $dependent->id)
        ->assertDispatched('notify', title: 'Dependente inativado');

    expect($dependent->refresh()->is_active)->toBeFalse()
        ->and($sheet->get('addDependentBlockedMessage'))->toBeNull();
});

test('atendente gets 403 when deactivating', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $dependent = Dependent::factory()->for($customer)->create();
    $sheet = Livewire::test('pages::customers.show', ['customer' => $customer]);

    actingAsRole('atendente');

    $sheet->call('deactivateDependent', $dependent->id)->assertForbidden();

    expect($dependent->refresh()->is_active)->toBeTrue();
});
