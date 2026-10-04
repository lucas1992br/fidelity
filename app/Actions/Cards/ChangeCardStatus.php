<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\CardStatus;
use App\Models\CardStatusLog;
use App\Models\User;

/**
 * Single place where a card changes status (internal use by the card Actions).
 *
 * Mirrors `is_current` from the `card_statuses` lookup (kept denormalized for
 * the partial unique index), stamps `status_changed_at` and writes the
 * `card_status_logs` row. It opens no transaction: it runs inside the caller's.
 */
class ChangeCardStatus
{
    /**
     * @param  User|null  $user  Who made the change; null means the system.
     */
    public function handle(Card $card, CardStatusEnum $status, ?string $reason = null, ?User $user = null): CardStatusLog
    {
        $fromStatusId = $card->card_status_id;

        $card->forceFill([
            'card_status_id' => $status->id(),
            'is_current' => self::isCurrent($status),
            'status_changed_at' => now(),
        ])->save();

        return CardStatusLog::create([
            'card_id' => $card->id,
            'from_card_status_id' => $fromStatusId,
            'to_card_status_id' => $status->id(),
            'reason' => filled($reason) ? $reason : null,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * Whether the status keeps the card current, read from the lookup row.
     */
    public static function isCurrent(CardStatusEnum $status): bool
    {
        return (bool) CardStatus::query()->whereKey($status->id())->value('is_current');
    }
}
