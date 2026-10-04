<?php

use App\Actions\Cards\DiscardCard;
use App\Models\Card;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Inutilizar cartão" modal (US-2.4): shows the number, asks for the mandatory
 * reason and confirms through `DiscardCard`. Requires `cards.stock.manage`.
 */
new class extends Component
{
    public bool $isOpen = false;

    public ?int $cardId = null;

    public string $number = '';

    public string $reason = '';

    #[On('open-discard-card')]
    public function open(int $id): void
    {
        $this->authorize('cards.stock.manage');

        $card = Card::findOrFail($id);

        $this->reset('reason');
        $this->resetValidation();
        $this->cardId = $card->id;
        $this->number = $card->number;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('cardId', 'number', 'reason');
        $this->resetValidation();
    }

    public function confirm(DiscardCard $discardCard): void
    {
        $this->authorize('cards.stock.manage');

        $card = $discardCard->handle(auth()->user(), Card::findOrFail($this->cardId), $this->reason);

        $this->dispatch('notify', title: 'Cartão inutilizado', description: "{$card->number} saiu do estoque e não poderá ser vinculado.");
        $this->dispatch('card-stock-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Inutilizar cartão" subtitle="O cartão sai do estoque e nunca poderá ser vinculado a um cliente." close="close">
            <div class="flex flex-col gap-1">
                <span class="text-[13px] font-semibold text-ink-800">Número do cartão</span>
                <span class="font-mono text-xl font-medium tracking-[0.08em] text-ink-800">{{ $number }}</span>
            </div>

            <x-ui.field label="Motivo" for="discard-reason" error="reason">
                <x-ui.textarea id="discard-reason" wire:model="reason" error="reason" rows="3" placeholder="Ex.: impressão borrada, cartão quebrado" />
            </x-ui.field>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm">Inutilizar cartão</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
