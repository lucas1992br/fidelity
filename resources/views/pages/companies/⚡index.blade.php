<?php

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Company list: paginated companies with the employees in force, the lives
 * covered (employees plus their active dependents) and the monthly total,
 * with a free-text search kept in the URL and the "Nova empresa" modal.
 * Requires `customers.view`; creating requires `customers.update`.
 */
new class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $inForce = fn (Builder $query) => $query->whereHas('currentCard');

        return $this->view([
            'companies' => Company::query()
                ->withCount(['employees as employees_count' => $inForce])
                ->withSum(['employees as monthly_total' => $inForce], 'monthly_amount')
                ->withCount(['employees as dependents_count' => fn (Builder $query) => $query
                    ->whereHas('currentCard')
                    ->join('dependents', 'dependents.customer_id', '=', 'customers.id')
                    ->where('dependents.is_active', true)
                    ->whereNull('dependents.deleted_at')])
                ->search($this->search)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }
};
?>

<x-ui.card class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2.5">
        <x-ui.input
            wire:model.live.debounce.300ms="search"
            placeholder="Buscar por razão social, nome fantasia ou CNPJ"
            aria-label="Buscar empresa"
            class="min-w-[220px] flex-1"
        />
        @can('customers.update')
            <x-ui.button icon="building-2" x-on:click="$dispatch('open-company')">Nova empresa</x-ui.button>
        @endcan
    </div>

    @if ($companies->isEmpty())
        <x-ui.empty-state title="Nenhuma empresa encontrada" description="Cadastre uma empresa para vender cartões aos funcionários com fatura única." />
    @else
        @php($canViewAmounts = auth()->user()->can('billing.view'))
        <x-ui.table :columns="array_values(array_filter(['Empresa', 'CNPJ', 'Funcionários', 'Vidas', $canViewAmounts ? 'Total mensal' : null, 'Vencimento', 'Status']))" min-width="min-w-[820px]">
            @foreach ($companies as $company)
                <tr
                    wire:key="company-{{ $company->id }}"
                    x-on:click="Livewire.navigate(@js(route('companies.show', $company)))"
                    class="cursor-pointer hover:bg-ink-25"
                >
                    <td>
                        <a href="{{ route('companies.show', $company) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $company->display_name }}</a>
                        @if ($company->trade_name)
                            <div class="text-xs text-ink-500">{{ $company->name }}</div>
                        @endif
                    </td>
                    <td class="font-mono text-[13px] text-ink-500">{{ $company->formatted_cnpj }}</td>
                    <td>{{ $company->employees_count }}</td>
                    <td>{{ $company->employees_count + $company->dependents_count }}</td>
                    @if ($canViewAmounts)
                        <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($company->monthly_total ?? 0) }}</td>
                    @endif
                    <td>Dia {{ $company->due_day }}</td>
                    <td><x-ui.badge :tone="$company->is_active ? 'green' : 'gray'" dot>{{ $company->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="text-[13px] text-ink-500">{{ $companies->total() }} {{ $companies->total() === 1 ? 'empresa' : 'empresas' }}</div>

        {{ $companies->links() }}
    @endif

    <livewire:companies.company-modal />
</x-ui.card>
