<?php

use App\Actions\Billing\RegisterPayment;
use App\Enums\EntryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->customer = Customer::factory()->withCard()->create();
});

/**
 * Invoice of the holder paid through RegisterPayment.
 *
 * @param  array<string, mixed>  $attributes
 */
function paidHolderInvoice(Customer $customer, array $attributes = []): Payment
{
    $invoice = Invoice::factory()->for($customer)->create($attributes);

    return app(RegisterPayment::class)->handle($invoice, [
        'paid_at' => '2026-10-15',
        'amount' => $invoice->amount,
        'payment_method' => PaymentMethod::Pix->value,
    ], User::factory()->create());
}

/**
 * The customer sheet on the Mensalidades tab.
 */
function invoicesTab(Customer $customer): Testable
{
    return Livewire::withQueryParams(['tab' => 'mensalidades'])->test('pages::customers.show', ['customer' => $customer]);
}

test('payable invoices offer the payment and paid ones the receipt', function () {
    actingAsRole('financeiro');
    $pending = Invoice::factory()->for($this->customer)->forMonth('2026-11')->create();
    $payment = paidHolderInvoice($this->customer, ['reference_month' => '2026-09-01', 'due_date' => '2026-09-10']);

    invoicesTab($this->customer)
        ->assertSeeHtml("\$dispatch('open-payment', { invoice: {$pending->id} })")
        ->assertSeeHtml(route('receipts.show', $payment));
});

test('financeiro does not see "Estornar" and admin does', function (string $role, bool $visible) {
    actingAsRole($role);
    paidHolderInvoice($this->customer);

    $component = invoicesTab($this->customer);

    $visible ? $component->assertSee('Estornar') : $component->assertDontSee('Estornar');
})->with([
    'financeiro' => ['financeiro', false],
    'admin' => ['admin', true],
]);

test('refunding through the sheet returns the invoice to open or overdue', function (string $dueDate, InvoiceStatus $expected) {
    actingAsRole('admin');
    $payment = paidHolderInvoice($this->customer, ['due_date' => $dueDate]);

    invoicesTab($this->customer)
        ->call('openRefund', $payment->id)
        ->assertSet('refundingPaymentId', $payment->id)
        ->assertSee('Estornar pagamento')
        ->set('refundReason', 'Lançado em duplicidade')
        ->call('refund')
        ->assertHasNoErrors()
        ->assertSet('refundingPaymentId', null)
        ->assertDispatched('notify', title: "Pagamento estornado · recibo nº {$payment->receipt_number}")
        ->assertDontSee('Estornar');

    $payment->refresh();
    $invoice = $payment->invoice;

    expect($invoice->hasStatus($expected))->toBeTrue()
        ->and($invoice->paid_at)->toBeNull()
        ->and($payment->refund_reason)->toBe('Lançado em duplicidade')
        ->and($payment->financialEntries()->sole()->entry_status_id)->toBe(EntryStatus::Cancelled->id());
})->with([
    'not yet due' => ['2026-10-20', InvoiceStatus::Open],
    'past due' => ['2026-10-10', InvoiceStatus::Overdue],
]);

test('financeiro calling the refund methods gets 403', function (string $method) {
    $payment = paidHolderInvoice($this->customer);
    actingAsRole('financeiro');

    invoicesTab($this->customer)
        ->call($method, ...($method === 'openRefund' ? [$payment->id] : []))
        ->assertForbidden();

    expect($payment->refresh()->refunded_at)->toBeNull();
})->with(['openRefund', 'refund']);

test('a payment of another holder cannot be refunded from this sheet', function () {
    actingAsRole('admin');
    $otherPayment = paidHolderInvoice(Customer::factory()->withCard()->create());

    invoicesTab($this->customer)->call('openRefund', $otherPayment->id)->assertNotFound();
});

test('the tab reloads when a payment is registered', function () {
    actingAsRole('financeiro');
    $invoice = Invoice::factory()->for($this->customer)->create();

    $component = invoicesTab($this->customer)
        ->assertSee('Registrar pagamento')
        ->assertDontSee('Recibo');

    $payment = app(RegisterPayment::class)->handle($invoice, [
        'paid_at' => '2026-10-15',
        'amount' => $invoice->amount,
        'payment_method' => PaymentMethod::Pix->value,
    ], User::factory()->create());

    $component->dispatch('payment-registered', invoice: $invoice->id)
        ->assertSeeHtml(route('receipts.show', $payment));
});

test('an invoice paid in boletos follows accounts receivable until every installment is received', function () {
    actingAsRole('financeiro');
    $payment = app(RegisterPayment::class)->handle(Invoice::factory()->for($this->customer)->forMonth('2026-11')->create(['amount' => '300.00']), [
        'paid_at' => '2026-09-15',
        'amount' => '300.00',
        'payment_method' => PaymentMethod::Boleto->value,
        'installments' => 3,
    ], User::factory()->create());
    [$first, $second, $third] = $payment->financialEntries->all();

    invoicesTab($this->customer)->assertSee('Parcela em atraso · 0/3 recebidas');

    $first->forceFill(['entry_status_id' => EntryStatus::Paid->id(), 'paid_at' => '2026-09-15'])->save();
    $second->forceFill(['entry_status_id' => EntryStatus::Paid->id(), 'paid_at' => '2026-10-15'])->save();

    invoicesTab($this->customer)->assertSee('A receber · 2/3 parcelas')->assertDontSee('Parcela em atraso');

    $third->forceFill(['entry_status_id' => EntryStatus::Paid->id(), 'paid_at' => '2026-11-15'])->save();

    invoicesTab($this->customer)->assertDontSee('A receber')->assertSee('Pago');
});

test('an invoice paid on the card in installments is paid, since the acquirer owes the installments', function () {
    actingAsRole('financeiro');
    app(RegisterPayment::class)->handle(Invoice::factory()->for($this->customer)->forMonth('2026-11')->create(['amount' => '300.00']), [
        'paid_at' => '2026-09-15',
        'amount' => '300.00',
        'payment_method' => PaymentMethod::Card->value,
        'installments' => 3,
    ], User::factory()->create());

    invoicesTab($this->customer)
        ->assertSee('Pago · cartão crédito 3x')
        ->assertDontSee('A receber')
        ->assertDontSee('Parcela em atraso');
});
