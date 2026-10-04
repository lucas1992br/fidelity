<?php

use App\Actions\Customers\AddDependent;
use App\Actions\Customers\DeactivateDependent;
use App\Actions\Customers\UpdateDependent;
use App\Enums\Kinship;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use App\Support\Format;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('deactivating frees a slot and the next dependent gets a new sequence', function () {
    $user = actingAsRole('financeiro');
    $plan = Plan::factory()->withDependents(2)->create(['name' => 'Duo']);
    $customer = Customer::factory()->for($plan)->create();
    [$first, $second] = Dependent::factory()->count(2)->for($customer)->create()->all();
    $input = ['name' => 'Ana Souza', 'birth_date' => '2015-03-10', 'kinship_id' => Kinship::Child->id()];

    expect(fn () => app(AddDependent::class)->handle($customer, $input))
        ->toThrow(ValidationException::class, 'O plano Duo permite até 2 dependentes.');

    app(DeactivateDependent::class)->handle($user, $second);

    $new = app(AddDependent::class)->handle($customer, $input);

    expect($second->refresh()->is_active)->toBeFalse()
        ->and($second->sequence)->toBe(2)
        ->and($new->sequence)->toBe(3)
        ->and($customer->activeDependents()->pluck('id')->all())->toBe([$first->id, $new->id]);
});

test('the identification of a deactivated dependent stays the same', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $dependent = Dependent::factory()->for($customer)->create();
    $identification = $dependent->identification;

    app(DeactivateDependent::class)->handle($user, $dependent);

    expect($identification)->not->toBeNull()
        ->and($dependent->refresh()->identification)->toBe($identification)
        ->and($dependent->trashed())->toBeFalse();
});

test('editing saves the data normalized and keeps the dependent own CPF valid', function () {
    $user = actingAsRole('financeiro');
    $dependent = Dependent::factory()->withCpf()->create();
    $cpf = $dependent->cpf;

    app(UpdateDependent::class)->handle($user, $dependent, [
        'name' => '  Bruno Lima ',
        'cpf' => Format::cpf($cpf),
        'birth_date' => '2010-01-20',
        'kinship_id' => Kinship::Spouse->id(),
    ]);

    $dependent->refresh();

    expect($dependent->name)->toBe('Bruno Lima')
        ->and($dependent->cpf)->toBe($cpf)
        ->and($dependent->birth_date->toDateString())->toBe('2010-01-20')
        ->and($dependent->kinship_id)->toBe(Kinship::Spouse->id());
});

test('editing with the CPF of another dependent fails', function () {
    $user = actingAsRole('financeiro');
    $other = Dependent::factory()->create(['cpf' => CustomerFactory::cpf()]);
    $dependent = Dependent::factory()->create(['name' => 'Ana Souza']);

    expect(fn () => app(UpdateDependent::class)->handle($user, $dependent, [
        'name' => 'Ana Souza',
        'cpf' => $other->cpf,
        'birth_date' => '2015-03-10',
        'kinship_id' => Kinship::Child->id(),
    ]))->toThrow(ValidationException::class, 'CPF');

    expect($dependent->refresh()->cpf)->toBeNull();
});

test('both actions require customers.update', function () {
    $user = actingAsRole('atendente');
    $dependent = Dependent::factory()->create(['name' => 'Ana Souza']);

    expect(fn () => app(DeactivateDependent::class)->handle($user, $dependent))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateDependent::class)->handle($user, $dependent, ['name' => 'Outro']))
        ->toThrow(AuthorizationException::class);

    expect($dependent->refresh()->is_active)->toBeTrue()
        ->and($dependent->name)->toBe('Ana Souza');
});
