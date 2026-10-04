<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo('2026-10-15 00:10');
});

test('an open invoice due yesterday becomes overdue', function () {
    $invoice = Invoice::factory()->create(['due_date' => '2026-10-14']);

    $this->artisan('invoices:mark-overdue')
        ->expectsOutputToContain('Mensalidades marcadas em atraso: 1')
        ->assertSuccessful();

    expect($invoice->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($invoice->days_overdue)->toBe(1);
});

test('an open invoice due today stays open', function () {
    $invoice = Invoice::factory()->create(['due_date' => '2026-10-15']);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->refresh()->hasStatus(InvoiceStatus::Open))->toBeTrue();
});

test('paid and cancelled invoices past due are not changed', function (string $state, InvoiceStatus $status) {
    $invoice = Invoice::factory()->{$state}()->create(['due_date' => '2026-09-10']);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->refresh()->hasStatus($status))->toBeTrue();
})->with([
    'paid' => ['paid', InvoiceStatus::Paid],
    'cancelled' => ['cancelled', InvoiceStatus::Cancelled],
]);

test('the activity log records how many invoices were marked', function () {
    Invoice::factory()->count(2)->create(['due_date' => '2026-10-01']);
    Invoice::factory()->create(['due_date' => '2026-10-20']);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    $activity = Activity::query()->where('log_name', 'routines')->sole();

    expect($activity->event)->toBe('invoices:mark-overdue')
        ->and($activity->properties['count'])->toBe(2)
        ->and($activity->causer)->toBeNull();
});
