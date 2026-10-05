<?php

namespace App\Reports;

use App\Enums\CardStatus;
use App\Exports\CustomersExport;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Holders and their active dependents by plan and card status. The status
 * follows the customer list: the current card, or the last one when none is
 * in force. A snapshot: the period does not apply.
 *
 * @phpstan-type PlanTotals array{plan: string, holders: int, dependents: int}
 */
class CustomersReport extends Report
{
    public static function key(): string
    {
        return 'clientes';
    }

    public static function title(): string
    {
        return 'Clientes e dependentes';
    }

    public static function description(): string
    {
        return 'Titulares e dependentes ativos por plano e status do cartão.';
    }

    public static function filters(): array
    {
        return [self::FilterPlan, self::FilterStatus];
    }

    public static function statusOptions(): array
    {
        return collect([CardStatus::Active, CardStatus::Suspended, CardStatus::Cancelled])
            ->mapWithKeys(fn (CardStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * Holders with plan, cards and active dependents, by plan then name.
     *
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        $status = CardStatus::tryFrom((string) $this->status);

        return Customer::query()
            ->with(['plan:id,name', 'currentCard', 'lastCard', 'activeDependents.kinship'])
            ->when($this->planId, fn (Builder $query, int $planId) => $query->where('plan_id', $planId))
            ->when($status, fn (Builder $query, CardStatus $status) => $query->withCardStatus($status))
            ->orderBy('plan_id')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function rowCount(): int
    {
        return $this->query()->count();
    }

    /**
     * Status of the holder: the current card, or the last card when none is in force.
     */
    public static function cardStatusOf(Customer $customer): ?CardStatus
    {
        $card = $customer->currentCard ?? $customer->lastCard;

        return $card ? CardStatus::fromId($card->card_status_id) : null;
    }

    /**
     * Holders and active dependents per plan, from the rows of the query.
     *
     * @param  Collection<int, Customer>  $customers
     * @return list<PlanTotals>
     */
    public function totalsByPlan(Collection $customers): array
    {
        return array_values($customers
            ->groupBy('plan_id')
            ->map(fn (Collection $holders): array => [
                'plan' => $holders->first()->plan->name,
                'holders' => $holders->count(),
                'dependents' => $holders->sum(fn (Customer $customer): int => $customer->activeDependents->count()),
            ])
            ->all());
    }

    public function pdfData(): array
    {
        $customers = $this->query()->get();

        return [
            'customers' => $customers,
            'totals' => $this->totalsByPlan($customers),
        ];
    }

    public function export(): CustomersExport
    {
        return new CustomersExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.customers';
    }
}
