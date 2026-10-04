<?php

use App\Support\Prototype\DemoData;
use Livewire\Livewire;

/**
 * Id of a prototype invoice that still accepts a payment.
 */
function payableInvoiceId(): int
{
    return DemoData::invoices()->first(fn (array $invoice): bool => $invoice['status']->isPayable())['id'];
}

test('atendente calling confirm on the payment modal gets 403', function () {
    actingAsRole('atendente');

    Livewire::test('billing.payment-modal')
        ->set('invoiceId', payableInvoiceId())
        ->call('confirm')
        ->assertForbidden();
});

test('atendente cannot open the payment modal', function () {
    actingAsRole('atendente');

    Livewire::test('billing.payment-modal')
        ->call('open', payableInvoiceId())
        ->assertForbidden();
});

test('financeiro opens the payment modal', function () {
    actingAsRole('financeiro');

    Livewire::test('billing.payment-modal')
        ->call('open', payableInvoiceId())
        ->assertOk()
        ->assertSet('invoiceId', payableInvoiceId());
});

test('atendente cannot open or confirm the replace card modal', function (string $method) {
    actingAsRole('atendente');

    Livewire::test('cards.replace-card-modal', ['customerId' => 1])
        ->call($method)
        ->assertForbidden();
})->with(['open', 'confirm']);

test('atendente mounting the dashboard gets 403', function () {
    actingAsRole('atendente');

    Livewire::test('pages::dashboard')->assertForbidden();
});

test('mounting the quick search requires cards.check', function () {
    Livewire::test('pages::quick-search')->assertForbidden();

    actingAsRole('atendente');

    Livewire::test('pages::quick-search')->assertOk();
});
