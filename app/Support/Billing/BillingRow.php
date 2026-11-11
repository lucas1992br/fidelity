<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Queries\InvoicesQuery;
use Carbon\CarbonImmutable;

/**
 * One line of the Mensalidades listing and report: either an individual
 * holder's invoice or a company's consolidated bill of a reference month
 * (which replaces its employees' invoices). Built from the rows of
 * {@see InvoicesQuery::rows()}.
 */
final readonly class BillingRow
{
    public const string KindInvoice = 'invoice';

    public const string KindCompanyBill = 'company';

    public function __construct(
        public string $kind,
        public int $id,
        public ?int $customerId,
        public ?int $companyId,
        public string $name,
        public ?string $cardNumber,
        public ?string $planName,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $dueDate,
        public string $amount,
        public InvoiceStatus $status,
        public ?CarbonImmutable $paidAt,
        public ?int $paymentId,
        public int $employees,
    ) {}

    public static function fromRecord(object $record): self
    {
        return new self(
            kind: $record->kind,
            id: (int) $record->id,
            customerId: $record->customer_id !== null ? (int) $record->customer_id : null,
            companyId: $record->company_id !== null ? (int) $record->company_id : null,
            name: $record->name,
            cardNumber: $record->card_number,
            planName: $record->plan_name,
            referenceMonth: CarbonImmutable::parse($record->reference_month),
            dueDate: CarbonImmutable::parse($record->due_date),
            amount: (string) $record->amount,
            status: InvoiceStatus::fromId((int) $record->invoice_status_id),
            paidAt: $record->paid_at !== null ? CarbonImmutable::parse($record->paid_at) : null,
            paymentId: $record->payment_id !== null ? (int) $record->payment_id : null,
            employees: (int) $record->employees,
        );
    }

    public function isCompanyBill(): bool
    {
        return $this->kind === self::KindCompanyBill;
    }

    /**
     * Days past the due date while still unpaid; 0 otherwise.
     */
    public function daysOverdue(): int
    {
        if (! $this->status->isPayable() || ! $this->dueDate->lt(CarbonImmutable::today())) {
            return 0;
        }

        return (int) $this->dueDate->diffInDays(CarbonImmutable::today());
    }
}
