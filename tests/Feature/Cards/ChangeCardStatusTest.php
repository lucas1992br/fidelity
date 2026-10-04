<?php

use App\Actions\Cards\ChangeCardStatus;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\User;

test('is_current mirrors the lookup: suspended keeps it true and lost sets it false', function () {
    $card = Card::factory()->active()->create();

    app(ChangeCardStatus::class)->handle($card, CardStatus::Suspended, 'Suspensão manual');

    expect($card->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($card->is_current)->toBeTrue()
        ->and($card->status_changed_at)->not->toBeNull();

    app(ChangeCardStatus::class)->handle($card, CardStatus::Lost, 'Perda ou roubo');

    expect($card->refresh()->hasStatus(CardStatus::Lost))->toBeTrue()
        ->and($card->is_current)->toBeFalse();
});

test('each change writes a log with from, to, reason and user', function () {
    $user = User::factory()->create();
    $card = Card::factory()->active()->create();

    app(ChangeCardStatus::class)->handle($card, CardStatus::Suspended, 'Suspensão automática: 32 dias em atraso');
    app(ChangeCardStatus::class)->handle($card, CardStatus::Active, 'Reativação manual', $user);

    $logs = $card->statusLogs()->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->from_card_status_id)->toBe(CardStatus::Active->id())
        ->and($logs[0]->to_card_status_id)->toBe(CardStatus::Suspended->id())
        ->and($logs[0]->reason)->toBe('Suspensão automática: 32 dias em atraso')
        ->and($logs[0]->user_id)->toBeNull()
        ->and($logs[1]->from_card_status_id)->toBe(CardStatus::Suspended->id())
        ->and($logs[1]->to_card_status_id)->toBe(CardStatus::Active->id())
        ->and($logs[1]->reason)->toBe('Reativação manual')
        ->and($logs[1]->user_id)->toBe($user->id);
});
