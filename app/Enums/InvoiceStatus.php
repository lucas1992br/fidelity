<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Overdue => 'Em atraso',
            self::Paid => 'Pago',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Badge tone understood by the `x-ui.badge` component.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Overdue => 'red',
            self::Paid => 'green',
            self::Cancelled => 'gray',
        };
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::Open, self::Overdue], true);
    }
}
