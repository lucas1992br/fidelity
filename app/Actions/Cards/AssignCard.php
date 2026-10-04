<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Link a stock card to a customer. The card row is locked so two operators
 * cannot hand out the same card; only `in_stock` cards qualify and the
 * customer may hold a single current card.
 */
class AssignCard
{
    public function __construct(
        protected ChangeCardStatus $changeCardStatus,
        protected CheckCardAvailability $checkCardAvailability,
    ) {}

    /**
     * @param  CardStatusEnum  $status  Status of the linked card; the replacement keeps the old card's status.
     *
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        string $number,
        ?User $user = null,
        CardStatusEnum $status = CardStatusEnum::Active,
        string $reason = 'Vínculo ao titular',
        string $errorKey = 'card_number',
    ): Card {
        $number = trim($number);

        return DB::transaction(function () use ($customer, $number, $user, $status, $reason, $errorKey): Card {
            $card = Card::query()->where('number', $number)->lockForUpdate()->first();

            if ($card === null || ! $card->hasStatus(CardStatusEnum::InStock)) {
                throw ValidationException::withMessages([
                    $errorKey => $this->checkCardAvailability->handle($number, $user)->message,
                ]);
            }

            if ($customer->currentCard()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    $errorKey => 'O titular já possui um cartão vigente.',
                ]);
            }

            $card->forceFill([
                'customer_id' => $customer->id,
                'assigned_at' => today(),
            ]);

            $this->changeCardStatus->handle($card, $status, $reason, $user);

            return $card;
        });
    }
}
