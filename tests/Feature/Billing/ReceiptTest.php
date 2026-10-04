<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Settings\ClinicSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Payment of a holder with a current card, receipt number loaded.
 */
function receiptPayment(): Payment
{
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->paid()->create(['amount' => '89.90']);

    return Payment::factory()->for($invoice)->create()->refresh();
}

test('the receipt is streamed as a PDF named after the receipt number', function (string $role) {
    actingAsRole($role);
    $payment = receiptPayment();

    $response = $this->get(route('receipts.show', $payment));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain("recibo-{$payment->receipt_number}.pdf")
        ->and($response->getContent())->toStartWith('%PDF');
})->with(['admin', 'financeiro']);

test('the receipt view shows the clinic, holder, card, reference and the amount in words', function () {
    settings(['clinic_name' => 'Clínica Sorriso', 'clinic_cnpj' => '12.345.678/0001-90']);
    $payment = receiptPayment();
    $customer = $payment->invoice->customer;

    $html = view('pdf.receipt', [
        'payment' => $payment,
        'invoice' => $payment->invoice,
        'customer' => $customer,
        'card' => $customer->currentCard,
        'method' => PaymentMethod::fromId($payment->payment_method_id),
        'clinic' => app(ClinicSettings::class),
    ])->render();

    expect($html)->toContain('Clínica Sorriso')
        ->toContain('12.345.678/0001-90')
        ->toContain("Recibo nº {$payment->receipt_number}")
        ->toContain('Maria Aparecida Souza')
        ->toContain($customer->formatted_cpf)
        ->toContain($customer->currentCard->number)
        ->toContain('10/2026')
        ->toContain('R$ 89,90')
        ->toContain('oitenta e nove reais e noventa centavos')
        ->toContain('PIX');
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    $this->get(route('receipts.show', receiptPayment()))->assertForbidden();
});

test('no file is written to the storage', function () {
    Storage::fake('local');
    Storage::fake('public');
    actingAsRole('financeiro');

    $this->get(route('receipts.show', receiptPayment()))->assertOk();

    expect(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});
