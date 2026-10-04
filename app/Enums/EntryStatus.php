<?php

namespace App\Enums;

use App\Enums\Concerns\ResolvesLookup;

enum EntryStatus: string
{
    use ResolvesLookup;

    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Pago/Recebido',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Badge tone understood by the `x-ui.badge` component.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Paid => 'green',
            self::Cancelled => 'gray',
        };
    }
}
