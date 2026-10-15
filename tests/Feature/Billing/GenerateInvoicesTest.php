<?php

use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    settings(['invoice_generation_day' => 20]);
});

test('on the generation day it bills next month for active and suspended holders and skips cancelled ones', function () {
    $this->travelTo('2026-10-20 01:00');
    $active = Card::factory()->active()->create()->customer;
    $suspended = Card::factory()->suspended()->create()->customer;
    $cancelled = Card::factory()->cancelled()->create()->customer;

    $this->artisan('invoices:generate')
        ->expectsOutputToContain('Mensalidades geradas: 2')
        ->assertSuccessful();

    expect(Invoice::query()->forMonth('2026-11')->pluck('customer_id')->sort()->values()->all())
        ->toBe(collect([$active->id, $suspended->id])->sort()->values()->all())
        ->and($cancelled->invoices()->exists())->toBeFalse();
});

test('the invoice is open, due on the holder due day of the next month', function () {
    $this->travelTo('2026-10-20');
    $customer = Customer::factory()->create(['due_day' => 15]);
    Card::factory()->active()->for($customer)->create();

    $this->artisan('invoices:generate')->assertSuccessful();

    $invoice = $customer->invoices()->sole();

    expect($invoice->reference_month->toDateString())->toBe('2026-11-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-11-15')
        ->and($invoice->hasStatus(InvoiceStatus::Open))->toBeTrue();
});

test('on another day without --month it generates nothing', function () {
    $this->travelTo('2026-10-19');
    Card::factory()->active()->create();

    $this->artisan('invoices:generate')->assertSuccessful();

    expect(Invoice::count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'routines')->exists())->toBeFalse();
});

test('running twice does not duplicate invoices', function () {
    $this->travelTo('2026-10-20');
    Card::factory()->active()->count(2)->create();

    $this->artisan('invoices:generate')->expectsOutputToContain('Mensalidades geradas: 2')->assertSuccessful();
    $this->artisan('invoices:generate')->expectsOutputToContain('Mensalidades geradas: 0')->assertSuccessful();

    expect(Invoice::count())->toBe(2);
});

test('it bills the current plan and the holder monthly amount, not the plan price', function () {
    $this->travelTo('2026-10-20');
    $oldPlan = Plan::factory()->create(['price' => '59.90']);
    $newPlan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->for($oldPlan)->create(['monthly_amount' => '59.90']);
    Card::factory()->active()->for($customer)->create();

    $customer->update(['plan_id' => $newPlan->id, 'monthly_amount' => '75.00']);
    $newPlan->update(['price' => '99.90']);

    $this->artisan('invoices:generate')->assertSuccessful();

    $invoice = $customer->invoices()->sole();

    expect($invoice->plan_id)->toBe($newPlan->id)
        ->and($invoice->amount)->toBe('75.00');
});

test('--month generates that month on any day', function () {
    $this->travelTo('2026-10-04');
    $customer = Card::factory()->active()->create()->customer;

    $this->artisan('invoices:generate', ['--month' => '2026-12'])->assertSuccessful();

    expect($customer->invoices()->sole()->reference_month->toDateString())->toBe('2026-12-01');
});

test('an invalid --month is rejected', function () {
    Card::factory()->active()->create();

    $this->artisan('invoices:generate', ['--month' => '2026-13'])->assertFailed();

    expect(Invoice::count())->toBe(0);
});

test('the activity log records how many invoices were created', function () {
    $this->travelTo('2026-10-20');
    Card::factory()->active()->count(3)->create();

    $this->artisan('invoices:generate')->assertSuccessful();

    $activity = Activity::query()->where('log_name', 'routines')->sole();

    expect($activity->event)->toBe('invoices:generate')
        ->and($activity->properties['count'])->toBe(3)
        ->and($activity->properties['reference_month'])->toBe('2026-11-01')
        ->and($activity->causer)->toBeNull();
});
