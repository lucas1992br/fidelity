<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Mark a damaged stock card as `discarded`. Only `in_stock` cards qualify and a
 * reason is mandatory; a discarded card never returns to stock.
 */
class DiscardCard
{
    public function __construct(protected ChangeCardStatus $changeCardStatus) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Card $card, ?string $reason): Card
    {
        if (! $user->can('cards.stock.manage')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['reason' => trim((string) $reason)], [
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'motivo'])->validate();

        return DB::transaction(function () use ($user, $card, $validated): Card {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);

            if (! $card->hasStatus(CardStatusEnum::InStock)) {
                throw ValidationException::withMessages([
                    'reason' => 'Só cartões em estoque podem ser inutilizados.',
                ]);
            }

            $this->changeCardStatus->handle($card, CardStatusEnum::Discarded, $validated['reason'], $user);

            return $card;
        });
    }
}
