<?php

namespace App\Enums;

enum PaymentMethod: string
{
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
