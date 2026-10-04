<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Manually suspend the holder's current card. Only an `active` current card
 * qualifies and a reason is mandatory; dependents follow the holder's card,
 * so they are suspended too.
 */
class SuspendCard
{
    public function __construct(protected ChangeCardStatus $changeCardStatus) {}

    /**
     * @throws ValidationException
     */
    public function handle(Card $card, ?string $reason, ?User $user = null): Card
    {
        $validated = Validator::make(['reason' => trim((string) $reason)], [
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'motivo'])->validate();

        return DB::transaction(function () use ($card, $validated, $user): Card {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);

            if (! $card->is_current || ! $card->hasStatus(CardStatusEnum::Active)) {
                throw ValidationException::withMessages([
                    'reason' => 'Só cartões ativos podem ser suspensos.',
                ]);
            }

            $this->changeCardStatus->handle($card, CardStatusEnum::Suspended, $validated['reason'], $user);

            return $card;
        });
    }
}
