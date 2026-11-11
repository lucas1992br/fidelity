<?php

use App\Actions\Cards\CheckCardAvailability;
use App\Actions\Cards\ReenrollCustomer;
use App\Models\Customer;
use App\Models\Plan;
use App\Support\Cards\CardAvailability;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Re-enrollment modal of the customer sheet (US-6.4): new stock card checked
 * in real time (as in the sale), active plan, monthly amount (the holder's
 * current one by default, the plan price when the plan changes) and due day
 * (the holder's current one by default), blocked while the active dependents do not fit
 * the plan. Confirming runs `ReenrollCustomer`. Requires `cards.manage` to
 * open and to confirm.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public string $number = '';

    public string $planId = '';

    public string $monthlyAmount = '';

    public string $dueDay = '';

    #[On('open-reenroll')]
    public function open(): void
    {
        $this->authorize('cards.manage');

        $this->resetValidation();
        $this->number = '';
        $this->planId = $this->plans->contains('id', $this->customer->plan_id) ? (string) $this->customer->plan_id : '';
        $this->monthlyAmount = number_format((float) $this->customer->monthly_amount, 2, ',', '.');
        $this->dueDay = (string) $this->customer->due_day;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('number', 'planId', 'monthlyAmount', 'dueDay');
        $this->resetValidation();
    }

    /**
     * A new plan replaces the dependents limit check, so its stale error goes
     * away, and suggests its price as the monthly amount.
     */
    public function updatedPlanId(): void
    {
        $this->resetErrorBag('planId');

        if ($plan = $this->plans->firstWhere('id', (int) $this->planId)) {
            $this->monthlyAmount = number_format((float) $plan->price, 2, ',', '.');
        }
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->findOrFail($this->customerId);
    }

    /**
     * Plans offered in the re-enrollment (active only).
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->active()->orderBy('price')->orderBy('name')->get();
    }

    /**
     * State of the typed card number, or null while the field is empty.
     */
    #[Computed]
    public function cardAvailability(): ?CardAvailability
    {
        if (trim($this->number) === '') {
            return null;
        }

        return app(CheckCardAvailability::class)->handle($this->number, auth()->user());
    }

    /**
     * Blocking message when the chosen plan cannot cover the active dependents.
     */
    #[Computed]
    public function dependentsLimitMessage(): ?string
    {
        $plan = $this->plans->firstWhere('id', (int) $this->planId);

        return $plan ? ReenrollCustomer::dependentsLimitMessage($plan, $this->customer->activeDependents()->count()) : null;
    }

    public function confirm(ReenrollCustomer $reenrollCustomer): void
    {
        $this->authorize('cards.manage');

        try {
            $reenrollCustomer->handle($this->customer, [
                'card_number' => $this->number,
                'plan_id' => $this->planId,
                'monthly_amount' => $this->monthlyAmount,
                'due_day' => $this->dueDay,
            ], auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'card_number' => 'number',
                    'plan_id' => 'planId',
                    'monthly_amount' => 'monthlyAmount',
                    'due_day' => 'dueDay',
                    default => $key,
                } => $messages])
                ->all());
        }

        $this->dispatch('notify', title: 'Titular reingressou', description: 'Cartão '.trim($this->number)." · {$this->customer->name}");
        $this->dispatch('customer-card-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Reingresso" :subtitle="$this->customer->name.' volta ao programa com um novo cartão do estoque.'" close="close">
            <x-ui.field label="Número do novo cartão" for="reenroll-card-number" error="number">
                <input
                    id="reenroll-card-number"
                    wire:model.live.debounce.300ms="number"
                    placeholder="Digite ou passe no leitor"
                    autofocus
                    autocomplete="off"
                    @error('number') aria-invalid="true" @enderror
                    class="h-[52px] rounded-md border border-ink-200 px-4 font-mono text-xl font-medium tracking-[0.08em] outline-none focus:border-guardian-500 focus:shadow-focus"
                >
            </x-ui.field>

            @if ($this->cardAvailability)
                <x-ui.callout :tone="$this->cardAvailability->tone()" wire:key="reenroll-card-availability">{{ $this->cardAvailability->message }}</x-ui.callout>
            @endif

            <x-ui.field label="Plano" for="reenroll-plan" error="planId">
                <x-ui.select id="reenroll-plan" wire:model.live="planId" error="planId" placeholder="Selecione">
                    @foreach ($this->plans as $plan)
                        <option value="{{ $plan->id }}" wire:key="reenroll-plan-{{ $plan->id }}">{{ $plan->name }} · {{ \App\Support\Format::money($plan->price) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <div class="grid grid-cols-[minmax(0,1fr)_120px] gap-3">
                @if ($this->customer->isEmployee())
                    <x-ui.field label="Valor mensal" for="reenroll-company-amount" hint="Funcionário não paga mensalidade própria.">
                        <x-ui.input id="reenroll-company-amount" value="Valor fixo da empresa" disabled />
                    </x-ui.field>
                @else
                    <x-ui.field label="Valor mensal (R$)" for="reenroll-monthly-amount" error="monthlyAmount">
                        <x-ui.input id="reenroll-monthly-amount" wire:model="monthlyAmount" error="monthlyAmount" mask="money" placeholder="0,00" />
                    </x-ui.field>
                @endif
                <x-ui.field label="Vencimento" for="reenroll-due-day" error="dueDay">
                    <x-ui.input id="reenroll-due-day" type="number" min="1" max="28" wire:model="dueDay" error="dueDay" :disabled="$this->customer->isEmployee()" />
                </x-ui.field>
            </div>

            @if ($this->dependentsLimitMessage)
                <x-ui.callout tone="warning" wire:key="reenroll-dependents-limit">{{ $this->dependentsLimitMessage }}</x-ui.callout>
            @endif

            <span class="text-xs text-ink-500">O novo cartão entra ativo e a nova mensalidade vence no próximo dia de vencimento, com o valor mensal informado.</span>

            <x-slot:actions>
                <x-ui.button
                    size="lg"
                    wire:click="confirm"
                    wire:loading.attr="disabled"
                    wire:target="confirm"
                    :disabled="! $this->cardAvailability?->isAvailable() || $this->dependentsLimitMessage !== null"
                >Reingressar</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
