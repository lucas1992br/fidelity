<?php

namespace App\Enums;

/**
 * Outcome of an imported card transaction: `paid` settled an invoice or a
 * company bill, `pending` still needs a payer or a fix, `ignored` was not
 * approved by the acquirer and never settles anything.
 */
enum CardTransactionResult: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Baixado',
            self::Pending => 'Pendente',
            self::Ignored => 'Ignorado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Pending => 'amber',
            self::Ignored => 'gray',
        };
    }
}
