<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\User;
use App\Support\Cards\CardAvailability;

/**
 * Real-time check of a typed (or scanned) card number. Only `in_stock` cards
 * are available; the holder's name of a linked card is shown only to users
 * with `customers.view`, so the atendente never sees third-party data.
 */
class CheckCardAvailability
{
    public function handle(string $number, ?User $user): CardAvailability
    {
        $card = Card::query()->with('customer:id,name')->where('number', trim($number))->first();

        if ($card === null) {
            return new CardAvailability(CardAvailability::NotFound, 'Não cadastrado no estoque');
        }

        return match (CardStatusEnum::fromId($card->card_status_id)) {
            CardStatusEnum::InStock => new CardAvailability(CardAvailability::Available, 'Disponível'),
            CardStatusEnum::Active, CardStatusEnum::Suspended, CardStatusEnum::Cancelled => new CardAvailability(
                CardAvailability::Linked,
                $user?->can('customers.view') && $card->customer !== null
                    ? "Já vinculado a {$card->customer->name}"
                    : 'Já vinculado',
            ),
            CardStatusEnum::Lost, CardStatusEnum::Discarded => new CardAvailability(CardAvailability::Unusable, 'Extraviado/Inutilizado'),
        };
    }
}
