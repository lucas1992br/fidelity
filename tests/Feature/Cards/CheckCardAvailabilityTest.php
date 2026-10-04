<?php

use App\Actions\Cards\CheckCardAvailability;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;

test('each card status returns the right message', function (CardStatus $status, string $message, bool $isAvailable) {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create(['name' => 'Maria Souza']);
    $card = Card::factory()->status($status)
        ->state(in_array($status, [CardStatus::Active, CardStatus::Suspended, CardStatus::Cancelled], true) ? ['customer_id' => $customer->id] : [])
        ->create(['number' => '000123']);

    $result = app(CheckCardAvailability::class)->handle(' 000123 ', $user);

    expect($result->message)->toBe($message)
        ->and($result->isAvailable())->toBe($isAvailable);
})->with([
    'in stock' => [CardStatus::InStock, 'Disponível', true],
    'active' => [CardStatus::Active, 'Já vinculado a Maria Souza', false],
    'suspended' => [CardStatus::Suspended, 'Já vinculado a Maria Souza', false],
    'cancelled' => [CardStatus::Cancelled, 'Já vinculado a Maria Souza', false],
    'lost' => [CardStatus::Lost, 'Extraviado/Inutilizado', false],
    'discarded' => [CardStatus::Discarded, 'Extraviado/Inutilizado', false],
]);

test('an unknown number is not in stock', function () {
    $user = actingAsRole('atendente');

    $result = app(CheckCardAvailability::class)->handle('999999', $user);

    expect($result->message)->toBe('Não cadastrado no estoque')
        ->and($result->isAvailable())->toBeFalse();
});

test('the atendente never sees the name of the linked customer', function () {
    $customer = Customer::factory()->create(['name' => 'Maria Souza']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);

    $atendente = actingAsRole('atendente');
    $financeiro = actingAsRole('financeiro');

    expect(app(CheckCardAvailability::class)->handle('000123', $atendente)->message)->toBe('Já vinculado')
        ->and(app(CheckCardAvailability::class)->handle('000123', $financeiro)->message)->toBe('Já vinculado a Maria Souza');
});
