<?php

namespace App\Reports;

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Exports\EntriesExport;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Queries\FinancialEntriesQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Financial entries due in the period by category, pending or paid; cancelled
 * and soft-deleted entries never count (FinancialEntriesQuery on due date).
 *
 * @phpstan-type CategoryTotals array{category: string, type: EntryType, count: int, amount: string, paid: string}
 * @phpstan-type Totals array{categories: list<CategoryTotals>, income: string, expense: string}
 */
class EntriesReport extends Report
{
    public static function key(): string
    {
        return 'lancamentos';
    }

    public static function title(): string
    {
        return 'Lançamentos por categoria';
    }

    public static function description(): string
    {
        return 'Receitas e despesas do período por categoria, sem cancelados.';
    }

    public static function filters(): array
    {
        return [self::FilterPeriod];
    }

    /**
     * Entries of the period without the cancelled ones, unordered.
     *
     * @return Builder<FinancialEntry>
     */
    protected function entries(): Builder
    {
        return (new FinancialEntriesQuery(
            periodBy: FinancialEntriesQuery::PeriodByDueDate,
            from: $this->from->toDateString(),
            until: $this->until->toDateString(),
        ))->builder()->where('entry_status_id', '<>', EntryStatus::Cancelled->id());
    }

    /**
     * By type (income first), category name and due date.
     *
     * @return Builder<FinancialEntry>
     */
    public function query(): Builder
    {
        return $this->entries()
            ->with('category:id,name')
            ->orderByRaw('entry_type_id = ? desc', [EntryType::Income->id()])
            ->orderBy(FinancialCategory::select('name')->whereColumn('financial_categories.id', 'financial_entries.financial_category_id'))
            ->orderBy('due_date')
            ->orderBy('id');
    }

    public function rowCount(): int
    {
        return $this->entries()->count();
    }

    /**
     * Count, amount and paid amount per category, plus the income and expense
     * totals (the per-type grouping set), in a single aggregate.
     *
     * @return Totals
     */
    public function totals(): array
    {
        $rows = $this->entries()
            ->toBase()
            ->join('financial_categories', 'financial_categories.id', '=', 'financial_entries.financial_category_id')
            ->selectRaw(
                'financial_categories.name as category, financial_entries.entry_type_id,
                 count(*) as count,
                 sum(financial_entries.amount)::numeric(14, 2) as amount,
                 coalesce(sum(financial_entries.amount) filter (where financial_entries.entry_status_id = ?), 0)::numeric(14, 2) as paid',
                [EntryStatus::Paid->id()],
            )
            ->groupByRaw('grouping sets ((financial_entries.entry_type_id, financial_categories.name), (financial_entries.entry_type_id))')
            ->orderByRaw('financial_entries.entry_type_id = ? desc', [EntryType::Income->id()])
            ->orderBy('financial_categories.name')
            ->get();

        $typeTotal = fn (EntryType $type): string => (string) ($rows
            ->first(fn (object $row): bool => $row->category === null && (int) $row->entry_type_id === $type->id())
            ->amount ?? '0.00');

        return [
            'categories' => array_values($rows
                ->filter(fn (object $row): bool => $row->category !== null)
                ->map(fn (object $row): array => [
                    'category' => (string) $row->category,
                    'type' => EntryType::fromId((int) $row->entry_type_id),
                    'count' => (int) $row->count,
                    'amount' => (string) $row->amount,
                    'paid' => (string) $row->paid,
                ])
                ->all()),
            'income' => $typeTotal(EntryType::Income),
            'expense' => $typeTotal(EntryType::Expense),
        ];
    }

    public function pdfData(): array
    {
        return [
            'entries' => $this->query()->get(),
            'totals' => $this->totals(),
        ];
    }

    public function export(): EntriesExport
    {
        return new EntriesExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.entries';
    }
}
