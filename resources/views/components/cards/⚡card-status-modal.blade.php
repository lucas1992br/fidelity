<?php

use App\Actions\Cards\ReactivateCard;
use App\Actions\Cards\SuspendCard;
use App\Models\Card;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Manual suspension / reactivation modal of the customer sheet (US-6.2): asks
 * for the mandatory reason and runs `SuspendCard` or `ReactivateCard` on the
 * holder's current card. Requires `cards.manage` to open and to confirm.
 */
new class extends Component
{
    public const string Suspend = 'suspend';

    public const string Reactivate = 'reactivate';

    #[Locked]
    public int $customerId;

    #[Locked]
    public ?string $action = null;

    public string $reason = '';

    #[On('open-suspend-card')]
    public function openSuspend(): void
    {
        $this->open(self::Suspend);
    }

    #[On('open-reactivate-card')]
    public function openReactivate(): void
    {
        $this->open(self::Reactivate);
    }

    public function close(): void
    {
        $this->reset('action', 'reason');
        $this->resetValidation();
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('currentCard')->findOrFail($this->customerId);
    }

    public function confirm(SuspendCard $suspendCard, ReactivateCard $reactivateCard): void
    {
        $this->authorize('cards.manage');

        $card = $this->customer->currentCard;

        if ($card === null || $this->action === null) {
            $this->close();

            return;
        }

        if ($this->action === self::Suspend) {
            $suspendCard->handle($card, $this->reason, auth()->user());
            $this->dispatch('notify', title: 'Cartão suspenso', description: "{$card->number} · {$this->customer->name}");
        } else {
            $reactivateCard->handle($card, $this->reason, auth()->user());
            $this->dispatch('notify', title: 'Cartão reativado', description: "{$card->number} · {$this->customer->name}");
        }

        $this->dispatch('customer-card-updated');
        $this->close();
    }

    protected function open(string $action): void
    {
        $this->authorize('cards.manage');

        $this->reset('reason');
        $this->resetValidation();
        $this->action = $action;
    }
};
?>

<div>
    @if ($action)
        @php($isSuspension = $action === 'suspend')
        <x-ui.modal
            :title="$isSuspension ? 'Suspender cartão' : 'Reativar cartão'"
            :subtitle="$this->customer->name.' · cartão '.($this->customer->currentCard?->number ?? '—')"
            close="close"
        >
            <x-ui.field label="Motivo" for="card-status-reason" error="reason">
                <x-ui.textarea
                    id="card-status-reason"
                    wire:model="reason"
                    error="reason"
                    rows="3"
                    :placeholder="$isSuspension ? 'Ex.: solicitação do titular, viagem prolongada' : 'Ex.: acordo de pagamento'"
                />
            </x-ui.field>

            <span class="text-xs text-ink-500">Os dependentes seguem o status do cartão do titular.</span>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">{{ $isSuspension ? 'Suspender cartão' : 'Reativar cartão' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
