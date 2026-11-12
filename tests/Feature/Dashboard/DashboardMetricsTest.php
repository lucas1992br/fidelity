<?php

use App\Actions\Billing\IssueInvoice;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanChange;
use App\Services\DashboardMetrics;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('counts active cards and cards in stock with the low stock alert', function () {
    settings(['min_card_stock' => 3]);
    Customer::factory()->withCard()->count(2)->create();
    Customer::factory()->withCard(CardStatus::Suspended)->create();
    Card::factory()->inStock()->count(2)->create();

    expect(app(DashboardMetrics::class)->cards())->toBe(['active' => 2, 'in_stock' => 2, 'min_stock' => 3, 'low_stock' => true]);

    Card::factory()->inStock()->create();

    expect(app(DashboardMetrics::class)->cards()['low_stock'])->toBeFalse();
});

test('lives served are the holders with an active card plus their active dependents', function () {
    [$ana, $bruno] = Customer::factory()->withCard()->count(2)->create()->all();
    $suspended = Customer::factory()->withCard(CardStatus::Suspended)->create();
    Dependent::factory()->for($ana)->count(2)->create();
    Dependent::factory()->for($bruno)->create();
    Dependent::factory()->for($bruno)->inactive()->create();
    Dependent::factory()->for($suspended)->create();

    expect(app(DashboardMetrics::class)->livesServed())->toBe(5);
});

test('month income and expense only count entries paid in the current month', function () {
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-10-02', 'paid_at' => '2026-10-02', 'amount' => '300.00']);
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-09-30', 'paid_at' => '2026-09-30', 'amount' => '999.00']);
    FinancialEntry::factory()->income()->cancelled()->create(['due_date' => '2026-10-03', 'amount' => '500.00']);
    FinancialEntry::factory()->income()->pending()->create(['due_date' => '2026-10-04', 'amount' => '70.00']);
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-10-10', 'paid_at' => '2026-10-10', 'amount' => '120.50']);

    expect(app(DashboardMetrics::class)->monthTotals())->toBe(['income' => '300.00', 'expense' => '120.50']);
});

test('open amount sums open and overdue invoices and overdue amount only the overdue ones', function () {
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->forMonth('2026-11')->open()->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->overdue(20)->create(['amount' => '50.00']);
    Invoice::factory()->for($customer)->forMonth('2026-08')->paid()->create(['amount' => '1000.00']);
    Invoice::factory()->for($customer)->forMonth('2026-07')->cancelled()->create(['amount' => '1000.00']);

    $metrics = app(DashboardMetrics::class);

    expect($metrics->openAmount())->toBe('139.90')
        ->and($metrics->overdueAmount())->toBe('50.00');
});

test('the delinquency rate is the holders with an overdue invoice over the holders in force', function () {
    $late = Customer::factory()->withCard(CardStatus::Suspended)->create();
    Invoice::factory()->for($late)->overdue(40)->create();
    Customer::factory()->withCard()->count(2)->create();
    $cancelled = Customer::factory()->withCard(CardStatus::Cancelled)->create();
    Invoice::factory()->for($cancelled)->overdue(10)->create();

    expect(app(DashboardMetrics::class)->delinquency())->toBe(['delinquent' => 1, 'holders' => 3, 'rate' => 33.3]);
});

test('without holders the delinquency rate is zero', function () {
    expect(app(DashboardMetrics::class)->delinquency())->toBe(['delinquent' => 0, 'holders' => 0, 'rate' => 0.0]);
});

test('the 12 month series starts 11 months ago and ends in the current month', function () {
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2025-11-05', 'paid_at' => '2025-11-05', 'amount' => '100.00']);
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-10-01', 'paid_at' => '2026-10-01', 'amount' => '40.00']);
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2025-10-31', 'paid_at' => '2025-10-31', 'amount' => '999.00']);

    $series = app(DashboardMetrics::class)->monthlySeries();

    expect($series)->toHaveCount(12)
        ->and($series[0])->toMatchArray(['key' => '2025-11', 'income' => '100.00', 'expense' => '0.00'])
        ->and($series[11])->toMatchArray(['key' => '2026-10', 'income' => '0.00', 'expense' => '40.00']);
});

test('latest payments are the 5 most recent ones that were not refunded', function () {
    $payments = collect(range(1, 6))->map(fn (int $day) => Payment::factory()->create(['paid_at' => "2026-10-0{$day}"]));
    Payment::factory()->refunded()->create(['paid_at' => '2026-10-10']);

    expect(app(DashboardMetrics::class)->latestPayments()->pluck('id')->all())
        ->toBe($payments->reverse()->take(5)->pluck('id')->values()->all());
});

test('top delinquents are the 5 longest delays', function () {
    $customers = collect([5, 50, 10, 90, 30, 70])->mapWithKeys(function (int $days) {
        $customer = Customer::factory()->withCard()->create();
        Invoice::factory()->for($customer)->overdue($days)->create();

        return [$days => $customer->id];
    });

    expect(app(DashboardMetrics::class)->topDelinquents()->pluck('id')->all())
        ->toBe([$customers[90], $customers[70], $customers[50], $customers[30], $customers[10]]);
});

test('month income and expense, sales, billing and payments follow the given month', function () {
    $august = CarbonImmutable::parse('2026-08-01');
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-08-02', 'paid_at' => '2026-08-02', 'amount' => '300.00']);
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-08-20', 'paid_at' => '2026-08-20', 'amount' => '120.50']);
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-10-02', 'paid_at' => '2026-10-02', 'amount' => '999.00']);
    PlanChange::factory()->sale()->count(2)->create(['created_at' => '2026-08-05 10:00']);
    PlanChange::factory()->reenrollment()->create(['created_at' => '2026-08-06 10:00']);
    PlanChange::factory()->sale()->create(['created_at' => '2026-10-05 10:00']);
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->forMonth('2026-08')->paid()->create(['amount' => '89.90']);
    Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-08')->overdue(60)->create(['amount' => '50.00', 'reference_month' => '2026-08-01']);
    Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-08')->cancelled()->create(['amount' => '1000.00']);
    $company = Company::factory()->create(['monthly_amount' => '1200.00']);
    app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), $august);
    $augustPayment = Payment::factory()->create(['paid_at' => '2026-08-12']);
    Payment::factory()->create(['paid_at' => '2026-10-12']);

    $metrics = app(DashboardMetrics::class);
    $series = $metrics->monthlySeries(until: $august);

    expect($metrics->monthTotals($august))->toBe(['income' => '300.00', 'expense' => '120.50'])
        ->and($metrics->monthSales($august))->toBe(['sales' => 2, 'reenrollments' => 1])
        ->and($metrics->monthBilling($august))->toBe(['billed' => '1339.90', 'received' => '89.90'])
        ->and($metrics->latestPayments(month: $august)->pluck('id')->all())->toBe([$augustPayment->id])
        ->and($series[11]['key'])->toBe('2026-08')
        ->and($series[0]['key'])->toBe('2025-09');
});

test('the delinquency rate counts each company once, not each of its employees', function () {
    $late = Company::factory()->create(['monthly_amount' => '1200.00']);
    foreach (range(1, 3) as $employee) {
        Invoice::factory()->for(Customer::factory()->forCompany($late)->withCard())->overdue(10)->create(['amount' => '0.00']);
    }
    Customer::factory()->forCompany(Company::factory()->create())->withCard()->create();
    Company::factory()->create();
    Customer::factory()->withCard()->create();

    expect(app(DashboardMetrics::class)->delinquency())->toBe(['delinquent' => 1, 'holders' => 3, 'rate' => 33.3]);
});
