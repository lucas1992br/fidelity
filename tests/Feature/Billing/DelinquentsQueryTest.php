<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RegisterCompanyPayment;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Cards\CancelCard;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\CompanyBill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Queries\DelinquentsQuery;
use App\Services\DashboardMetrics;
use App\Support\Billing\DelinquentRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('a holder with two overdue invoices shows the count, the sum and the longest delay', function () {
    $customer = Customer::factory()->withCard(CardStatus::Suspended)->create(['phone' => '11987654321']);
    Invoice::factory()->for($customer)->overdue(55)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->overdue(24)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create(['amount' => '89.90']);

    $rows = app(DelinquentsQuery::class)->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->id)->toBe($customer->id)
        ->and($row->overdueCount)->toBe(2)
        ->and($row->overdueAmount)->toBe('179.80')
        ->and($row->maxDaysOverdue)->toBe(55)
        ->and($row->oldestDueDate->toDateString())->toBe('2026-08-21')
        ->and($row->cardStatus)->toBe(CardStatus::Suspended);

    expect(app(DelinquentsQuery::class)->summary())->toBe(['holders' => 1, 'companies' => 0, 'amount' => '179.80']);
});

test('holders are ordered by the longest delay', function () {
    $recent = Customer::factory()->withCard()->create(['name' => 'Ana Recente']);
    Invoice::factory()->for($recent)->overdue(3)->create();
    $oldest = Customer::factory()->withCard()->create(['name' => 'Bruno Antigo']);
    Invoice::factory()->for($oldest)->overdue(90)->create();
    $middle = Customer::factory()->withCard()->create(['name' => 'Carla Meio']);
    Invoice::factory()->for($middle)->overdue(30)->create();

    expect(app(DelinquentsQuery::class)->get()->pluck('id')->all())->toBe([$oldest->id, $middle->id, $recent->id])
        ->and(app(DelinquentsQuery::class)->get(limit: 2)->pluck('id')->all())->toBe([$oldest->id, $middle->id]);
});

test('a holder with only open invoices is not delinquent', function () {
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create();
    Invoice::factory()->for($customer)->forMonth('2026-09')->paid()->create();

    expect(app(DelinquentsQuery::class)->get())->toBeEmpty()
        ->and(app(DelinquentsQuery::class)->count())->toBe(0)
        ->and(app(DelinquentsQuery::class)->summary())->toBe(['holders' => 0, 'companies' => 0, 'amount' => '0.00']);
});

test('a holder whose card was cancelled with the invoices kept is still delinquent', function () {
    $customer = Customer::factory()->withCard()->create();
    $invoice = Invoice::factory()->for($customer)->overdue(40)->create();

    app(CancelCard::class)->handle($customer->currentCard, 'Inadimplência', CancelCard::KeepPendingInvoices, User::factory()->create());

    $row = app(DelinquentsQuery::class)->get()->sole();

    expect($row->id)->toBe($customer->id)
        ->and($row->overdueAmount)->toBe($invoice->amount)
        ->and($row->cardStatus)->toBe(CardStatus::Cancelled);
});

test('the listing loads every holder in a single query', function () {
    Customer::factory()->withCard()->count(3)->create()->each(
        fn (Customer $customer) => Invoice::factory()->for($customer)->overdue(10)->create(),
    );

    // Lookup ids are loaded once per request; only the listing itself is measured.
    EntryType::Income->id();
    EntryStatus::Pending->id();
    PaymentMethod::Boleto->id();
    CardStatus::Active->id();

    DB::enableQueryLog();
    $rows = app(DelinquentsQuery::class)->get();
    $rows->each(fn (DelinquentRow $row) => [$row->name, $row->phone, $row->cardStatus, $row->maxDaysOverdue]);

    expect($rows)->toHaveCount(3)
        ->and(DB::getQueryLog())->toHaveCount(1);
});

test('an overdue company bill shows as one company line at its fixed amount in place of its employees', function () {
    $holder = Customer::factory()->withCard()->create();
    Invoice::factory()->for($holder)->overdue(10)->create(['amount' => '89.90']);
    $company = Company::factory()->create();
    $overdueBill = CompanyBill::factory()->for($company)->create(['reference_month' => '2026-09-01', 'amount' => '1200.00']);
    $paidBill = CompanyBill::factory()->for($company)->create(['reference_month' => '2026-08-01', 'amount' => '1000.00']);
    $employee = Customer::factory()->forCompany($company)->withCard()->create();
    Invoice::factory()->for($employee)->overdue(10)->create(['amount' => '0.00', 'reference_month' => $overdueBill->reference_month]);
    Invoice::factory()->for($employee)->forMonth('2026-08')->paid()->create(['amount' => '0.00']);

    $company->update(['name' => 'Metalúrgica Souza LTDA', 'trade_name' => null]);
    $companyRow = app(DelinquentsQuery::class)->get()->sole(fn (DelinquentRow $row): bool => $row->isCompany());

    expect(app(DelinquentsQuery::class)->get()->pluck('id')->all())->not->toContain($employee->id)
        ->and($companyRow->id)->toBe($company->id)
        ->and($companyRow->name)->toBe('Metalúrgica Souza LTDA')
        ->and($companyRow->overdueCount)->toBe(1)
        ->and($companyRow->overdueAmount)->toBe('1200.00')
        ->and($companyRow->employees)->toBe(1)
        ->and(app(DelinquentsQuery::class)->summary())->toBe(['holders' => 1, 'companies' => 1, 'amount' => '1289.90'])
        ->and(app(DelinquentsQuery::class)->count())->toBe(2);
});

test('late boleto installments make the holder, or the company that paid with them, delinquent', function () {
    $holder = Customer::factory()->withCard()->create();
    $holderPayment = app(RegisterPayment::class)->handle(Invoice::factory()->for($holder)->create(['amount' => '300.00']), [
        'paid_at' => '2026-09-01', 'amount' => '300.00', 'payment_method' => PaymentMethod::Boleto->value, 'installments' => 3,
    ], User::factory()->create());
    $company = Company::factory()->create(['monthly_amount' => '1200.00', 'due_day' => 1]);
    app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), CarbonImmutable::parse('2026-09-01'));
    app(RegisterCompanyPayment::class)->handle($company, '2026-09', [
        'paid_at' => '2026-09-01', 'amount' => '1200.00', 'payment_method' => PaymentMethod::Boleto->value, 'installments' => 2,
    ], actingAsRole('financeiro'));

    $rows = app(DelinquentsQuery::class)->get()->keyBy(fn (DelinquentRow $row): string => "{$row->kind}-{$row->id}");

    expect($rows->keys()->sort()->values()->all())->toBe(["company-{$company->id}", "holder-{$holder->id}"])
        ->and($rows["holder-{$holder->id}"]->overdueCount)->toBe(2)
        ->and($rows["holder-{$holder->id}"]->overdueAmount)->toBe('200.00')
        ->and($rows["holder-{$holder->id}"]->maxDaysOverdue)->toBe(44)
        ->and($rows["company-{$company->id}"]->overdueCount)->toBe(2)
        ->and($rows["company-{$company->id}"]->overdueAmount)->toBe('1200.00')
        ->and($rows["company-{$company->id}"]->employees)->toBe(1)
        ->and(app(DashboardMetrics::class)->overdueAmount())->toBe('1400.00')
        ->and($holderPayment->refresh()->financialEntries()->count())->toBe(3);
});
