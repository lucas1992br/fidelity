<?php

use App\Actions\Cards\DiscardCard;
use App\Enums\CardStatus;
use App\Models\Card;
use Illuminate\Validation\ValidationException;

test('a stock card becomes discarded with a log and the reason', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->inStock()->create();

    app(DiscardCard::class)->handle($user, $card, '  Impressão borrada  ');

    $card->refresh();
    $log = $card->statusLogs()->sole();

    expect($card->hasStatus(CardStatus::Discarded))->toBeTrue()
        ->and($card->is_current)->toBeFalse()
        ->and($card->customer_id)->toBeNull()
        ->and($log->from_card_status_id)->toBe(CardStatus::InStock->id())
        ->and($log->to_card_status_id)->toBe(CardStatus::Discarded->id())
        ->and($log->reason)->toBe('Impressão borrada')
        ->and($log->user_id)->toBe($user->id);
});

test('an active card cannot be discarded from the stock', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->active()->create();

    expect(fn () => app(DiscardCard::class)->handle($user, $card, 'Danificado'))
        ->toThrow(ValidationException::class, 'Só cartões em estoque podem ser inutilizados.');

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
});

test('a discarded card cannot be discarded again nor return to stock', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->discarded()->create();

    expect(fn () => app(DiscardCard::class)->handle($user, $card, 'De novo'))
        ->toThrow(ValidationException::class);
});

test('an empty reason is rejected', function () {
    $user = actingAsRole('financeiro');
    $card = Card::factory()->inStock()->create();

    expect(fn () => app(DiscardCard::class)->handle($user, $card, '   '))
        ->toThrow(ValidationException::class, 'motivo');

    expect($card->refresh()->hasStatus(CardStatus::InStock))->toBeTrue();
});
