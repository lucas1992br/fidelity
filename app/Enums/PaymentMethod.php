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

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Cash => 'Dinheiro',
            self::Card => 'Cartão',
            self::Boleto => 'Boleto',
        };
    }
}
