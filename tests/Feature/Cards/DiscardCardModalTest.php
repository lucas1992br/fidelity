<?php

use App\Enums\CardStatus;
use App\Models\Card;
use Livewire\Livewire;

test('the modal shows the number and discards the card with the reason', function () {
    actingAsRole('financeiro');
    $card = Card::factory()->inStock()->create(['number' => '000179']);

    Livewire::test('cards.discard-card-modal')
        ->call('open', $card->id)
        ->assertSet('number', '000179')
        ->assertSee('000179')
        ->set('reason', 'Impressão borrada')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('notify')
        ->assertDispatched('card-stock-updated');

    expect($card->refresh()->hasStatus(CardStatus::Discarded))->toBeTrue()
        ->and($card->statusLogs()->sole()->reason)->toBe('Impressão borrada');
});

test('an empty reason keeps the modal open with the error', function () {
    actingAsRole('financeiro');
    $card = Card::factory()->inStock()->create();

    Livewire::test('cards.discard-card-modal')
        ->call('open', $card->id)
        ->call('confirm')
        ->assertHasErrors('reason')
        ->assertSet('isOpen', true);

    expect($card->refresh()->hasStatus(CardStatus::InStock))->toBeTrue();
});

test('a user without cards.stock.manage cannot open or confirm', function (string $method) {
    actingAsRole('financeiro');
    $card = Card::factory()->inStock()->create();
    $component = Livewire::test('cards.discard-card-modal')
        ->call('open', $card->id)
        ->set('reason', 'Danificado');

    actingAsRole('atendente');

    $component->call($method, ...($method === 'open' ? [$card->id] : []))->assertForbidden();

    expect($card->refresh()->hasStatus(CardStatus::InStock))->toBeTrue();
})->with(['open', 'confirm']);

test('the stock page mounts the discard modal', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::cards.stock')->assertSeeLivewire('cards.discard-card-modal');
});
