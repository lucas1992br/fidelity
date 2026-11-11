<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RegisterCompanyPayment;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Queries\InvoicesQuery;
use App\Support\Billing\BillingRow;
use Carbon\CarbonImmutable;

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

/**
 * Company with fixed amount whose employees got their invoices of the month issued.
 *
 * @return list<Invoice>
 */
function companyBillOf(Company $company, int $employees, string $month = '2026-10'): array
{
    return collect(range(1, $employees))
        ->map(fn (): Invoice => app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), CarbonImmutable::parse("{$month}-01")))
        ->all();
}

test('a company bill replaces its employee invoices with one line at the fixed amount', function () {
    invoiceOf($this->plan, InvoiceStatus::Open, '89.90');
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA', 'monthly_amount' => '1200.00', 'due_day' => 20]);
    companyBillOf($company, 3);

    $rows = (new InvoicesQuery(month: '2026-10'))->rows()->get()->map(BillingRow::fromRecord(...));
    $bill = $rows->first(fn (BillingRow $row): bool => $row->isCompanyBill());

    expect($rows)->toHaveCount(2)
        ->and($bill->name)->toBe('Metalúrgica Souza LTDA')
        ->and($bill->companyId)->toBe($company->id)
        ->and($bill->amount)->toBe('1200.00')
        ->and($bill->employees)->toBe(3)
        ->and($bill->status)->toBe(InvoiceStatus::Open)
        ->and($bill->dueDate->toDateString())->toBe('2026-10-20')
        ->and((new InvoicesQuery(month: '2026-10'))->summary()['open'])->toBe(['amount' => '1289.90', 'count' => 2]);
});

test('the company bill status follows its employee invoices and a paid bill links its receipt', function () {
    $company = Company::factory()->create(['monthly_amount' => '1200.00', 'due_day' => 20]);
    companyBillOf($company, 2);
    $payment = app(RegisterCompanyPayment::class)->handle($company, '2026-10', ['paid_at' => '2026-10-15', 'amount' => '1200.00', 'payment_method' => 'pix'], actingAsRole('financeiro'));

    $bill = BillingRow::fromRecord((new InvoicesQuery(month: '2026-10'))->rows()->sole());

    expect($bill->status)->toBe(InvoiceStatus::Paid)
        ->and($bill->paymentId)->toBe($payment->id)
        ->and($bill->paidAt->toDateString())->toBe('2026-10-15')
        ->and((new InvoicesQuery(month: '2026-10'))->summary()['paid'])->toBe(['amount' => '1200.00', 'count' => 1]);
});

test('the search finds a company bill by name and the plan filter leaves company bills out', function () {
    invoiceOf($this->plan, InvoiceStatus::Open, '89.90');
    companyBillOf(Company::factory()->create(['name' => 'Metalúrgica Souza LTDA']), 1);

    $kindsFound = fn (InvoicesQuery $query): array => $query->rows()->pluck('kind')->all();

    expect($kindsFound(new InvoicesQuery(month: '2026-10', customer: 'metalurgica')))->toBe([BillingRow::KindCompanyBill])
        ->and($kindsFound(new InvoicesQuery(month: '2026-10', planId: $this->plan->id)))->toBe([BillingRow::KindInvoice]);
});
