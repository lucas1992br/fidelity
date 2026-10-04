<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Second copy (segunda via): replace the holder's current card by a stock
 * card, in one transaction with both cards locked. The old card goes to
 * `lost` (loss/theft) or `discarded` (damage) before the new one is linked,
 * so the partial unique index never sees two current cards; the new card
 * keeps the old card's status (a suspended holder stays suspended).
 */
class ReplaceCard
{
    /**
     * @var list<string>
     */
    public const array Reasons = ['lost', 'discarded'];

    public function __construct(
        protected AssignCard $assignCard,
        protected ChangeCardStatus $changeCardStatus,
        protected CheckCardAvailability $checkCardAvailability,
    ) {}

    /**
     * @param  string  $reason  `lost` or `discarded`, the final status of the old card.
     * @return Card The new current card.
     *
     * @throws ValidationException
     */
    public function handle(Customer $customer, string $number, string $reason, ?User $user = null): Card
    {
        $validated = Validator::make([
            'card_number' => trim($number),
            'reason' => $reason,
        ], [
            'card_number' => ['required', 'string', 'max:255'],
            'reason' => ['required', Rule::in(self::Reasons)],
        ], [], [
            'card_number' => 'número do novo cartão',
            'reason' => 'motivo',
        ])->validate();

        return DB::transaction(function () use ($customer, $validated, $user): Card {
            $oldCard = $customer->currentCard()->lockForUpdate()->first();

            if ($oldCard === null) {
                throw ValidationException::withMessages([
                    'card_number' => 'O titular não possui cartão vigente para substituir.',
                ]);
            }

            $newCard = Card::query()->where('number', $validated['card_number'])->lockForUpdate()->first();

            if ($newCard === null || ! $newCard->hasStatus(CardStatusEnum::InStock)) {
                throw ValidationException::withMessages([
                    'card_number' => $this->checkCardAvailability->handle($validated['card_number'], $user)->message,
                ]);
            }

            $inheritedStatus = CardStatusEnum::fromId($oldCard->card_status_id);

            $this->changeCardStatus->handle(
                $oldCard,
                CardStatusEnum::from($validated['reason']),
                "Segunda via: substituído por {$newCard->number}",
                $user,
            );

            return $this->assignCard->handle(
                $customer,
                $newCard->number,
                $user,
                $inheritedStatus,
                "Segunda via do cartão {$oldCard->number}",
            );
        });
    }
}
