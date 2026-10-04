<?php

use App\Actions\Plans\DeletePlan;
use App\Actions\Plans\SavePlan;
use App\Models\Plan;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Plan catalog (US-3.1): cards with price, dependents limit and active
 * customers, plus a create/edit modal. Every entry point requires `plans.manage`.
 */
new class extends Component
{
    public bool $isModalOpen = false;

    public ?int $planId = null;

    public string $name = '';

    public string $description = '';

    public string $price = '';

    public int|string $max_dependents = 0;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('plans.manage');
    }

    public function create(): void
    {
        $this->authorize('plans.manage');

        $this->resetForm();
        $this->isModalOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('plans.manage');

        $plan = Plan::findOrFail($id);

        $this->resetForm();
        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->price = str_replace('.', ',', $plan->price);
        $this->max_dependents = $plan->max_dependents;
        $this->is_active = $plan->is_active;
        $this->isModalOpen = true;
    }

    public function save(SavePlan $savePlan): void
    {
        $this->authorize('plans.manage');

        $plan = $savePlan->handle(
            $this->only('name', 'description', 'price', 'max_dependents', 'is_active'),
            $this->planId ? Plan::findOrFail($this->planId) : null,
        );

        $this->dispatch('notify', title: $this->planId ? 'Plano atualizado' : 'Plano criado', description: "{$plan->name} · ".Format::money($plan->price).'/mês');
        $this->close();
    }

    public function delete(DeletePlan $deletePlan): void
    {
        $this->authorize('plans.manage');

        $plan = Plan::findOrFail($this->planId);

        try {
            $deletePlan->handle($plan);
        } catch (ValidationException $exception) {
            $this->dispatch('notify', type: 'error', title: $exception->getMessage());

            return;
        }

        $this->dispatch('notify', title: 'Plano excluído', description: $plan->name);
        $this->close();
    }

    public function close(): void
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('planId', 'name', 'description', 'price', 'max_dependents', 'is_active');
        $this->resetValidation();
    }

    public function render(): View
    {
        return $this->view([
            'plans' => Plan::query()
                ->withCount(['customers as active_customers_count' => fn ($query) => $query->whereHas('currentCard')])
                ->orderByDesc('is_active')
                ->orderBy('price')
                ->get(),
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-4">
        <p class="m-0 flex-1 text-sm text-ink-500">Planos inativos não aparecem em novas adesões, mas continuam valendo para quem já os tem.</p>
        <x-ui.button icon="badge-check" wire:click="create">Novo plano</x-ui.button>
    </div>

    @if ($plans->isEmpty())
        <x-ui.empty-state title="Nenhum plano cadastrado" description="Cadastre o primeiro plano para começar as vendas." />
    @else
        <div class="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-4">
            @foreach ($plans as $plan)
                <x-ui.card size="hero" padding="p-6" class="flex flex-col gap-3.5" wire:key="plan-{{ $plan->id }}">
                    <div class="flex items-center gap-2">
                        <span class="flex-1 font-display text-[22px] font-bold text-ink-800">{{ $plan->name }}</span>
                        <x-ui.badge :tone="$plan->is_active ? 'green' : 'gray'">{{ $plan->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="font-display text-4xl font-bold tracking-tight text-ink-800">{{ \App\Support\Format::money($plan->price) }}</span>
                        <span class="text-sm text-ink-500">/mês</span>
                    </div>
                    <div class="flex flex-col gap-1.5 border-t border-ink-100 pt-3.5 text-sm">
                        <span>{{ $plan->max_dependents > 0 ? "Até {$plan->max_dependents} dependentes" : 'Não inclui dependentes' }}</span>
                        <span class="text-ink-500">{{ $plan->active_customers_count }} {{ $plan->active_customers_count === 1 ? 'titular ativo' : 'titulares ativos' }}</span>
                    </div>
                    <x-ui.button variant="secondary" wire:click="edit({{ $plan->id }})">Editar</x-ui.button>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    @if ($isModalOpen)
        <x-ui.modal :title="$planId ? 'Editar plano' : 'Novo plano'" subtitle="O novo preço vale a partir da próxima mensalidade gerada." close="close">
            <x-ui.field label="Nome" for="plan-name" error="name">
                <x-ui.input id="plan-name" wire:model="name" error="name" autocomplete="off" />
            </x-ui.field>

            <x-ui.field label="Descrição" for="plan-description" error="description">
                <x-ui.textarea id="plan-description" wire:model="description" error="description" rows="2" />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-4">
                <x-ui.field label="Preço mensal (R$)" for="plan-price" error="price">
                    <x-ui.input id="plan-price" wire:model="price" error="price" mask="money" placeholder="0,00" />
                </x-ui.field>

                <x-ui.field label="Limite de dependentes" for="plan-max-dependents" error="max_dependents" hint="0 = não inclui dependentes.">
                    <x-ui.input id="plan-max-dependents" type="number" min="0" wire:model="max_dependents" error="max_dependents" />
                </x-ui.field>
            </div>

            <label class="flex items-center gap-2.5 text-sm font-semibold text-ink-800">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-guardian-500">
                Ativo para novas adesões
            </label>

            <x-slot:actions>
                @if ($planId)
                    <x-ui.button
                        variant="secondary"
                        size="lg"
                        wire:click="delete"
                        wire:confirm="Excluir o plano {{ $name }}? Planos com titulares não podem ser excluídos."
                    >Excluir</x-ui.button>
                @endif
                <x-ui.button size="lg" wire:click="save">{{ $planId ? 'Salvar alterações' : 'Criar plano' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
