<?php

namespace App\Support\Billing;

use App\Enums\CardStatus;
use App\Queries\DelinquentsQuery;
use Carbon\CarbonImmutable;

/**
 * One line of the Inadimplentes listing: an individual holder with overdue
 * invoices, or a company with overdue bills (in place of its employees).
 * Built from the rows of {@see DelinquentsQuery::rows()}.
 */
final readonly class DelinquentRow
{
    public const string KindHolder = 'holder';

    public const string KindCompany = 'company';

    public function __construct(
        public string $kind,
        public int $id,
        public string $name,
        public ?string $phone,
        public int $overdueCount,
        public string $overdueAmount,
        public CarbonImmutable $oldestDueDate,
        public int $maxDaysOverdue,
        public ?CardStatus $cardStatus,
        public int $employees,
    ) {}

    public static function fromRecord(object $record): self
    {
        return new self(
            kind: $record->kind,
            id: (int) $record->id,
            name: $record->name,
            phone: $record->phone,
            overdueCount: (int) $record->overdue_invoices_count,
            overdueAmount: (string) $record->overdue_amount,
            oldestDueDate: CarbonImmutable::parse($record->oldest_due_date),
            maxDaysOverdue: (int) $record->max_days_overdue,
            cardStatus: $record->card_status_id !== null ? CardStatus::fromId((int) $record->card_status_id) : null,
            employees: (int) $record->employees,
        );
    }

    public function isCompany(): bool
    {
        return $this->kind === self::KindCompany;
    }

    /**
     * Sheet of the holder or of the company (on its bills tab).
     */
    public function url(): string
    {
        return $this->isCompany()
            ? route('companies.show', ['company' => $this->id, 'tab' => 'faturas'])
            : route('customers.show', $this->id);
    }

    /**
     * Card status label ("Suspenso", "Sem cartão") or, for a company, how
     * many employees are late ("3 funcionários em atraso").
     */
    public function situation(): string
    {
        if ($this->isCompany()) {
            return $this->employees === 1 ? '1 funcionário em atraso' : "{$this->employees} funcionários em atraso";
        }

        return $this->cardStatus?->label() ?? 'Sem cartão';
    }
}
