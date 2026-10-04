<?php

namespace App\Enums;

enum Kinship: string
{
    case Spouse = 'spouse';
    case Child = 'child';
    case Father = 'father';
    case Mother = 'mother';
    case Sibling = 'sibling';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spouse => 'Cônjuge',
            self::Child => 'Filho(a)',
            self::Father => 'Pai',
            self::Mother => 'Mãe',
            self::Sibling => 'Irmão(ã)',
            self::Other => 'Outro',
        };
    }
}
