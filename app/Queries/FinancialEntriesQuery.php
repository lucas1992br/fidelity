<?php

namespace App\Queries;

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Models\FinancialEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtered financial entries (US-10.1): type, category, status and a period
 * on the due date or on the payment date. Filters that do not parse are
 * ignored, since they come from the URL. Soft-deleted entries never show.
 */
class FinancialEntriesQuery
{
    public const string PeriodByDueDate = 'due';

    public const string PeriodByPaymentDate = 'paid';

    public function __construct(
        public ?EntryType $type = null,
        public ?int $categoryId = null,
        public ?EntryStatus $status = null,
        public string $periodBy = self::PeriodByDueDate,
        public ?string $from = null,
        public ?string $until = null,
    ) {}

    /**
     * Entries matching every filter, without ordering.
     *
     * @return Builder<FinancialEntry>
     */
    public function builder(): Builder
    {
        $dateColumn = $this->periodBy === self::PeriodByPaymentDate ? 'paid_at' : 'due_date';

        return FinancialEntry::query()
            ->when($this->type, fn (Builder $query, EntryType $type) => $query->where('entry_type_id', $type->id()))
            ->when($this->categoryId, fn (Builder $query, int $categoryId) => $query->where('financial_category_id', $categoryId))
            ->when($this->status, fn (Builder $query, EntryStatus $status) => $query->where('entry_status_id', $status->id()))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->whereDate($dateColumn, '>=', $this->from))
            ->when($this->validDate($this->until), fn (Builder $query) => $query->whereDate($dateColumn, '<=', $this->until));
    }

    /**
     * Listing order: most recent due date first, with the relations shown in the table.
     *
     * @return Builder<FinancialEntry>
     */
    public function ordered(): Builder
    {
        return $this->builder()
            ->with(['category:id,name', 'payment.invoice.customer' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('due_date')
            ->orderByDesc('id');
    }

    protected function validDate(?string $date): bool
    {
        return is_string($date)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
