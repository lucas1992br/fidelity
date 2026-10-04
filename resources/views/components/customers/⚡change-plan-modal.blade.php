<?php

use App\Actions\Customers\ChangePlan;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Trocar plano" modal of the customer sheet (US-7.2): only active plans,
 * with price and dependents limit, and an optional reason; the dependents
 * block shows before confirming. Confirming runs `ChangePlan`; the new price
 * applies from the next invoice. Requires `cards.manage` to open and to confirm.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public string $planId = '';

    public string $reason = '';

    #[On('open-change-plan')]
    public function open(): void
    {
        $this->authorize('cards.manage');

        $this->reset('planId', 'reason');
        $this->resetValidation();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('planId', 'reason');
        $this->resetValidation();
    }

    /**
     * A new plan replaces the dependents limit check, so its stale error goes away.
     */
    public function updatedPlanId(): void
    {
        $this->resetErrorBag('planId');
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('plan')->findOrFail($this->customerId);
    }

    /**
     * Plans offered in the change (active only).
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->active()->orderBy('price')->orderBy('name')->get();
    }

    /**
     * Blocking message when the chosen plan cannot cover the active dependents.
     */
    #[Computed]
    public function dependentsBlockMessage(): ?string
    {
        $plan = $this->plans->firstWhere('id', (int) $this->planId);

        return $plan ? ChangePlan::dependentsBlockMessage($plan, $this->customer->activeDependents()->count()) : null;
    }

    public function confirm(ChangePlan $changePlan): void
    {
        $this->authorize('cards.manage');

        $fromPlan = $this->customer->plan->name;

        try {
            $customer = $changePlan->handle(auth()->user(), $this->customer, [
                'plan_id' => $this->planId,
                'reason' => $this->reason,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'plan_id' ? 'planId' : $key => $messages])
                ->all());
        }

        $this->dispatch('notify', title: 'Plano alterado', description: "{$fromPlan} → {$customer->plan->name} · vale a partir da próxima mensalidade.");
        $this->dispatch('customer-card-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Trocar plano" :subtitle="$this->customer->name.' · plano atual '.$this->customer->plan->name.' ('.\App\Support\Format::money($this->customer->plan->price).')'" close="close">
            <div class="flex flex-col gap-1.5" role="radiogroup" aria-label="Novo plano">
                <span class="text-[13px] font-semibold text-ink-800">Novo plano</span>
                @forelse ($this->plans as $plan)
                    @php($isCurrent = $plan->id === $this->customer->plan_id)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $planId === (string) $plan->id ? 'true' : 'false' }}"
                        wire:key="change-plan-{{ $plan->id }}"
                        wire:click="$set('planId', '{{ $plan->id }}')"
                        @disabled($isCurrent)
                        @class([
                            'flex cursor-pointer items-center gap-3 rounded-md border px-3.5 py-3 text-left disabled:cursor-not-allowed disabled:opacity-50',
                            'border-guardian-500 bg-guardian-50' => $planId === (string) $plan->id,
                            'border-ink-200 bg-white hover:bg-ink-50' => $planId !== (string) $plan->id,
                        ])
                    >
                        <span class="flex flex-1 flex-col">
                            <span class="text-sm font-semibold text-ink-800">{{ $plan->name }}{{ $isCurrent ? ' · atual' : '' }}</span>
                            <span class="text-xs text-ink-500">{{ $plan->max_dependents === 0 ? 'Sem dependentes' : "Até {$plan->max_dependents} dependentes" }}</span>
                        </span>
                        <span class="text-sm font-semibold text-ink-800">{{ \App\Support\Format::money($plan->price) }}/mês</span>
                    </button>
                @empty
                    <span class="text-xs text-ink-500">Nenhum plano ativo cadastrado.</span>
                @endforelse
                @error('planId')
                    <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                @enderror
            </div>

            @if ($this->dependentsBlockMessage)
                <x-ui.callout tone="warning" wire:key="change-plan-dependents-limit">{{ $this->dependentsBlockMessage }}</x-ui.callout>
            @endif

            <x-ui.field label="Motivo" for="change-plan-reason" error="reason" hint="Opcional.">
                <x-ui.textarea id="change-plan-reason" wire:model="reason" error="reason" rows="2" placeholder="Ex.: incluir a família no plano" />
            </x-ui.field>

            <x-ui.callout>O novo valor vale a partir da próxima mensalidade.</x-ui.callout>

            <x-slot:actions>
                <x-ui.button
                    size="lg"
                    wire:click="confirm"
                    wire:loading.attr="disabled"
                    wire:target="confirm"
                    :disabled="$planId === '' || $this->dependentsBlockMessage !== null"
                >Trocar plano</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
