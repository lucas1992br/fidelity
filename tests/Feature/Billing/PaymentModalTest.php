<?php

use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod as PaymentMethodModel;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    settings(['suspend_after_days' => 30]);
});

test('opening prefills the date, the invoice amount and the first active method', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '1289.90']);

    Livewire::test('billing.payment-modal')
        ->dispatch('open-payment', invoice: $invoice->id)
        ->assertSet('invoiceId', $invoice->id)
        ->assertSet('paidAt', '15/10/2026')
        ->assertSet('amount', '1.289,90')
        ->assertSet('method', PaymentMethod::Pix->value)
        ->assertSee('Maria Aparecida Souza')
        ->assertSee('ref. 10/2026')
        ->assertSee('Observação')
        ->assertSee('Confirmar baixa')
        ->assertDontSee('ele será reativado');
});

test('confirming registers the payment and notifies the receipt number', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);

    $component = Livewire::test('billing.payment-modal')
        ->call('open', $invoice->id)
        ->set('amount', '95,00')
        ->set('method', PaymentMethod::Cash->value)
        ->set('notes', 'Pago com acréscimo')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('invoiceId', null);

    $payment = Payment::query()->sole();

    $component
        ->assertDispatched('notify', title: "Pagamento registrado · recibo nº {$payment->receipt_number}", description: 'Maria Aparecida Souza · ref. 10/2026', receiptUrl: route('receipts.show', $payment))
        ->assertDispatched('payment-registered', invoice: $invoice->id);

    expect($payment->amount)->toBe('95.00')
        ->and($payment->payment_method_id)->toBe(PaymentMethod::Cash->id())
        ->and($payment->notes)->toBe('Pago com acréscimo')
        ->and($payment->user_id)->toBe($user->id)
        ->and($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue();
});

test('the modal warns and the toast tells when the payment reactivates the card', function () {
    actingAsRole('financeiro');
    $card = Card::factory()->suspended()->create();
    $invoice = Invoice::factory()->overdue(35)->create(['customer_id' => $card->customer_id]);

    Livewire::test('billing.payment-modal')
        ->call('open', $invoice->id)
        ->assertSee('ele será reativado')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('notify', description: 'Cartão reativado por pagamento.');

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('an amount below the invoice shows the error on the amount field', function () {
    actingAsRole('financeiro');
    $invoice = Invoice::factory()->create(['amount' => '89.90']);

    Livewire::test('billing.payment-modal')
        ->call('open', $invoice->id)
        ->set('amount', '80,00')
        ->call('confirm')
        ->assertHasErrors(['amount' => 'O valor recebido não pode ser menor que R$ 89,90.'])
        ->assertNotDispatched('payment-registered')
        ->assertSet('invoiceId', $invoice->id);

    expect(Payment::count())->toBe(0);
});

test('only active payment methods are offered', function () {
    actingAsRole('financeiro');
    PaymentMethodModel::query()->where('slug', PaymentMethod::Boleto->value)->update(['is_active' => false]);
    $invoice = Invoice::factory()->create();

    Livewire::test('billing.payment-modal')
        ->call('open', $invoice->id)
        ->assertSee('Dinheiro')
        ->assertDontSee('Boleto')
        ->set('method', PaymentMethod::Boleto->value)
        ->call('confirm')
        ->assertHasErrors('method');
});

test('atendente gets 403 on open and on confirm', function (string $method) {
    $invoice = Invoice::factory()->create();
    actingAsRole('atendente');

    Livewire::test('billing.payment-modal')
        ->set('invoiceId', $invoice->id)
        ->call($method, ...($method === 'open' ? [$invoice->id] : []))
        ->assertForbidden();

    expect(Payment::count())->toBe(0);
})->with(['open', 'confirm']);
