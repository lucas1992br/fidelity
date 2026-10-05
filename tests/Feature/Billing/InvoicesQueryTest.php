<?php

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Queries\InvoicesQuery;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->plan = Plan::factory()->create(['price' => '89.90']);
});

/**
 * Invoice of a new holder of the plan, with the given status and amount.
 *
 * @param  array<string, mixed>  $attributes
 */
function invoiceOf(Plan $plan, InvoiceStatus $status, string $amount, string $month = '2026-10', array $attributes = []): Invoice
{
    return Invoice::factory()
        ->for(Customer::factory()->for($plan))
        ->forMonth($month)
        ->create(['invoice_status_id' => $status->id(), 'amount' => $amount, ...$attributes]);
}

test('the summary of a month sums the amounts and counts the invoices by status', function () {
    invoiceOf($this->plan, InvoiceStatus::Paid, '89.90');
    invoiceOf($this->plan, InvoiceStatus::Paid, '100.00');
    invoiceOf($this->plan, InvoiceStatus::Open, '50.10');
    invoiceOf($this->plan, InvoiceStatus::Overdue, '30.00');
    invoiceOf($this->plan, InvoiceStatus::Overdue, '20.00');
    invoiceOf($this->plan, InvoiceStatus::Paid, '999.00', '2026-09');

    expect((new InvoicesQuery(month: '2026-10'))->summary())->toBe([
        'total' => ['amount' => '290.00', 'count' => 5],
        'paid' => ['amount' => '189.90', 'count' => 2],
        'open' => ['amount' => '50.10', 'count' => 1],
        'overdue' => ['amount' => '50.00', 'count' => 2],
    ]);
});

test('cancelled invoices are left out of the total', function () {
    invoiceOf($this->plan, InvoiceStatus::Open, '89.90');
    invoiceOf($this->plan, InvoiceStatus::Cancelled, '89.90');

    $query = new InvoicesQuery(month: '2026-10');

    expect($query->summary()['total'])->toBe(['amount' => '89.90', 'count' => 1])
        ->and($query->builder()->count())->toBe(2);
});

test('the plan filter keeps only invoices of that plan', function () {
    $other = Plan::factory()->create();
    $mine = invoiceOf($this->plan, InvoiceStatus::Open, '89.90');
    invoiceOf($other, InvoiceStatus::Open, '45.00');

    $query = new InvoicesQuery(month: '2026-10', planId: $this->plan->id);

    expect($query->builder()->pluck('id')->all())->toBe([$mine->id])
        ->and($query->summary()['total'])->toBe(['amount' => '89.90', 'count' => 1]);
});

test('the due date interval filter replaces the reference month', function () {
    invoiceOf($this->plan, InvoiceStatus::Overdue, '89.90', '2026-08');
    $september = invoiceOf($this->plan, InvoiceStatus::Overdue, '89.90', '2026-09');
    $october = invoiceOf($this->plan, InvoiceStatus::Open, '89.90', '2026-10');
    invoiceOf($this->plan, InvoiceStatus::Open, '89.90', '2026-11');

    $query = new InvoicesQuery(dueFrom: '2026-09-01', dueUntil: '2026-10-10');

    expect($query->builder()->orderBy('due_date')->pluck('id')->all())->toBe([$september->id, $october->id]);
});

test('status and customer filters narrow the invoices', function () {
    $maria = Customer::factory()->for($this->plan)->create(['name' => 'Maria Conceição']);
    $overdue = Invoice::factory()->for($maria)->overdue(20)->create();
    Invoice::factory()->for($maria)->forMonth('2026-10')->create();
    Invoice::factory()->for(Customer::factory()->for($this->plan)->create(['name' => 'José Ribeiro']))->overdue(20)->create();

    $query = new InvoicesQuery(status: InvoiceStatus::Overdue, customer: 'maria');

    expect($query->builder()->pluck('id')->all())->toBe([$overdue->id]);
});
