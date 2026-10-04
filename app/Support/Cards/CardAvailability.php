<?php

namespace App\Support\Cards;

use App\Actions\Cards\CheckCardAvailability;

/**
 * Result of {@see CheckCardAvailability}: the state of the
 * typed card number and the message shown to the operator.
 */
final readonly class CardAvailability
{
    public const string Available = 'available';

    public const string Linked = 'linked';

    public const string Unusable = 'unusable';

    public const string NotFound = 'not_found';

    public function __construct(
        public string $state,
        public string $message,
    ) {}

    /**
     * Only stock cards can be linked to a customer.
     */
    public function isAvailable(): bool
    {
        return $this->state === self::Available;
    }

    /**
     * Tone understood by the `x-ui.callout` component.
     */
    public function tone(): string
    {
        return match ($this->state) {
            self::Available => 'success',
            self::Linked => 'danger',
            default => 'warning',
        };
    }
}
