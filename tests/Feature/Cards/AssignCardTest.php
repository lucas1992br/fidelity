<?php

use App\Actions\Cards\AssignCard;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

test('a stock card becomes active, current, assigned today and logged', function () {
    $user = actingAsRole('atendente');
    $customer = Customer::factory()->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    $card = app(AssignCard::class)->handle($customer, ' 000123 ', $user);

    $card->refresh();
    $log = $card->statusLogs()->sole();

    expect($card->customer_id)->toBe($customer->id)
        ->and($card->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($card->is_current)->toBeTrue()
        ->and($card->assigned_at->isToday())->toBeTrue()
        ->and($log->from_card_status_id)->toBe(CardStatus::InStock->id())
        ->and($log->to_card_status_id)->toBe(CardStatus::Active->id())
        ->and($log->user_id)->toBe($user->id)
        ->and($customer->currentCard->is($card))->toBeTrue();
});

test('the given status is used instead of active', function () {
    $customer = Customer::factory()->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    $card = app(AssignCard::class)->handle($customer, '000123', null, CardStatus::Suspended);

    expect($card->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($card->is_current)->toBeTrue();
});

test('a card that is not in stock is rejected with the availability message', function (CardStatus $status, string $message) {
    $user = actingAsRole('atendente');
    $customer = Customer::factory()->create();
    $card = Card::factory()->status($status)->create(['number' => '000123']);

    expect(fn () => app(AssignCard::class)->handle($customer, '000123', $user))
        ->toThrow(ValidationException::class, $message);

    expect($card->refresh()->card_status_id)->toBe($status->id())
        ->and($card->statusLogs()->count())->toBe(0);
})->with([
    'already linked' => [CardStatus::Active, 'Já vinculado'],
    'lost' => [CardStatus::Lost, 'Extraviado/Inutilizado'],
    'discarded' => [CardStatus::Discarded, 'Extraviado/Inutilizado'],
]);

test('an unknown card is rejected', function () {
    $customer = Customer::factory()->create();

    expect(fn () => app(AssignCard::class)->handle($customer, '999999'))
        ->toThrow(ValidationException::class, 'Não cadastrado no estoque');
});

test('a second current card for the same customer is blocked', function () {
    $customer = Customer::factory()->withCard()->create();
    $card = Card::factory()->inStock()->create(['number' => '000456']);

    expect(fn () => app(AssignCard::class)->handle($customer, '000456'))
        ->toThrow(ValidationException::class, 'O titular já possui um cartão vigente.');

    expect($card->refresh()->hasStatus(CardStatus::InStock))->toBeTrue()
        ->and($customer->cards()->count())->toBe(1);
});
