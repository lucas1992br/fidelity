<?php

use App\Actions\Cards\CancelCard;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Card cancellation modal of the customer sheet (US-6.3): mandatory reason
 * and the destiny of the pending invoices (cancel them or keep them
 * chargeable), with their count and total; warns that the cancellation is
 * final. Requires `cards.manage` to open and to confirm.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public string $reason = '';

    public string $pendingInvoices = '';

    #[On('open-cancel-card')]
    public function open(): void
    {
        $this->authorize('cards.manage');

        $this->reset('reason', 'pendingInvoices');
        $this->resetValidation();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('reason', 'pendingInvoices');
        $this->resetValidation();
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('currentCard')->findOrFail($this->customerId);
    }

    /**
     * Count and total of the holder's open and overdue invoices.
     *
     * @return array{count: int, total: string}
     */
    #[Computed]
    public function pending(): array
    {
        $invoices = Invoice::query()->where('customer_id', $this->customerId)->payable();

        return [
            'count' => (clone $invoices)->count(),
            'total' => (string) ((clone $invoices)->sum('amount') ?: '0'),
        ];
    }

    public function confirm(CancelCard $cancelCard): void
    {
        $this->authorize('cards.manage');

        $card = $this->customer->currentCard;

        if ($card === null) {
            $this->close();

            return;
        }

        $option = $this->pending['count'] === 0 ? CancelCard::KeepPendingInvoices : $this->pendingInvoices;

        try {
            $cancelCard->handle($card, $this->reason, $option, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'pending_invoices' ? 'pendingInvoices' : $key => $messages])
                ->all());
        }

        $this->dispatch('notify', title: 'Cartão cancelado', description: "{$card->number} · {$this->customer->name}");
        $this->dispatch('customer-card-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Cancelar cartão" :subtitle="$this->customer->name.' · cartão '.($this->customer->currentCard?->number ?? '—')" close="close">
            <x-ui.callout tone="danger">O cancelamento é definitivo: o cartão não poderá ser reativado nem voltará ao estoque. O titular só volta ao programa pelo reingresso, com outro cartão.</x-ui.callout>

            <x-ui.field label="Motivo" for="cancel-card-reason" error="reason">
                <x-ui.textarea id="cancel-card-reason" wire:model="reason" error="reason" rows="3" placeholder="Ex.: desistência do titular" />
            </x-ui.field>

            @if ($this->pending['count'] > 0)
                @php($count = $this->pending['count'])
                <div class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-semibold text-ink-800">Faturas pendentes</span>
                    <div class="grid gap-2">
                        <x-ui.choice :active="$pendingInvoices === 'cancel'" wire:click="$set('pendingInvoices', 'cancel')" class="px-3.5 text-left">
                            Cancelar {{ $count === 1 ? 'a fatura pendente' : "as {$count} faturas pendentes" }} ({{ \App\Support\Format::money($this->pending['total']) }})
                        </x-ui.choice>
                        <x-ui.choice :active="$pendingInvoices === 'keep'" wire:click="$set('pendingInvoices', 'keep')" class="px-3.5 text-left">
                            Manter as faturas (continuam cobráveis)
                        </x-ui.choice>
                    </div>
                    @error('pendingInvoices')
                        <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                    @enderror
                </div>
            @else
                <span class="text-xs text-ink-500">O titular não tem faturas pendentes.</span>
            @endif

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">Cancelar cartão</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
