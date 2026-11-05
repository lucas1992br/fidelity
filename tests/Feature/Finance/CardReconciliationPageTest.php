<?php

use App\Enums\CardTransactionResult;
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
