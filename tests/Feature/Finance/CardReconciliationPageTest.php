<?php

use App\Enums\CardTransactionResult;
use App\Models\CardFee;
use App\Models\CardTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentCard;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-15 10:00');
});

test('uploading the bank model file pays the known cards and lists the rest as pending', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['monthly_amount' => '145.00']);
    PaymentCard::factory()->for($customer)->create(['card_bin' => '498407', 'card_last4' => '9593']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '145.00']);

    Livewire::test('pages::finance.card-reconciliation')
        ->set('statement', UploadedFile::fake()->createWithContent('extrato.txt', file_get_contents(base_path('tests/Fixtures/granito-statement.txt'))))
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('summary.created', 5)
        ->assertSet('summary.paid', 1)
        ->assertSet('summary.pending', 4)
        ->assertSee('Vincular e dar baixa');

    expect($invoice->refresh()->paid_at)->not->toBeNull();
});

test('a file that is not a Granito statement shows the error', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::finance.card-reconciliation')
        ->set('statement', UploadedFile::fake()->createWithContent('outro.txt', "Nome;Valor\r\nMaria;10"))
        ->call('import')
        ->assertHasErrors('statement');

    expect(CardTransaction::count())->toBe(0);
});

test('a pending transaction is linked to the holder found in the search', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza', 'monthly_amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);
    $transaction = CardTransaction::factory()->create(['amount' => '89.90', 'card_bin' => '498407', 'card_last4' => '9593']);

    Livewire::test('pages::finance.card-reconciliation')
        ->call('openLink', $transaction->id)
        ->set('payerSearch', 'Aparecida')
        ->assertSee('Maria Aparecida Souza')
        ->call('link', 'customer', $customer->id)
        ->assertHasNoErrors()
        ->assertSet('linkingId', null);

    expect($transaction->refresh()->result)->toBe(CardTransactionResult::Paid)
        ->and($customer->paymentCards()->sole()->card_last4)->toBe('9593');
});

test('each transaction shows the plan value paid, the statement value, the card machine fee and the net amount', function () {
    actingAsRole('financeiro');
    CardFee::query()->where('is_debit', false)->where('installments', 3)->update(['rate' => '4.00']);
    $customer = Customer::factory()->withCard()->create(['name' => 'Maria Aparecida Souza', 'monthly_amount' => '145.00']);
    PaymentCard::factory()->for($customer)->create(['card_bin' => '498407', 'card_last4' => '9593']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '145.00']);
    $unknown = CardTransaction::factory()->create(['amount' => '200.00', 'type' => 'Crédito 3X', 'installments' => 3, 'card_bin' => '111111', 'card_last4' => '2222']);

    Livewire::test('pages::finance.card-reconciliation')
        ->set('statement', UploadedFile::fake()->createWithContent('extrato.txt', implode("\r\n", [
            'ID Granito;Data e Hora;Pdv;Tipo;Bandeira;Cartão;Meio de Captura;Documento Vinculado;Cod. Autorização;Cod. Transação;ID Pedido E-Commerce;Valor;Status',
            '1;07/10/2026 - 15:21:18;0003;Crédito 3X;Visa;498407-9593;POS;;269415;598100;;145;Aprovada',
        ])))
        ->call('import')
        ->set('result', '')
        ->assertSee(['Maria Aparecida Souza', 'R$ 145,00', 'R$ 5,80', '4,00% · Crédito 3x', 'R$ 139,20'])
        ->assertDontSee('diferença')
        ->assertSee(['R$ 200,00', 'R$ 8,00', 'R$ 192,00']);

    expect($unknown->refresh()->result)->toBe(CardTransactionResult::Pending);
});
