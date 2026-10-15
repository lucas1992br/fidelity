<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Customers\ChangePlan;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('Familiar with 3 dependents to Individual is blocked informing 3', function () {
    $user = actingAsRole('financeiro');
    $familiar = Plan::factory()->withDependents(4)->create(['name' => 'Familiar']);
    $individual = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    $customer = Customer::factory()->withCard()->for($familiar)->create();
    Dependent::factory()->count(3)->for($customer)->create();
    Dependent::factory()->inactive()->for($customer)->create();

    expect(fn () => app(ChangePlan::class)->handle($user, $customer, ['plan_id' => $individual->id]))
        ->toThrow(ValidationException::class, 'Inative 3 dependente(s) para trocar para o plano Individual.');

    expect($customer->refresh()->plan_id)->toBe($familiar->id)
        ->and($customer->planChanges()->count())->toBe(0);
});

test('a valid change records the prices from and to and keeps the open invoice with the old amount', function () {
    $user = actingAsRole('financeiro');
    $individual = Plan::factory()->create(['name' => 'Individual', 'price' => '49.90']);
    $familiar = Plan::factory()->withDependents(4)->create(['name' => 'Familiar', 'price' => '89.90']);
    $customer = Customer::factory()->withCard()->for($individual)->create(['due_day' => 10]);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create();

    app(ChangePlan::class)->handle($user, $customer, ['plan_id' => (string) $familiar->id, 'reason' => '  Incluir a família ']);

    $change = $customer->planChanges()->sole();

    expect($customer->refresh()->plan_id)->toBe($familiar->id)
        ->and($change->from_plan_id)->toBe($individual->id)
        ->and($change->to_plan_id)->toBe($familiar->id)
        ->and($change->from_price)->toBe('49.90')
        ->and($change->to_price)->toBe('89.90')
        ->and($change->reason)->toBe('Incluir a família')
        ->and($change->user_id)->toBe($user->id)
        ->and($invoice->refresh()->amount)->toBe('49.90')
        ->and($invoice->plan_id)->toBe($individual->id);

    $next = app(IssueInvoice::class)->handle($customer, CarbonImmutable::parse('2026-11-01'));

    expect($next->amount)->toBe('89.90');
});

test('an inactive plan is rejected', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $planId = $customer->plan_id;
    $inactive = Plan::factory()->inactive()->create();

    expect(fn () => app(ChangePlan::class)->handle($user, $customer, ['plan_id' => $inactive->id]))
        ->toThrow(ValidationException::class, 'Selecione um plano ativo.');

    expect($customer->refresh()->plan_id)->toBe($planId)
        ->and($customer->planChanges()->count())->toBe(0);
});

test('the current plan is rejected', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();

    expect(fn () => app(ChangePlan::class)->handle($user, $customer, ['plan_id' => $customer->plan_id]))
        ->toThrow(ValidationException::class, 'O titular já está no plano');
});

test('the change requires cards.manage', function () {
    $user = actingAsRole('atendente');
    $customer = Customer::factory()->withCard()->create();
    $planId = $customer->plan_id;

    expect(fn () => app(ChangePlan::class)->handle($user, $customer, ['plan_id' => Plan::factory()->create()->id]))
        ->toThrow(AuthorizationException::class);

    expect($customer->refresh()->plan_id)->toBe($planId);
});

test('keeping the plan with a new monthly amount records the amounts from and to', function () {
    $user = actingAsRole('financeiro');
    $plan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->withCard()->for($plan)->create(['monthly_amount' => '89.90']);

    app(ChangePlan::class)->handle($user, $customer, ['plan_id' => $plan->id, 'monthly_amount' => '75,00']);

    $change = $customer->planChanges()->sole();

    expect($customer->refresh()->monthly_amount)->toBe('75.00')
        ->and($change->from_plan_id)->toBe($plan->id)
        ->and($change->to_plan_id)->toBe($plan->id)
        ->and($change->from_price)->toBe('89.90')
        ->and($change->to_price)->toBe('75.00');
});

test('a new plan with a typed amount bills the typed amount, not the plan price', function () {
    $user = actingAsRole('financeiro');
    $familiar = Plan::factory()->withDependents(4)->create(['price' => '89.90']);
    $customer = Customer::factory()->withCard()->create();

    app(ChangePlan::class)->handle($user, $customer, ['plan_id' => $familiar->id, 'monthly_amount' => '120,00']);

    $next = app(IssueInvoice::class)->handle($customer->refresh(), CarbonImmutable::parse('2026-11-01'));

    expect($next->amount)->toBe('120.00');
});
