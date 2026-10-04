<?php

use App\Actions\Plans\DeletePlan;
use App\Actions\Plans\SavePlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

test('creates a plan from a pt-BR money input', function () {
    $plan = app(SavePlan::class)->handle([
        'name' => 'Familiar Plus',
        'description' => 'Titular e até 7 dependentes',
        'price' => '1.129,90',
        'max_dependents' => 7,
        'is_active' => true,
    ]);

    expect($plan->exists)->toBeTrue()
        ->and($plan->refresh()->price)->toBe('1129.90')
        ->and($plan->max_dependents)->toBe(7);
});

test('changing the price keeps existing invoices and applies only to the next generations', function () {
    $plan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->for($plan)->create();
    $invoice = Invoice::factory()->for($customer)->create();

    app(SavePlan::class)->handle([...$plan->only('name', 'description', 'max_dependents', 'is_active'), 'price' => '99,90'], $plan);

    expect($plan->refresh()->price)->toBe('99.90')
        ->and($invoice->refresh()->amount)->toBe('89.90');
});

test('an inactive plan leaves Plan::active() but the customer keeps it', function () {
    $plan = Plan::factory()->create();
    $customer = Customer::factory()->for($plan)->create();

    app(SavePlan::class)->handle([...$plan->only('name', 'description', 'price', 'max_dependents'), 'is_active' => false], $plan);

    expect(Plan::active()->whereKey($plan->id)->exists())->toBeFalse()
        ->and($customer->refresh()->plan_id)->toBe($plan->id);
});

test('negative price and dependents are rejected', function (array $override) {
    app(SavePlan::class)->handle([
        'name' => 'Individual',
        'price' => '89,90',
        'max_dependents' => 0,
        'is_active' => true,
        ...$override,
    ]);
})->throws(ValidationException::class)->with([
    'price' => [['price' => '-1,00']],
    'max_dependents' => [['max_dependents' => -1]],
]);

test('deleting a plan without customers soft deletes it', function () {
    $plan = Plan::factory()->create();

    app(DeletePlan::class)->handle($plan);

    expect($plan->refresh()->trashed())->toBeTrue();
});

test('deleting a plan with customers fails', function () {
    $plan = Plan::factory()->create();
    Customer::factory()->for($plan)->create();

    expect(fn () => app(DeletePlan::class)->handle($plan))
        ->toThrow(ValidationException::class, 'Plano com titulares não pode ser excluído; desative-o.');

    expect($plan->refresh()->trashed())->toBeFalse();
});
