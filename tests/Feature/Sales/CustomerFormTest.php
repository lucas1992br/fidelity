<?php

use App\Actions\Customers\AddDependent;
use App\Enums\Kinship;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use App\Support\Customers\CustomerRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The sale page, with the holder fields valid except for the overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function customerFormPage(array $overrides = []): Testable
{
    $plan = Plan::factory()->create();

    return Livewire::test('pages::sales.create')
        ->fill(collect([
            'name' => 'Maria Souza',
            'cpf' => '529.982.247-25',
            'birth_date' => '1985-06-15',
            'phone' => '(11) 98765-4321',
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'plan_id' => $plan->id,
            ...$overrides,
        ])->mapWithKeys(fn (mixed $value, string $key): array => ["customer.{$key}" => $value])->all());
}

test('due day comes pre-filled with the clinic default', function () {
    actingAsRole('atendente');
    settings(['default_due_day' => 15]);

    Livewire::test('pages::sales.create')->assertSet('customer.due_day', 15);
});

test('due day outside 1 to 28 is rejected', function (int $dueDay) {
    actingAsRole('atendente');

    customerFormPage(['due_day' => $dueDay])
        ->call('save')
        ->assertHasErrors(['customer.due_day' => 'between']);
})->with([0, 29]);

test('a masked CPF is normalized to digits and validated', function () {
    actingAsRole('atendente');

    customerFormPage()
        ->call('save')
        ->assertHasNoErrors('customer.cpf')
        ->assertSet('customer.cpf', '52998224725')
        ->assertSet('customer.phone', '11987654321')
        ->assertSet('customer.zip_code', '01310100');

    customerFormPage(['cpf' => '529.982.247-26'])
        ->call('save')
        ->assertHasErrors('customer.cpf')
        ->assertSee('O CPF informado é inválido.');
});

test('a CPF already used by another holder is rejected', function () {
    actingAsRole('atendente');
    Customer::factory()->create(['cpf' => '52998224725']);

    customerFormPage()
        ->call('save')
        ->assertHasErrors(['customer.cpf' => 'unique']);
});

test('an inactive plan is rejected', function () {
    actingAsRole('atendente');
    $inactive = Plan::factory()->inactive()->create();

    customerFormPage(['plan_id' => $inactive->id])
        ->call('save')
        ->assertHasErrors(['customer.plan_id' => 'exists'])
        ->assertSee('Selecione um plano ativo.');
});

test('UF must be a valid state and the messages are in Portuguese', function () {
    actingAsRole('atendente');

    customerFormPage(['state' => 'XX', 'name' => ''])
        ->call('save')
        ->assertHasErrors(['customer.state' => 'in', 'customer.name' => 'required'])
        ->assertSee('Informe uma UF válida.')
        ->assertSee('O campo nome é obrigatório.');
});

test('the dependent form validates the CPF and the uniqueness among dependents', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(4)->create();
    Dependent::factory()->create(['cpf' => '52998224725']);

    Livewire::test('pages::sales.create')
        ->set('customer.plan_id', $plan->id)
        ->set('dependent.name', 'Ana Souza')
        ->set('dependent.cpf', '529.982.247-25')
        ->set('dependent.birth_date', '2015-03-10')
        ->set('dependent.kinship_id', Kinship::Child->id())
        ->call('addDependent')
        ->assertHasErrors(['dependent.cpf' => 'unique'])
        ->set('dependent.cpf', '111.111.111-11')
        ->call('addDependent')
        ->assertHasErrors('dependent.cpf')
        ->set('dependent.cpf', '')
        ->call('addDependent')
        ->assertHasNoErrors()
        ->assertCount('dependents', 1)
        ->assertSet('dependents.0.cpf', null);
});

test('the forms ignore the record itself when editing', function () {
    actingAsRole('atendente');
    $customer = Customer::factory()->create();
    $dependent = Dependent::factory()->withCpf()->create();

    $component = Livewire::test('pages::sales.create');
    $component->instance()->customer->setCustomer($customer);
    $component->instance()->dependent->setDependent($dependent);

    $customerErrors = validator($component->instance()->customer->all(), CustomerRules::rules($customer->id))->errors();
    $dependentErrors = validator($component->instance()->dependent->all(), AddDependent::rules($dependent->id))->errors();

    expect($customerErrors->has('cpf'))->toBeFalse()
        ->and($dependentErrors->has('cpf'))->toBeFalse();
});
