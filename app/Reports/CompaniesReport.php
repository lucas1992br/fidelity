<?php

namespace App\Reports;

use App\Exports\CompaniesExport;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Registered companies with their employees (holders) and the employees'
 * active dependents: card, plan, card status and monthly amount. The
 * monthly bill counts only employees with a card in force. A snapshot: the
 * period does not apply; the status filter picks active or inactive companies.
 *
 * @phpstan-type CompanyTotals array{company: string, employees: int, dependents: int, monthly: string}
 */
class CompaniesReport extends Report
{
    public const string StatusActive = 'active';

    public const string StatusInactive = 'inactive';

    public static function key(): string
    {
        return 'empresas';
    }

    public static function title(): string
    {
        return 'Empresas e funcionários';
    }

    public static function description(): string
    {
        return 'Empresas cadastradas com seus funcionários e dependentes.';
    }

    public static function filters(): array
    {
        return [self::FilterStatus];
    }

    public static function statusOptions(): array
    {
        return [self::StatusActive => 'Ativas', self::StatusInactive => 'Inativas'];
    }

    /**
     * Companies with employees (plan, cards, active dependents), by name.
     *
     * @return Builder<Company>
     */
    public function query(): Builder
    {
        return Company::query()
            ->with(['employees' => fn ($query) => $query->orderBy('name')->orderBy('id'), 'employees.plan:id,name', 'employees.currentCard', 'employees.lastCard', 'employees.activeDependents.kinship'])
            ->when($this->status !== null, fn (Builder $query) => $query->where('is_active', $this->status === self::StatusActive))
            ->orderBy('name')
            ->orderBy('id');
    }

    public function rowCount(): int
    {
        return $this->query()->get()->sum(fn (Company $company): int => 1 + $company->employees->sum(fn (Customer $employee): int => 1 + $employee->activeDependents->count()));
    }

    /**
     * Employees in force, their active dependents and the monthly bill of a company.
     *
     * @return CompanyTotals
     */
    public static function totalsOf(Company $company): array
    {
        $inForce = $company->employees->filter(fn (Customer $employee): bool => $employee->currentCard !== null);

        return [
            'company' => $company->display_name,
            'employees' => $inForce->count(),
            'dependents' => $inForce->sum(fn (Customer $employee): int => $employee->activeDependents->count()),
            'monthly' => $inForce->reduce(fn (string $sum, Customer $employee): string => bcadd($sum, $employee->monthly_amount, 2), '0.00'),
        ];
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return list<CompanyTotals>
     */
    public function totals(Collection $companies): array
    {
        return $companies->map(fn (Company $company): array => self::totalsOf($company))->values()->all();
    }

    public function pdfData(): array
    {
        $companies = $this->query()->get();

        return [
            'companies' => $companies,
            'totals' => $this->totals($companies),
        ];
    }

    public function export(): CompaniesExport
    {
        return new CompaniesExport($this);
    }

    public function pdfView(): string
    {
        return 'pdf.reports.companies';
    }
}
