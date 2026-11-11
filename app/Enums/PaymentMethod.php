<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesLookup;

enum PaymentMethod: string
{
    use ResolvesLookup;

    case Pix = 'pix';
    case Cash = 'cash';
    case Card = 'card';
    case Boleto = 'boleto';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Cash => 'Dinheiro',
            self::Card => 'Cartão',
            self::Boleto => 'Boleto',
            self::Recurring => 'Cartão recorrente',
        };
    }

    /**
     * Whether an invoice paid with this method may be split into installments.
     */
    public function allowsInstallments(): bool
    {
        return in_array($this, [self::Card, self::Boleto], true);
    }

    /**
     * Whether the payment is charged on the payer's credit card, so the first
     * 6 and last 4 digits may be recorded for the card reconciliation. The
     * recurring card is charged monthly outside the system and only settled here.
     */
    public function isCreditCard(): bool
    {
        return in_array($this, [self::Card, self::Recurring], true);
    }
}
