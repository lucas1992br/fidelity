<?php

use App\Actions\Customers\CreateCustomer;
use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Kinship;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanChange;
use Database\Factories\CustomerFactory;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saleInput(Plan $plan, array $overrides = []): array
{
    return [
        'card_number' => '000123',
        'name' => 'Maria Souza',
        'cpf' => '529.982.247-25',
        'birth_date' => '1985-06-15',
        'phone' => '(11) 98765-4321',
        'phone_secondary' => '',
        'email' => 'maria@example.com',
        'zip_code' => '01310-100',
        'street' => 'Avenida Paulista',
        'number' => '1000',
        'complement' => '',
        'district' => 'Bela Vista',
        'city' => 'São Paulo',
        'state' => 'sp',
        'plan_id' => $plan->id,
        'due_day' => 10,
        'dependents' => [],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function saleDependent(string $name): array
{
    return ['name' => $name, 'cpf' => null, 'birth_date' => '2015-03-10', 'kinship_id' => Kinship::Child->id()];
}

/**
 * Assert the failed sale left nothing behind.
 */
function assertNothingPersisted(): void
{
    expect(Customer::withTrashed()->count())->toBe(0)
        ->and(PlanChange::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
}

test('a complete sale creates the holder, card, sign-up, dependents and the first invoice', function () {
    $this->travelTo('2026-10-05');
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(4)->create(['name' => 'Familiar', 'price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan, [
        'dependents' => [saleDependent('Ana Souza'), saleDependent('Pedro Souza')],
    ]), $user);

    $customer->refresh();
    $card = $customer->currentCard;
    $planChange = $customer->planChanges()->sole();
    $invoice = $customer->invoices()->sole();

    expect($customer->cpf)->toBe('52998224725')
        ->and($customer->phone)->toBe('11987654321')
        ->and($customer->phone_secondary)->toBeNull()
        ->and($customer->zip_code)->toBe('01310100')
        ->and($customer->state)->toBe('SP')
        ->and($customer->joined_at->toDateString())->toBe('2026-10-05')
        ->and($card->number)->toBe('000123')
        ->and($card->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($card->assigned_at->toDateString())->toBe('2026-10-05')
        ->and($card->statusLogs()->count())->toBe(1)
        ->and($planChange->from_plan_id)->toBeNull()
        ->and($planChange->to_plan_id)->toBe($plan->id)
        ->and($planChange->to_price)->toBe('89.90')
        ->and($planChange->reason)->toBe('Adesão')
        ->and($planChange->user_id)->toBe($user->id)
        ->and($customer->dependents->pluck('sequence')->all())->toBe([1, 2])
        ->and($invoice->hasStatus(InvoiceStatus::Open))->toBeTrue()
        ->and($invoice->amount)->toBe('89.90')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-10')
        ->and($invoice->reference_month->toDateString())->toBe('2026-10-01');
});

test('an already linked card persists nothing', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create();
    Card::factory()->active()->create(['number' => '000123']);
    $existingCustomers = Customer::count();

    expect(fn () => app(CreateCustomer::class)->handle(saleInput($plan), $user))
        ->toThrow(ValidationException::class, 'Já vinculado');

    expect(Customer::count())->toBe($existingCustomers)
        ->and(Customer::where('cpf', '52998224725')->exists())->toBeFalse()
        ->and(PlanChange::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

test('an inactive plan is rejected', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->inactive()->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    expect(fn () => app(CreateCustomer::class)->handle(saleInput($plan), $user))
        ->toThrow(ValidationException::class, 'Selecione um plano ativo.');

    assertNothingPersisted();
});

test('a CPF already used by another holder is rejected', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create();
    $cpf = CustomerFactory::cpf();
    Customer::factory()->create(['cpf' => $cpf]);
    Card::factory()->inStock()->create(['number' => '000123']);

    expect(fn () => app(CreateCustomer::class)->handle(saleInput($plan, ['cpf' => $cpf]), $user))
        ->toThrow(ValidationException::class, 'Este CPF já pertence a outro titular.');

    expect(Customer::count())->toBe(1)
        ->and(Card::where('number', '000123')->sole()->hasStatus(CardStatus::InStock))->toBeTrue();
});

test('dependents above the plan limit roll back the whole sale', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(1)->create(['name' => 'Duo']);
    Card::factory()->inStock()->create(['number' => '000123']);

    expect(fn () => app(CreateCustomer::class)->handle(saleInput($plan, [
        'dependents' => [saleDependent('Ana Souza'), saleDependent('Pedro Souza')],
    ]), $user))->toThrow(ValidationException::class, 'O plano Duo permite até 1 dependentes.');

    assertNothingPersisted();
    expect(Card::where('number', '000123')->sole()->hasStatus(CardStatus::InStock))->toBeTrue();
});

test('an invalid dependent rolls back the sale with the error under its index', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(4)->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    try {
        app(CreateCustomer::class)->handle(saleInput($plan, [
            'dependents' => [saleDependent('Ana Souza'), [...saleDependent('Pedro Souza'), 'cpf' => '111.111.111-11']],
        ]), $user);

        $this->fail('The sale should have been rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('dependents.1.cpf');
    }

    assertNothingPersisted();
    expect(Card::where('number', '000123')->sole()->hasStatus(CardStatus::InStock))->toBeTrue();
});
