<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the status filter suspended lists only suspended holders', function () {
    actingAsRole('financeiro');
    Customer::factory()->withCard()->create(['name' => 'Maria Ativa']);
    Customer::factory()->withCard(CardStatus::Suspended)->create(['name' => 'José Suspenso']);
    $cancelled = Customer::factory()->create(['name' => 'Juliana Cancelada']);
    Card::factory()->cancelled()->for($cancelled)->create();

    Livewire::test('pages::customers.index')
        ->set('status', 'suspended')
        ->assertSee('José Suspenso')
        ->assertDontSee('Maria Ativa')
        ->assertDontSee('Juliana Cancelada')
        ->set('status', 'cancelled')
        ->assertSee('Juliana Cancelada')
        ->assertSee('Cancelado')
        ->assertDontSee('José Suspenso');
});

test('the plan filter lists only holders of that plan', function () {
    actingAsRole('financeiro');
    $familiar = Plan::factory()->create(['name' => 'Familiar']);
    $individual = Plan::factory()->create(['name' => 'Individual']);
    Customer::factory()->for($familiar)->withCard()->create(['name' => 'Maria Familiar']);
    Customer::factory()->for($individual)->withCard()->create(['name' => 'José Individual']);

    Livewire::test('pages::customers.index')
        ->set('plan', (string) $familiar->id)
        ->assertSee('Maria Familiar')
        ->assertDontSee('José Individual');
});

test('the search finds the holder by CPF, name or card number', function () {
    actingAsRole('financeiro');
    $maria = Customer::factory()->create(['name' => 'Maria Conceição', 'cpf' => '12345678909']);
    Card::factory()->active()->for($maria)->create(['number' => '000123']);
    Customer::factory()->withCard()->create(['name' => 'José Ribeiro', 'cpf' => '98765432100']);

    Livewire::test('pages::customers.index')
        ->set('search', '123.456.789-09')
        ->assertSee('Maria Conceição')
        ->assertDontSee('José Ribeiro')
        ->set('search', 'conceicao')
        ->assertSee('Maria Conceição')
        ->assertDontSee('José Ribeiro')
        ->set('search', '000123')
        ->assertSee('Maria Conceição')
        ->assertDontSee('José Ribeiro');
});

test('the listing shows the masked CPF, plan, active dependents and links to the sheet', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->create(['name' => 'Familiar']);
    $customer = Customer::factory()->for($plan)->create(['name' => 'Maria Conceição', 'cpf' => '12345678909']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Dependent::factory()->count(2)->for($customer)->create();
    Dependent::factory()->inactive()->for($customer)->create();

    Livewire::test('pages::customers.index')
        ->assertSee('***.456.789-**')
        ->assertDontSee('123.456.789-09')
        ->assertDontSee('12345678909')
        ->assertSee('000123')
        ->assertSee('Familiar')
        ->assertSee('2 dependentes')
        ->assertSee('Ativo')
        ->assertSee(route('customers.show', $customer))
        ->assertSee(route('sales.create'));
});

test('a holder without a current card shows the status of the last card', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create(['name' => 'Juliana Rocha']);
    Card::factory()->lost()->for($customer)->create(['assigned_at' => now()->subYear()]);
    Card::factory()->cancelled()->for($customer)->create(['assigned_at' => now()->subMonth()]);

    Livewire::test('pages::customers.index')
        ->assertSee('Juliana Rocha')
        ->assertSee('Cancelado')
        ->assertDontSee('Extraviado');
});

test('the filters persist in the query string', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->create(['name' => 'Familiar']);
    Customer::factory()->for($plan)->withCard(CardStatus::Suspended)->create(['name' => 'José Suspenso']);
    Customer::factory()->withCard()->create(['name' => 'Maria Ativa']);

    Livewire::withQueryParams(['status' => 'suspended', 'plan' => (string) $plan->id, 'q' => 'jose'])
        ->test('pages::customers.index')
        ->assertSet('status', 'suspended')
        ->assertSet('plan', (string) $plan->id)
        ->assertSet('search', 'jose')
        ->assertSee('José Suspenso')
        ->assertDontSee('Maria Ativa');

    $this->get(route('customers.index', ['status' => 'suspended']))
        ->assertOk()
        ->assertSee('José Suspenso')
        ->assertDontSee('Maria Ativa');
});

test('the listing runs a fixed number of queries regardless of the rows', function () {
    actingAsRole('financeiro');
    Customer::factory()->count(3)->withCard()->has(Dependent::factory()->count(2))->create();
    Livewire::test('pages::customers.index');

    DB::enableQueryLog();
    Livewire::test('pages::customers.index');
    $fewRows = count(DB::getQueryLog());

    Customer::factory()->count(6)->withCard()->has(Dependent::factory()->count(2))->create();

    DB::flushQueryLog();
    Livewire::test('pages::customers.index');

    expect(count(DB::getQueryLog()))->toBe($fewRows);
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    $this->get(route('customers.index'))->assertForbidden();
    Livewire::test('pages::customers.index')->assertForbidden();
});
