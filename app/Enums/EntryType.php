<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesLookup;

enum EntryType: string
{
    use ResolvesLookup;

    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Receita',
            self::Expense => 'Despesa',
        };
    }
}
