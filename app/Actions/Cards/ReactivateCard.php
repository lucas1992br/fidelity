<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reactivate a `suspended` card, manually (with the operator's reason) or by
 * the payment routine (no user, fixed reason). A cancelled card is never
 * reactivated: the cancellation is final.
 */
class ReactivateCard
{
    public const string PaymentReason = 'Reativação por pagamento';

    public function __construct(protected ChangeCardStatus $changeCardStatus) {}

    /**
     * @param  User|null  $user  Who reactivated; null means the system (payment).
     *
     * @throws ValidationException
     */
    public function handle(Card $card, ?string $reason, ?User $user = null): Card
    {
        $validated = Validator::make(['reason' => trim((string) $reason)], [
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'motivo'])->validate();

        return DB::transaction(function () use ($card, $validated, $user): Card {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);

            if ($card->hasStatus(CardStatusEnum::Cancelled)) {
                throw ValidationException::withMessages([
                    'reason' => 'O cancelamento é definitivo: cartão cancelado não pode ser reativado.',
                ]);
            }

            if (! $card->hasStatus(CardStatusEnum::Suspended)) {
                throw ValidationException::withMessages([
                    'reason' => 'Só cartões suspensos podem ser reativados.',
                ]);
            }

            $this->changeCardStatus->handle($card, CardStatusEnum::Active, $validated['reason'], $user);

            return $card;
        });
    }
}
