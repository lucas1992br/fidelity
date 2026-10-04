<?php

use App\Actions\Customers\AddDependent;
use App\Enums\Kinship;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use App\Support\Format;
use Database\Factories\CustomerFactory;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dependentInput(array $overrides = []): array
{
    return [
        'name' => 'Ana Souza',
        'cpf' => null,
        'birth_date' => '2015-03-10',
        'kinship_id' => Kinship::Child->id(),
        ...$overrides,
    ];
}

test('the Familiar plan accepts the 4th dependent and rejects the 5th', function () {
    $plan = Plan::factory()->withDependents(4)->create(['name' => 'Familiar']);
    $customer = Customer::factory()->for($plan)->create();
    Dependent::factory()->count(3)->for($customer)->create();

    $fourth = app(AddDependent::class)->handle($customer, dependentInput());

    expect($fourth->sequence)->toBe(4);

    expect(fn () => app(AddDependent::class)->handle($customer, dependentInput()))
        ->toThrow(ValidationException::class, 'O plano Familiar permite até 4 dependentes.');

    expect($customer->dependents()->count())->toBe(4);
});

test('the Individual plan rejects any dependent', function () {
    $plan = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    $customer = Customer::factory()->for($plan)->create();

    expect(fn () => app(AddDependent::class)->handle($customer, dependentInput()))
        ->toThrow(ValidationException::class, 'O plano Individual não inclui dependentes. Troque de plano para incluir.');

    expect($customer->dependents()->count())->toBe(0);
});

test('the sequence is never reused after a dependent is deactivated', function () {
    $plan = Plan::factory()->withDependents(3)->create();
    $customer = Customer::factory()->for($plan)->create();
    [, $second] = Dependent::factory()->count(3)->for($customer)->create()->all();

    $second->update(['is_active' => false]);

    $new = app(AddDependent::class)->handle($customer, dependentInput());

    expect($new->sequence)->toBe(4)
        ->and($customer->activeDependents()->count())->toBe(3);
});

test('soft-deleted dependents still count for the sequence', function () {
    $plan = Plan::factory()->withDependents(4)->create();
    $customer = Customer::factory()->for($plan)->create();
    Dependent::factory()->count(2)->for($customer)->create()->last()->delete();

    expect(app(AddDependent::class)->handle($customer, dependentInput())->sequence)->toBe(3);
});

test('a CPF already used by another dependent is rejected, even soft-deleted', function () {
    $plan = Plan::factory()->withDependents(4)->create();
    $customer = Customer::factory()->for($plan)->create();
    $cpf = CustomerFactory::cpf();
    Dependent::factory()->create(['cpf' => $cpf])->delete();

    expect(fn () => app(AddDependent::class)->handle($customer, dependentInput(['cpf' => Format::cpf($cpf)])))
        ->toThrow(ValidationException::class, 'CPF');

    expect($customer->dependents()->count())->toBe(0);
});

test('a masked CPF is stored with digits only and an invalid one is rejected', function () {
    $plan = Plan::factory()->withDependents(4)->create();
    $customer = Customer::factory()->for($plan)->create();

    $dependent = app(AddDependent::class)->handle($customer, dependentInput(['cpf' => '529.982.247-25']));

    expect($dependent->cpf)->toBe('52998224725');

    expect(fn () => app(AddDependent::class)->handle($customer, dependentInput(['cpf' => '529.982.247-26'])))
        ->toThrow(ValidationException::class, 'O CPF informado é inválido.');
});
