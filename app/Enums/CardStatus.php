<?php

namespace App\Enums;

enum CardStatus: string
{
    case InStock = 'in_stock';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Lost = 'lost';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Em estoque',
            self::Active => 'Ativo',
            self::Suspended => 'Suspenso',
            self::Cancelled => 'Cancelado',
            self::Lost => 'Extraviado',
            self::Discarded => 'Inutilizado',
        };
    }

    /**
     * Badge tone understood by the `x-ui.badge` component.
     */
    public function color(): string
    {
        return match ($this) {
            self::InStock => 'blue',
            self::Active => 'green',
            self::Suspended => 'amber',
            self::Cancelled => 'red',
            self::Lost, self::Discarded => 'gray',
        };
    }

    /**
     * Statuses in which the card is linked and current for the customer.
     *
     * @return list<self>
     */
    public static function current(): array
    {
        return [self::Active, self::Suspended];
    }
}
