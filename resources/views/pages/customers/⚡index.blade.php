<?php

use App\Enums\CardStatus;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer list (US-4.5): paginated holders with the card status, filters
 * by status, plan and free-text search kept in the URL. Requires
 * `customers.view`; the CPF is always masked (LGPD).
 */
new class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $plan = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'plan', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'plan', 'search');
        $this->resetPage();
    }

    /**
     * Status of the holder: the current card, or the last card when none is in force.
     */
    protected function cardStatus(Customer $customer): ?CardStatus
    {
        $card = $customer->currentCard ?? $customer->lastCard;

        return $card ? CardStatus::fromId($card->card_status_id) : null;
    }

    public function render(): View
    {
        $statusFilter = CardStatus::tryFrom($this->status);

        $customers = Customer::query()
            ->with(['plan:id,name', 'currentCard', 'lastCard'])
            ->withCount('activeDependents')
            ->search($this->search)
            ->when($this->plan !== '', fn (Builder $query) => $query->where('plan_id', (int) $this->plan))
            ->when($statusFilter, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('currentCard', fn (Builder $cards) => $cards->withStatus($statusFilter))
                ->orWhere(fn (Builder $query) => $query
                    ->doesntHave('currentCard')
                    ->whereHas('lastCard', fn (Builder $cards) => $cards->withStatus($statusFilter)))))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return $this->view([
            'customers' => $customers,
            'cardStatuses' => $customers->getCollection()->mapWithKeys(fn (Customer $customer): array => [$customer->id => $this->cardStatus($customer)])->all(),
            'statusFilters' => [
                '' => 'Todos',
                CardStatus::Active->value => 'Ativos',
                CardStatus::Suspended->value => 'Suspensos',
                CardStatus::Cancelled->value => 'Cancelados',
            ],
            'planOptions' => Plan::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
};
?>

<x-ui.card class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2.5">
        <x-ui.input
            wire:model.live.debounce.300ms="search"
            placeholder="Buscar por nome, CPF ou cartão"
            aria-label="Buscar cliente"
            class="min-w-[220px] flex-1"
        />
        <div class="flex flex-wrap gap-1.5">
            @foreach ($statusFilters as $value => $label)
                <x-ui.pill wire:key="status-{{ $value ?: 'all' }}" :active="$status === $value" wire:click="$set('status', '{{ $value }}')">{{ $label }}</x-ui.pill>
            @endforeach
        </div>
        <div class="w-48">
            <x-ui.select wire:model.live="plan" :options="$planOptions" placeholder="Todos os planos" aria-label="Plano" />
        </div>
        <x-ui.button icon="user-plus" :href="route('sales.create')" wire:navigate>Novo cliente</x-ui.button>
    </div>

    @if ($customers->isEmpty())
        <x-ui.empty-state title="Nenhum cliente encontrado" description="Ajuste os filtros ou busque pelo nome, CPF ou cartão.">
            @if ($status !== '' || $plan !== '' || $search !== '')
                <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.table :columns="['Cartão', 'Nome', 'CPF', 'Plano', 'Dependentes', 'Status']" min-width="min-w-[760px]">
            @foreach ($customers as $customer)
                @php($cardStatus = $cardStatuses[$customer->id])
                <tr
                    wire:key="customer-{{ $customer->id }}"
                    x-on:click="Livewire.navigate(@js(route('customers.show', $customer)))"
                    class="cursor-pointer hover:bg-ink-25"
                >
                    <td class="font-mono text-[13px]">{{ $customer->currentCard?->number ?? '—' }}</td>
                    <td>
                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $customer->name }}</a>
                    </td>
                    <td class="font-mono text-[13px] text-ink-500">{{ $customer->masked_cpf }}</td>
                    <td>{{ $customer->plan->name }}</td>
                    <td>{{ $customer->active_dependents_count === 0 ? '—' : $customer->active_dependents_count.' '.($customer->active_dependents_count === 1 ? 'dependente' : 'dependentes') }}</td>
                    <td>
                        @if ($cardStatus)
                            <x-ui.badge :tone="$cardStatus->color()" dot>{{ $cardStatus->label() }}</x-ui.badge>
                        @else
                            <x-ui.badge dot>Sem cartão</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="text-[13px] text-ink-500">{{ $customers->total() }} {{ $customers->total() === 1 ? 'cliente' : 'clientes' }}</div>

        {{ $customers->links() }}
    @endif
</x-ui.card>
