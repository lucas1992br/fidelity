<?php

use App\Actions\Cards\ReplaceCard;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use Livewire\Livewire;

test('confirming with a stock card replaces the current card', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $old = Card::factory()->suspended()->for($customer)->create(['number' => '000123']);
    Card::factory()->inStock()->create(['number' => '000456']);

    Livewire::test('cards.replace-card-modal', ['customerId' => $customer->id])
        ->call('open')
        ->assertSee('cartão atual 000123')
        ->assertSee('O novo cartão herda o status do atual')
        ->assertDontSee('Em estoque agora')
        ->set('reason', 'discarded')
        ->set('number', '000456')
        ->assertSee('Disponível')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false)
        ->assertDispatched('notify', title: 'Novo cartão vinculado', description: '000456 substitui 000123. Dependentes atualizados.')
        ->assertDispatched('customer-card-updated');

    $new = $customer->refresh()->currentCard;

    expect($new->number)->toBe('000456')
        ->and($new->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($old->refresh()->hasStatus(CardStatus::Discarded))->toBeTrue()
        ->and($old->statusLogs()->sole()->user_id)->toBe($user->id);
});

test('confirming with an invalid card changes nothing', function (string $number, string $message) {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Card::factory()->lost()->create(['number' => '000789']);

    Livewire::test('cards.replace-card-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('number', $number)
        ->assertSee($message)
        ->call('confirm')
        ->assertHasErrors(['number' => $message])
        ->assertSet('isOpen', true)
        ->assertNotDispatched('customer-card-updated');

    expect($old->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($old->statusLogs()->count())->toBe(0)
        ->and($customer->cards()->count())->toBe(1);
})->with([
    'unknown' => ['999999', 'Não cadastrado no estoque'],
    'lost' => ['000789', 'Extraviado/Inutilizado'],
]);

test('the confirm button stays disabled until the card is available', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    $component = Livewire::test('cards.replace-card-modal', ['customerId' => $customer->id])->call('open');

    expect($component->get('cardAvailability'))->toBeNull();

    $component->set('number', '000456');

    expect($component->get('cardAvailability')->isAvailable())->toBeTrue();
});

test('atendente gets 403 on confirm', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create();
    Card::factory()->inStock()->create(['number' => '000456']);
    $component = Livewire::test('cards.replace-card-modal', ['customerId' => $customer->id])
        ->call('open')
        ->set('number', '000456');

    actingAsRole('atendente');

    $component->call('confirm')->assertForbidden();

    expect($old->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('the sheet mounts the modal and reloads the card after the replacement', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    Card::factory()->active()->for($customer)->create(['number' => '000123']);

    $sheet = Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSeeLivewire('cards.replace-card-modal')
        ->assertSee('Cartão 000123');

    Card::factory()->inStock()->create(['number' => '000456']);
    app(ReplaceCard::class)->handle($customer, '000456', 'lost');

    $sheet->dispatch('customer-card-updated')
        ->assertSee('Cartão 000456')
        ->assertSee('Cartões anteriores');
});
