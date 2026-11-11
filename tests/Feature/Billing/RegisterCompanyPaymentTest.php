<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RefundPayment;
use App\Actions\Billing\RegisterCompanyPayment;
use App\Enums\CardStatus;
use App\Enums\EntryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    settings(['suspend_after_days' => 30]);
});

/**
 * Pay the company bill of the month through the action with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerCompanyPayment(Company $company, string $month, array $overrides = [], ?User $user = null): Payment
{
    return app(RegisterCompanyPayment::class)->handle($company, $month, [
        'paid_at' => '2026-10-15',
        'amount' => '150.00',
        'payment_method' => PaymentMethod::Pix->value,
        ...$overrides,
    ], $user ?? actingAsRole('financeiro'));
}

/**
 * Open invoice of a new employee of the company in the given month.
 */
function employeeInvoice(Company $company, string $month, string $amount): Invoice
{
    $employee = Customer::factory()->forCompany($company)->withCard()->create();

    return Invoice::factory()->for($employee)->forMonth($month)->create(['amount' => $amount]);
}

test('one payment pays every open employee invoice of the month with one receipt and one income to the company', function () {
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA']);
    $first = employeeInvoice($company, '2026-10', '100.00');
    $second = employeeInvoice($company, '2026-10', '50.00');
    $otherMonth = employeeInvoice($company, '2026-11', '100.00');

    $payment = registerCompanyPayment($company, '2026-10');

    $entry = $payment->financialEntries()->sole();

    expect($payment->company_id)->toBe($company->id)
        ->and($payment->receipt_number)->toBeInt()
        ->and($payment->invoices()->pluck('invoices.id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($first->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($second->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($otherMonth->refresh()->hasStatus(InvoiceStatus::Open))->toBeTrue()
        ->and($entry->amount)->toBe('150.00')
        ->and($entry->supplier_or_payer)->toBe('Metalúrgica Souza LTDA')
        ->and($entry->description)->toBe('Fatura empresa 10/2026 - Metalúrgica Souza LTDA (2 funcionários)')
        ->and($entry->entry_status_id)->toBe(EntryStatus::Paid->id());
});

test('an amount below the bill total is rejected', function () {
    $company = Company::factory()->create();
    employeeInvoice($company, '2026-10', '100.00');
    employeeInvoice($company, '2026-10', '50.00');

    expect(fn () => registerCompanyPayment($company, '2026-10', ['amount' => '149.99']))
        ->toThrow(ValidationException::class, 'O valor recebido não pode ser menor que R$ 150,00.');

    expect(Payment::count())->toBe(0);
});

test('a month without open employee invoices is rejected', function () {
    $company = Company::factory()->create();
    employeeInvoice($company, '2026-10', '100.00')->update(['invoice_status_id' => InvoiceStatus::Paid->id()]);

    expect(fn () => registerCompanyPayment($company, '2026-10'))
        ->toThrow(ValidationException::class, 'Não há mensalidades em aberto na fatura de 10/2026.');
});

test('another company employee invoices are not covered', function () {
    $company = Company::factory()->create();
    $own = employeeInvoice($company, '2026-10', '100.00');
    $foreign = employeeInvoice(Company::factory()->create(), '2026-10', '100.00');

    registerCompanyPayment($company, '2026-10', ['amount' => '100.00']);

    expect($own->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($foreign->refresh()->hasStatus(InvoiceStatus::Open))->toBeTrue();
});

test('a suspended employee card is reactivated when the bill is paid', function () {
    $company = Company::factory()->create();
    $employee = Customer::factory()->forCompany($company)->create();
    $card = Card::factory()->suspended()->for($employee)->create();
    $invoice = Invoice::factory()->overdue(35)->for($employee)->create(['amount' => '100.00']);

    registerCompanyPayment($company, $invoice->reference_month->format('Y-m'), ['amount' => '100.00']);

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('refunding the company payment reopens every covered invoice', function () {
    $company = Company::factory()->create();
    $first = employeeInvoice($company, '2026-10', '100.00');
    $second = employeeInvoice($company, '2026-10', '50.00');
    $payment = registerCompanyPayment($company, '2026-10');

    app(RefundPayment::class)->handle($payment, 'Duplicado', actingAsRole('admin'));

    expect($first->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($second->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($payment->financialEntries()->sole()->entry_status_id)->toBe(EntryStatus::Cancelled->id());
});

test('the company payment requires billing.pay', function () {
    $company = Company::factory()->create();
    employeeInvoice($company, '2026-10', '100.00');

    expect(fn () => registerCompanyPayment($company, '2026-10', user: actingAsRole('atendente')))->toThrow(AuthorizationException::class);
});

test('the bill with a fixed amount charges that amount whatever the number of employees', function () {
    $company = Company::factory()->create(['monthly_amount' => '1200.00', 'due_day' => 20]);
    foreach (range(1, 3) as $employee) {
        app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(), today());
    }

    expect(fn () => registerCompanyPayment($company, '2026-10', ['amount' => '1199.99']))
        ->toThrow(ValidationException::class, 'O valor recebido não pode ser menor que R$ 1.200,00.');

    $payment = registerCompanyPayment($company, '2026-10', ['amount' => '1200.00']);

    expect($payment->invoices()->count())->toBe(3)
        ->and($payment->financialEntries()->sole()->amount)->toBe('1200.00')
        ->and($company->invoices()->get()->every(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Paid)))->toBeTrue();
});
