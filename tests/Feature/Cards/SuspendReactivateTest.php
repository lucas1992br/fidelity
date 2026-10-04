<?php

use App\Actions\Cards\ReactivateCard;
use App\Actions\Cards\SuspendCard;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use Illuminate\Validation\ValidationException;

test('suspending an active card logs the reason and the user', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->active()->create();

    app(SuspendCard::class)->handle($card, ' Solicitação do titular ', $user);

    $card->refresh();
    $log = $card->statusLogs()->sole();

    expect($card->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($card->is_current)->toBeTrue()
        ->and($log->from_card_status_id)->toBe(CardStatus::Active->id())
        ->and($log->to_card_status_id)->toBe(CardStatus::Suspended->id())
        ->and($log->reason)->toBe('Solicitação do titular')
        ->and($log->user_id)->toBe($user->id);
});

test('suspending a card that is not active fails', function (CardStatus $status) {
    $card = Card::factory()->status($status)->create();

    expect(fn () => app(SuspendCard::class)->handle($card, 'Motivo'))
        ->toThrow(ValidationException::class, 'Só cartões ativos podem ser suspensos.');

    expect($card->refresh()->hasStatus($status))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
})->with([
    'suspended' => [CardStatus::Suspended],
    'cancelled' => [CardStatus::Cancelled],
    'in stock' => [CardStatus::InStock],
]);

test('reactivating a suspended card logs the reason and the user', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->suspended()->create();

    app(ReactivateCard::class)->handle($card, 'Acordo de pagamento', $user);

    $log = $card->refresh()->statusLogs()->sole();

    expect($card->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($log->reason)->toBe('Acordo de pagamento')
        ->and($log->user_id)->toBe($user->id);
});

test('the payment reactivates without a user and with the fixed reason', function () {
    $card = Card::factory()->suspended()->create();

    app(ReactivateCard::class)->handle($card, ReactivateCard::PaymentReason);

    $log = $card->refresh()->statusLogs()->sole();

    expect($card->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($log->reason)->toBe('Reativação por pagamento')
        ->and($log->user_id)->toBeNull();
});

test('reactivating a cancelled card fails because the cancellation is final', function () {
    $user = actingAsRole('admin');
    $card = Card::factory()->cancelled()->create();

    expect(fn () => app(ReactivateCard::class)->handle($card, 'Pedido do titular', $user))
        ->toThrow(ValidationException::class, 'O cancelamento é definitivo');

    expect($card->refresh()->hasStatus(CardStatus::Cancelled))->toBeTrue()
        ->and($card->is_current)->toBeFalse()
        ->and($card->statusLogs()->count())->toBe(0);
});

test('reactivating an active card fails', function () {
    $card = Card::factory()->active()->create();

    expect(fn () => app(ReactivateCard::class)->handle($card, 'Motivo'))
        ->toThrow(ValidationException::class, 'Só cartões suspensos podem ser reativados.');
});

test('an empty reason is rejected', function (string $action, CardStatus $status) {
    $card = Card::factory()->status($status)->create();

    expect(fn () => app($action)->handle($card, '   '))
        ->toThrow(ValidationException::class);

    expect($card->refresh()->hasStatus($status))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
})->with([
    'suspend' => [SuspendCard::class, CardStatus::Active],
    'reactivate' => [ReactivateCard::class, CardStatus::Suspended],
]);

test('dependents follow the status of the holder card', function () {
    $customer = Customer::factory()->create();
    $card = Card::factory()->active()->for($customer)->create(['number' => '000123']);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 2]);

    app(SuspendCard::class)->handle($card, 'Solicitação do titular');

    $holderCard = $dependent->refresh()->customer->currentCard;

    expect($dependent->identification)->toBe('000123-02')
        ->and($holderCard->hasStatus(CardStatus::Suspended))->toBeTrue();
});
