<?php

use App\Actions\Cards\CheckCardAvailability;
use App\Actions\Cards\ReplaceCard;
use App\Models\Customer;
use App\Support\Cards\CardAvailability;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Segunda via" modal of the customer sheet (US-6.1): reason (loss/theft or
 * damage) and the new stock card checked in real time; confirming runs
 * `ReplaceCard`, so the new card keeps the old card's status. Requires
 * `cards.manage` to open and to confirm.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public string $reason = 'lost';

    public string $number = '';

    #[On('open-replace-card')]
    public function open(): void
    {
        $this->authorize('cards.manage');

        $this->reset('reason', 'number');
        $this->resetValidation();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('reason', 'number');
        $this->resetValidation();
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('currentCard')->findOrFail($this->customerId);
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

    public function confirm(ReplaceCard $replaceCard): void
    {
        $this->authorize('cards.manage');

        $oldNumber = $this->customer->currentCard?->number;

        try {
            $card = $replaceCard->handle($this->customer, $this->number, $this->reason, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'card_number' ? 'number' : $key => $messages])
                ->all());
        }

        $this->dispatch('notify', title: 'Novo cartão vinculado', description: "{$card->number} substitui {$oldNumber}. Dependentes atualizados.");
        $this->dispatch('customer-card-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Segunda via do cartão" :subtitle="$this->customer->name.' · cartão atual '.($this->customer->currentCard?->number ?? '—')" close="close">
            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Motivo</span>
                <div class="grid grid-cols-2 gap-2">
                    <x-ui.choice :active="$reason === 'lost'" wire:click="$set('reason', 'lost')">Perda ou roubo</x-ui.choice>
                    <x-ui.choice :active="$reason === 'discarded'" wire:click="$set('reason', 'discarded')">Cartão danificado</x-ui.choice>
                </div>
                @error('reason')
                    <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                @enderror
            </div>

            <x-ui.field label="Número do novo cartão" for="replace-card-number" error="number">
                <input
                    id="replace-card-number"
                    wire:model.live.debounce.300ms="number"
                    placeholder="Digite ou passe no leitor"
                    autofocus
                    autocomplete="off"
                    @error('number') aria-invalid="true" @enderror
                    class="h-[52px] rounded-md border border-ink-200 px-4 font-mono text-xl font-medium tracking-[0.08em] outline-none focus:border-guardian-500 focus:shadow-focus"
                >
            </x-ui.field>

            @if ($this->cardAvailability)
                <x-ui.callout :tone="$this->cardAvailability->tone()" wire:key="replace-card-availability">{{ $this->cardAvailability->message }}</x-ui.callout>
            @endif

            <span class="text-xs text-ink-500">O novo cartão herda o status do atual.</span>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" :disabled="! $this->cardAvailability?->isAvailable()">Vincular novo cartão</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
