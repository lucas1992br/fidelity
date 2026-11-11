<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RegisterCompanyPayment;
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

test('the invoice freezes the holder monthly amount, not the plan price', function () {
    $plan = Plan::factory()->create(['price' => '89.90']);
    $customer = Customer::factory()->for($plan)->create(['monthly_amount' => '45.00']);

    $invoice = app(IssueInvoice::class)->handle($customer, now()->setDate(2026, 11, 1));

    expect($invoice->amount)->toBe('45.00')
        ->and($invoice->company_id)->toBeNull();
});

test('an employee invoice is zero and the month bill freezes the company fixed amount once', function () {
    $company = Company::factory()->create(['monthly_amount' => '1200.00']);
    $first = Customer::factory()->forCompany($company)->create(['monthly_amount' => '45.00']);
    $second = Customer::factory()->forCompany($company)->create();

    $invoice = app(IssueInvoice::class)->handle($first, now()->setDate(2026, 11, 1));
    $company->update(['monthly_amount' => '1500.00']);
    app(IssueInvoice::class)->handle($second, now()->setDate(2026, 11, 1));

    $bill = $company->bills()->sole();

    expect($invoice->amount)->toBe('0.00')
        ->and($invoice->company_id)->toBe($company->id)
        ->and($bill->reference_month->toDateString())->toBe('2026-11-01')
        ->and($bill->amount)->toBe('1200.00');
});

test('an employee joining a month the company already paid gets the invoice paid by that payment', function () {
    $this->travelTo('2026-11-03');
    actingAsRole('financeiro');
    $company = Company::factory()->create(['monthly_amount' => '1200.00', 'due_day' => 10]);
    app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), today());
    $payment = app(RegisterCompanyPayment::class)->handle($company, '2026-11', ['paid_at' => '2026-11-03', 'amount' => '1200.00', 'payment_method' => 'pix'], auth()->user());

    $invoice = app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), today());

    expect($invoice->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($invoice->paid_at->toDateString())->toBe('2026-11-03')
        ->and($payment->invoices()->pluck('invoices.id'))->toContain($invoice->id);
});
