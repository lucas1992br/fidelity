<?php

use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use Livewire\Livewire;

test('an inactive plan does not appear in the options', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $active = Plan::factory()->withDependents(2)->create(['name' => 'Plano Casal', 'price' => '69.90']);
    $inactive = Plan::factory()->inactive()->create(['name' => 'Plano Antigo']);

    $modal = Livewire::test('customers.change-plan-modal', ['customerId' => $customer->id])
        ->call('open')
        ->assertSee('Plano Casal')
        ->assertSee('R$ 69,90')
        ->assertSee('Até 2 dependentes')
        ->assertDontSee('Plano Antigo')
        ->assertSee('O novo valor vale a partir da próxima mensalidade.');

    expect($modal->get('plans')->pluck('id'))->toContain($active->id)->not->toContain($inactive->id);

    $modal->set('planId', (string) $inactive->id)
        ->call('confirm')
        ->assertHasErrors(['planId' => 'Selecione um plano ativo.']);
});

test('the dependents block shows before confirming', function () {
    actingAsRole('financeiro');
    $familiar = Plan::factory()->withDependents(4)->create(['name' => 'Familiar']);
    $individual = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    $customer = Customer::factory()->withCard()->for($familiar)->create();
    Dependent::factory()->count(3)->for($customer)->create();

    Livewire::test('customers.change-plan-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('planId', (string) $individual->id)
        ->assertSee('Inative 3 dependente(s) para trocar para o plano Individual.')
        ->assertSet('dependentsBlockMessage', 'Inative 3 dependente(s) para trocar para o plano Individual.');
});

test('confirming changes the plan and refreshes the sheet', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $plan = Plan::factory()->create(['name' => 'Plano Novo']);

    Livewire::test('customers.change-plan-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('planId', (string) $plan->id)
        ->set('reason', 'Pedido do titular')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('customer-card-updated');

    expect($customer->refresh()->plan_id)->toBe($plan->id)
        ->and($customer->planChanges()->sole()->only('reason', 'user_id'))->toBe(['reason' => 'Pedido do titular', 'user_id' => $user->id]);
});

test('atendente gets 403 on confirm', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $planId = $customer->plan_id;
    $plan = Plan::factory()->create();
    $modal = Livewire::test('customers.change-plan-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('planId', (string) $plan->id);

    actingAsRole('atendente');

    $modal->call('confirm')->assertForbidden();

    expect($customer->refresh()->plan_id)->toBe($planId)
        ->and($customer->planChanges()->count())->toBe(0);
});

test('the sheet mounts the modal for cards.manage', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSeeLivewire('customers.change-plan-modal');
});
