<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\Billing\DueDate;

/**
 * Issue the first invoice the way the sale does: due on the next `due_day`.
 */
function issueFirstInvoice(Customer $customer): ?Invoice
{
    return app(IssueInvoice::class)->handle($customer, DueDate::next(today(), $customer->due_day));
}

test('the first invoice falls due on the next due day strictly after joining', function (string $today, int $dueDay, string $dueDate, string $referenceMonth) {
    $this->travelTo($today);
    $customer = Customer::factory()->create(['due_day' => $dueDay]);

    $invoice = issueFirstInvoice($customer);

    expect($invoice->due_date->toDateString())->toBe($dueDate)
        ->and($invoice->reference_month->toDateString())->toBe($referenceMonth)
        ->and($invoice->hasStatus(InvoiceStatus::Open))->toBeTrue();
})->with([
    'before the due day' => ['2026-10-05', 10, '2026-10-10', '2026-10-01'],
    'on the due day' => ['2026-10-10', 10, '2026-11-10', '2026-11-01'],
    'after the due day' => ['2026-10-25', 5, '2026-11-05', '2026-11-01'],
    'december rolls the year' => ['2026-12-20', 10, '2027-01-10', '2027-01-01'],
]);

test('a second issue for the same month does not create another invoice', function () {
    $customer = Customer::factory()->create(['due_day' => 10]);

    $first = app(IssueInvoice::class)->handle($customer, now()->setDate(2026, 11, 1));
    $second = app(IssueInvoice::class)->handle($customer, now()->setDate(2026, 11, 20));

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($customer->invoices()->count())->toBe(1);
});

test('amount and plan stay frozen after the plan price changes', function () {
    $plan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->for($plan)->create(['due_day' => 10]);

    $invoice = app(IssueInvoice::class)->handle($customer, now()->setDate(2026, 11, 1));
    $plan->update(['price' => '99.90']);

    expect($invoice->refresh()->amount)->toBe('89.90')
        ->and($invoice->plan_id)->toBe($plan->id);
});

test('the invoice freezes the holder monthly amount and company, not the plan price', function () {
    $plan = Plan::factory()->create(['price' => '89.90']);
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($plan)->forCompany($company)->create(['monthly_amount' => '45.00']);

    $invoice = app(IssueInvoice::class)->handle($customer, now()->setDate(2026, 11, 1));

    expect($invoice->amount)->toBe('45.00')
        ->and($invoice->company_id)->toBe($company->id);
});
