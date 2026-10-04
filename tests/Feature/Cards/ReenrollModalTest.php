<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use Livewire\Livewire;

test('the button is missing for a holder with an active card and present for a cancelled one', function () {
    actingAsRole('financeiro');
    $active = Customer::factory()->withCard()->create();
    $cancelled = Customer::factory()->withCard(CardStatus::Cancelled)->create();
    $lost = Customer::factory()->create();
    Card::factory()->lost()->for($lost)->create();

    Livewire::test('pages::customers.show', ['customer' => $active])->assertDontSee('Reingressar');
    Livewire::test('pages::customers.show', ['customer' => $lost])->assertDontSee('Reingressar');
    Livewire::test('pages::customers.show', ['customer' => $cancelled])
        ->assertSee('Reingressar')
        ->assertSeeLivewire('cards.reenroll-modal');
});

test('confirming re-enrolls the holder', function () {
    $user = actingAsRole('financeiro');
    $plan = Plan::factory()->create(['name' => 'Individual', 'price' => '49.90', 'max_dependents' => 0]);
    $family = Plan::factory()->create(['name' => 'Familiar', 'price' => '89.90', 'max_dependents' => 4]);
    Plan::factory()->inactive()->create(['name' => 'Plano Antigo']);
    $customer = Customer::factory()->for($plan)->create(['due_day' => 12]);
    Card::factory()->cancelled()->for($customer)->create(['number' => '000123']);
    Card::factory()->inStock()->create(['number' => '000456']);

    Livewire::test('cards.reenroll-modal', ['customerId' => $customer->id])
        ->dispatch('open-reenroll')
        ->assertSet('isOpen', true)
        ->assertSet('dueDay', '12')
        ->assertSet('planId', (string) $plan->id)
        ->assertSee('Familiar · R$ 89,90')
        ->assertDontSee('Plano Antigo')
        ->set('number', '000456')
        ->assertSee('Disponível')
        ->set('planId', (string) $family->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('notify', title: 'Titular reingressou')
        ->assertDispatched('customer-card-updated');

    $customer->refresh();

    expect($customer->currentCard->number)->toBe('000456')
        ->and($customer->currentCard->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($customer->plan_id)->toBe($family->id)
        ->and($customer->planChanges()->sole()->reason)->toBe('Reingresso')
        ->and($customer->planChanges()->sole()->user_id)->toBe($user->id)
        ->and($customer->invoices()->sole()->amount)->toBe('89.90');
});

test('the modal warns and blocks when the dependents do not fit the plan', function () {
    actingAsRole('financeiro');
    $family = Plan::factory()->create(['name' => 'Familiar', 'max_dependents' => 4]);
    $single = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    $customer = Customer::factory()->for($family)->create();
    Card::factory()->cancelled()->for($customer)->create();
    Dependent::factory()->count(2)->for($customer)->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    $component = Livewire::test('cards.reenroll-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('number', '000456')
        ->set('planId', (string) $single->id)
        ->assertSee('O plano Individual permite até 0 dependentes; inative 2 dependente(s) antes do reingresso.')
        ->call('confirm')
        ->assertHasErrors(['planId' => 'O plano Individual permite até 0 dependentes; inative 2 dependente(s) antes do reingresso.']);

    expect($customer->refresh()->currentCard)->toBeNull();

    $component->set('planId', (string) $family->id)->assertDontSee('inative');
});

test('a user without cards.manage cannot open or confirm', function (string $method) {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard(CardStatus::Cancelled)->create();
    Card::factory()->inStock()->create(['number' => '000456']);
    $component = Livewire::test('cards.reenroll-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('number', '000456');

    actingAsRole('atendente');

    $component->call($method)->assertForbidden();

    expect($customer->refresh()->currentCard)->toBeNull();
})->with(['open', 'confirm']);
