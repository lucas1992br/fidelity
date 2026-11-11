<?php

use App\Actions\Customers\ChangePlan;
use App\Models\Customer;
use App\Models\Plan;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Trocar plano / valor" modal of the customer sheet (US-7.2): only active
 * plans, with suggested price and dependents limit, the monthly amount
 * (prefilled with the current one, then with the price of a newly chosen
 * plan) and an optional reason; the dependents block shows before confirming.
 * Keeping the current plan with a new amount is allowed. Confirming runs
 * `ChangePlan`; the new amount applies from the next invoice. Requires
 * `cards.manage` to open and to confirm.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public string $planId = '';

    public string $monthlyAmount = '';

    public string $reason = '';

    #[On('open-change-plan')]
    public function open(): void
    {
        $this->authorize('cards.manage');

        $this->reset('reason');
        $this->resetValidation();
        $this->planId = (string) $this->customer->plan_id;
        $this->monthlyAmount = self::money($this->customer->monthly_amount);
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('planId', 'monthlyAmount', 'reason');
        $this->resetValidation();
    }

    protected static function money(string $amount): string
    {
        return number_format((float) $amount, 2, ',', '.');
    }

    /**
     * A new plan replaces the dependents limit check, so its stale error goes
     * away, and suggests its price as the monthly amount (the current amount
     * when going back to the current plan).
     */
    public function updatedPlanId(): void
    {
        $this->resetErrorBag('planId');

        if ((int) $this->planId === $this->customer->plan_id) {
            $this->monthlyAmount = self::money($this->customer->monthly_amount);
        } elseif ($plan = $this->plans->firstWhere('id', (int) $this->planId)) {
            $this->monthlyAmount = self::money($plan->price);
        }
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

        $from = $this->customer->plan->name.' ('.Format::money($this->customer->monthly_amount).')';

        try {
            $customer = $changePlan->handle(auth()->user(), $this->customer, [
                'plan_id' => $this->planId,
                'monthly_amount' => $this->monthlyAmount,
                'reason' => $this->reason,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'plan_id' => 'planId',
                    'monthly_amount' => 'monthlyAmount',
                    default => $key,
                } => $messages])
                ->all());
        }

        $to = $customer->plan->name.' ('.Format::money($customer->monthly_amount).')';
        $this->dispatch('notify', title: 'Plano alterado', description: "{$from} → {$to} · vale a partir da próxima mensalidade.");
        $this->dispatch('customer-card-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Trocar plano / valor" :subtitle="$this->customer->name.' · plano atual '.$this->customer->plan->name.' ('.\App\Support\Format::money($this->customer->monthly_amount).')'" close="close">
            <div class="flex flex-col gap-1.5" role="radiogroup" aria-label="Novo plano">
                <span class="text-[13px] font-semibold text-ink-800">Plano</span>
                @forelse ($this->plans as $plan)
                    @php($isCurrent = $plan->id === $this->customer->plan_id)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $planId === (string) $plan->id ? 'true' : 'false' }}"
                        wire:key="change-plan-{{ $plan->id }}"
                        wire:click="$set('planId', '{{ $plan->id }}')"
                        @class([
                            'flex cursor-pointer items-center gap-3 rounded-md border px-3.5 py-3 text-left',
                            'border-guardian-500 bg-guardian-50' => $planId === (string) $plan->id,
                            'border-ink-200 bg-white hover:bg-ink-50' => $planId !== (string) $plan->id,
                        ])
                    >
                        <span class="flex flex-1 flex-col">
                            <span class="text-sm font-semibold text-ink-800">{{ $plan->name }}{{ $isCurrent ? ' · atual' : '' }}</span>
                            <span class="text-xs text-ink-500">{{ $plan->max_dependents === 0 ? 'Sem dependentes' : "Até {$plan->max_dependents} dependentes" }}</span>
                        </span>
                        <span class="text-sm text-ink-500">sugerido {{ \App\Support\Format::money($plan->price) }}</span>
                    </button>
                @empty
                    <span class="text-xs text-ink-500">Nenhum plano ativo cadastrado.</span>
                @endforelse
                @error('planId')
                    <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                @enderror
            </div>

            @if ($this->customer->isEmployee())
                <span class="text-xs text-ink-500">Funcionário de empresa: não tem valor mensal próprio, a empresa paga o valor fixo dela.</span>
            @else
                <x-ui.field label="Valor mensal (R$)" for="change-plan-monthly-amount" error="monthlyAmount" hint="Valor cobrado do titular; o preço do plano é só uma sugestão." class="max-w-60">
                    <x-ui.input id="change-plan-monthly-amount" wire:model="monthlyAmount" error="monthlyAmount" mask="money" placeholder="0,00" />
                </x-ui.field>
            @endif

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
                >Salvar alteração</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
