<?php

use App\Actions\Billing\ImportCardStatement;
use App\Actions\Billing\ReconcileCardTransaction;
use App\Enums\CardTransactionResult;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\CardImport;
use App\Models\CardTransaction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

/**
 * One statement line of the card 498407-9593 with the given amount, type and status.
 */
function statementLine(string $id, string $amount, string $type = 'Crédito à Vista', string $status = 'Aprovada', string $card = '498407-9593'): string
{
    return "{$id};07/10/2026 - 15:21:18;0003;{$type};Visa;{$card};POS;;269415;598100;;{$amount};{$status}";
}

/**
 * Import the lines as a Granito statement.
 *
 * @param  list<string>  $lines
 * @return array{import: CardImport, errors: list<string>}
 */
function importStatement(array $lines, ?User $user = null): array
{
    $contents = implode("\r\n", ['ID Granito;Data e Hora;Pdv;Tipo;Bandeira;Cartão;Meio de Captura;Documento Vinculado;Cod. Autorização;Cod. Transação;ID Pedido E-Commerce;Valor;Status', ...$lines]);

    return app(ImportCardStatement::class)->handle($contents, 'extrato.txt', $user ?? actingAsRole('financeiro'));
}

/**
 * Holder paying with the card 498407-9593 and an open October invoice of the amount.
 */
function holderWithKnownCard(string $amount = '145.00'): Invoice
{
    $customer = Customer::factory()->withCard()->create(['monthly_amount' => $amount]);
    PaymentCard::factory()->for($customer)->create(['card_bin' => '498407', 'card_last4' => '9593']);

    return Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => $amount]);
}

test('an approved transaction of a known card pays the holder oldest open invoice as a card payment', function () {
    $invoice = holderWithKnownCard('145.00');

    importStatement([statementLine('1604141679', '145')]);

    $transaction = CardTransaction::sole();
    $payment = Payment::sole();

    expect($transaction->result)->toBe(CardTransactionResult::Paid)
        ->and($transaction->payment_id)->toBe($payment->id)
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($payment->payment_method_id)->toBe(PaymentMethod::Card->id())
        ->and($payment->paid_at->toDateString())->toBe('2026-10-07')
        ->and($payment->card_bin)->toBe('498407')
        ->and($payment->card_last4)->toBe('9593')
        ->and($payment->notes)->toBe('Conciliação de cartão · ID Granito 1604141679 · aut. 269415');
});

test('an installment sale pays with the installments of its type', function () {
    holderWithKnownCard('145.00');

    importStatement([statementLine('1', '145', 'Crédito 3X')]);

    expect(Payment::sole()->installments)->toBe(3)
        ->and(Payment::sole()->financialEntries()->count())->toBe(3);
});

test('an amount covering several months pays them all', function () {
    $invoice = holderWithKnownCard('100.00');

    importStatement([statementLine('1', '250')]);

    $payment = Payment::sole();

    expect($payment->months)->toBe(2)
        ->and($payment->amount)->toBe('250.00')
        ->and($invoice->customer->invoices()->where('invoice_status_id', InvoiceStatus::Paid->id())->count())->toBe(2);
});

test('an unknown card stays pending with the reason', function () {
    Invoice::factory()->forMonth('2026-10')->create();

    importStatement([statementLine('1', '145')]);

    expect(CardTransaction::sole()->result)->toBe(CardTransactionResult::Pending)
        ->and(CardTransaction::sole()->message)->toBe('Cartão sem cliente ou empresa vinculado. Vincule para dar baixa; nos próximos meses a baixa é automática.')
        ->and(Payment::count())->toBe(0);
});

test('an amount below the monthly fee stays pending', function () {
    $invoice = holderWithKnownCard('145.00');

    importStatement([statementLine('1', '100')]);

    expect(CardTransaction::sole()->result)->toBe(CardTransactionResult::Pending)
        ->and(CardTransaction::sole()->message)->toBe('Valor de R$ 100,00 menor que a mensalidade de R$ 145,00.')
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeFalse();
});

test('a holder without open invoices stays pending', function () {
    $invoice = holderWithKnownCard();
    $invoice->update(['invoice_status_id' => InvoiceStatus::Paid->id()]);

    importStatement([statementLine('1', '145')]);

    expect(CardTransaction::sole()->message)->toBe("{$invoice->customer->name} não tem mensalidade em aberto.");
});

test('a transaction not approved is ignored and settles nothing', function (string $status) {
    holderWithKnownCard();

    importStatement([statementLine('1', '145', status: $status)]);

    expect(CardTransaction::sole()->result)->toBe(CardTransactionResult::Ignored)
        ->and(CardTransaction::sole()->message)->toBe("Transação {$status}: não dá baixa.")
        ->and(Payment::count())->toBe(0);
})->with(['Negada', 'Cancelada', 'Estornada']);

test('a transaction already imported is skipped and never pays twice', function () {
    holderWithKnownCard();
    importStatement([statementLine('1604141679', '145')]);

    ['import' => $import] = importStatement([statementLine('1604141679', '145')]);

    expect($import->transactions_count)->toBe(0)
        ->and($import->duplicates_count)->toBe(1)
        ->and(CardTransaction::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
});

test('a company card pays the company oldest open bill', function () {
    $company = Company::factory()->create();
    PaymentCard::factory()->for($company)->create(['customer_id' => null, 'card_bin' => '498407', 'card_last4' => '9593']);
    foreach (['100.00', '45.00'] as $amount) {
        Invoice::factory()->for(Customer::factory()->forCompany($company)->withCard())->forMonth('2026-10')->create(['amount' => $amount]);
    }

    importStatement([statementLine('1', '145')]);

    expect(Payment::sole()->company_id)->toBe($company->id)
        ->and(Payment::sole()->invoices()->count())->toBe(2)
        ->and(CardTransaction::sole()->result)->toBe(CardTransactionResult::Paid);
});

test('linking a pending transaction to a holder pays it and remembers the card for the next import', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['monthly_amount' => '145.00']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '145.00']);
    importStatement([statementLine('1', '145')], $user);

    app(ReconcileCardTransaction::class)->handle(CardTransaction::sole(), $user, $customer);

    expect(CardTransaction::sole()->result)->toBe(CardTransactionResult::Paid)
        ->and($customer->paymentCards()->sole()->only(['card_bin', 'card_last4', 'brand']))->toBe(['card_bin' => '498407', 'card_last4' => '9593', 'brand' => 'Visa']);

    Invoice::factory()->for($customer)->forMonth('2026-11')->create(['amount' => '145.00']);
    importStatement([statementLine('2', '145')], $user);

    expect(CardTransaction::where('external_id', '2')->sole()->result)->toBe(CardTransactionResult::Paid);
});

test('linking a company employee is refused with the reason', function () {
    $user = actingAsRole('financeiro');
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA']);
    $employee = Customer::factory()->forCompany($company)->withCard()->create(['name' => 'João Lima']);
    importStatement([statementLine('1', '145')], $user);

    app(ReconcileCardTransaction::class)->handle(CardTransaction::sole(), $user, $employee);

    expect(CardTransaction::sole()->result)->toBe(CardTransactionResult::Pending)
        ->and(CardTransaction::sole()->message)->toBe('João Lima é funcionário de Metalúrgica Souza LTDA: vincule o cartão à empresa.');
});

test('importing requires billing.pay', function () {
    expect(fn () => importStatement([statementLine('1', '145')], actingAsRole('atendente')))
        ->toThrow(AuthorizationException::class);

    expect(CardTransaction::count())->toBe(0);
});
