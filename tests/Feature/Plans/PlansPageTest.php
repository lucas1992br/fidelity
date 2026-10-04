<?php

use App\Enums\CardStatus;
use App\Models\Customer;
use App\Models\Plan;
use Livewire\Livewire;

test('the count shows only customers with a current card', function () {
    actingAsRole('admin');
    $plan = Plan::factory()->create(['name' => 'Familiar', 'price' => '129.90', 'max_dependents' => 4]);

    Customer::factory()->for($plan)->withCard(CardStatus::Active)->create();
    Customer::factory()->for($plan)->withCard(CardStatus::Suspended)->create();
    Customer::factory()->for($plan)->withCard(CardStatus::Cancelled)->create();

    Livewire::test('pages::plans')
        ->assertViewHas('plans', fn ($plans) => $plans->firstWhere('id', $plan->id)->active_customers_count === 2)
        ->assertSee('2 titulares ativos')
        ->assertSee('R$ 129,90')
        ->assertSee('Até 4 dependentes');
});

test('financeiro gets 403 on mount', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::plans')->assertForbidden();

    $this->withoutVite()->get(route('plans.index'))->assertForbidden();
});

test('the plans route renders the page for the admin', function () {
    actingAsRole('admin');
    Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);

    $this->withoutVite()->get(route('plans.index'))
        ->assertOk()
        ->assertSee('Novo plano')
        ->assertSee('Não inclui dependentes');
});

test('admin creates and edits a plan through the modal', function () {
    actingAsRole('admin');

    Livewire::test('pages::plans')
        ->call('create')
        ->set('name', 'Familiar Plus')
        ->set('price', '129,90')
        ->set('max_dependents', 7)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('isModalOpen', false)
        ->assertDispatched('notify');

    $plan = Plan::where('name', 'Familiar Plus')->firstOrFail();

    expect($plan->price)->toBe('129.90');

    Livewire::test('pages::plans')
        ->call('edit', $plan->id)
        ->assertSet('price', '129,90')
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Inativo');

    expect($plan->refresh()->is_active)->toBeFalse();
});

test('deleting a plan with customers shows the error and keeps it', function () {
    actingAsRole('admin');
    $plan = Plan::factory()->create();
    Customer::factory()->for($plan)->create();

    Livewire::test('pages::plans')
        ->call('edit', $plan->id)
        ->call('delete')
        ->assertDispatched('notify', type: 'error');

    expect($plan->refresh()->trashed())->toBeFalse();
});

test('financeiro cannot call the plan actions', function (string $method) {
    actingAsRole('admin');
    $plan = Plan::factory()->create();
    $component = Livewire::test('pages::plans')->call('edit', $plan->id);

    actingAsRole('financeiro');

    $component->call($method, ...($method === 'edit' ? [$plan->id] : []))->assertForbidden();

    expect($plan->refresh()->trashed())->toBeFalse();
})->with(['create', 'edit', 'save', 'delete']);
