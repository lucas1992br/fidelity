<?php

use App\Actions\Customers\CreateCustomer;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Kinship;
use App\Models\Card;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
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

test('the typed monthly amount replaces the plan price on the sign-up and the first invoice', function () {
    $this->travelTo('2026-10-05');
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create(['price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan, ['monthly_amount' => '1.050,00']), $user);

    expect($customer->refresh()->monthly_amount)->toBe('1050.00')
        ->and($customer->planChanges()->sole()->to_price)->toBe('1050.00')
        ->and($customer->invoices()->sole()->amount)->toBe('1050.00');
});

test('a sale without a monthly amount bills the plan price', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create(['price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan), $user);

    expect($customer->refresh()->monthly_amount)->toBe('89.90');
});

test('a company employee follows the company due day and joins its bill', function () {
    $this->travelTo('2026-10-05');
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create();
    $company = Company::factory()->create(['due_day' => 20]);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan, ['company_id' => $company->id, 'due_day' => 5]), $user);

    $invoice = $customer->invoices()->sole();

    expect($customer->refresh()->company_id)->toBe($company->id)
        ->and($customer->due_day)->toBe(20)
        ->and($invoice->company_id)->toBe($company->id)
        ->and($invoice->due_date->toDateString())->toBe('2026-10-20');
});

test('an inactive company is rejected and persists nothing', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->create();
    $company = Company::factory()->inactive()->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    expect(fn () => app(CreateCustomer::class)->handle(saleInput($plan, ['company_id' => $company->id]), $user))
        ->toThrow(ValidationException::class, 'Selecione uma empresa ativa.');

    assertNothingPersisted();
});

test('the plan enrollment fee becomes a pending income and is recorded on the sign-up with the commission percentage', function () {
    $this->travelTo('2026-10-05');
    settings(['enrollment_commission_percent' => 40.0]);
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withEnrollmentFee('150.00')->create(['price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan), $user);

    $planChange = $customer->planChanges()->sole();
    $entry = FinancialEntry::sole();

    expect($planChange->enrollment_fee)->toBe('150.00')
        ->and($planChange->commission_percent)->toBe('40.00')
        ->and($entry->financial_category_id)->toBe(FinancialCategory::enrollmentFees()->id)
        ->and($entry->description)->toBe('Taxa de adesão - Maria Souza')
        ->and($entry->amount)->toBe('150.00')
        ->and($entry->due_date->toDateString())->toBe('2026-10-05')
        ->and($entry->entry_status_id)->toBe(EntryStatus::Pending->id())
        ->and($entry->supplier_or_payer)->toBe('Maria Souza')
        ->and($entry->user_id)->toBe($user->id)
        ->and($customer->invoices()->sole()->amount)->toBe('89.90');
});

test('the typed enrollment fee replaces the plan fee', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withEnrollmentFee('150.00')->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan, ['enrollment_fee' => '99,90']), $user);

    expect($customer->planChanges()->sole()->enrollment_fee)->toBe('99.90')
        ->and(FinancialEntry::sole()->amount)->toBe('99.90');
});

test('a sale without enrollment fee books no income', function () {
    $user = actingAsRole('atendente');
    $plan = Plan::factory()->withEnrollmentFee('150.00')->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput($plan, ['enrollment_fee' => '0,00']), $user);

    expect($customer->planChanges()->sole()->enrollment_fee)->toBe('0.00')
        ->and(FinancialEntry::count())->toBe(0);
});

test('an employee sale has no monthly amount of its own and the invoice joins the company fixed bill', function () {
    $this->travelTo('2026-10-05');
    $user = actingAsRole('atendente');
    $company = Company::factory()->create(['monthly_amount' => '1200.00', 'due_day' => 20]);
    Card::factory()->inStock()->create(['number' => '000123']);

    $customer = app(CreateCustomer::class)->handle(saleInput(Plan::factory()->create(), ['company_id' => $company->id, 'monthly_amount' => '89,90']), $user);

    expect($customer->refresh()->monthly_amount)->toBe('0.00')
        ->and($customer->invoices()->sole()->amount)->toBe('0.00')
        ->and($company->bills()->sole()->amount)->toBe('1200.00');
});
