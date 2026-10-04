<?php

namespace App\Support\Customers;

use App\Services\CustomerTimeline;
use Carbon\CarbonInterface;

/**
 * One event of the {@see CustomerTimeline}; `user` is the responsible
 * user's name, or "sistema" when the routines did it.
 */
final readonly class TimelineEvent
{
    public const string SYSTEM_USER = 'sistema';

    public function __construct(
        public CarbonInterface $date,
        public string $type,
        public string $title,
        public ?string $description,
        public string $user,
    ) {}

    /**
     * Tailwind class of the timeline dot, by event type.
     */
    public function dotClass(): string
    {
        return match ($this->type) {
            CustomerTimeline::ENROLLMENT, CustomerTimeline::REENROLLMENT => 'bg-guardian-600',
            CustomerTimeline::PLAN_CHANGE, CustomerTimeline::INVOICE_ISSUED => 'bg-guardian-400',
            CustomerTimeline::CARD_LINKED, CustomerTimeline::CARD_REACTIVATED, CustomerTimeline::PAYMENT => 'bg-success-500',
            CustomerTimeline::CARD_REPLACED => 'bg-embrace-400',
            CustomerTimeline::CARD_SUSPENDED, CustomerTimeline::REFUND => 'bg-warning-500',
            CustomerTimeline::CARD_CANCELLED => 'bg-danger-500',
            default => 'bg-ink-400',
        };
    }
}
