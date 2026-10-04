<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesLookup;

enum Kinship: string
{
    use ResolvesLookup;

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
