<?php

use App\Support\Prototype\DemoData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $customerId;

    public bool $isOpen = false;

    public string $reason = 'lost';

    public string $number = '';

    #[On('open-replace-card')]
    public function open(): void
    {
        $this->reset('reason', 'number');
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    /**
     * @return array{tone: string, label: string, available: bool}|null
     */
    #[Computed]
    public function check(): ?array
    {
        return DemoData::cardCheck($this->number);
    }

    public function confirm(): void
    {
        if (! ($this->check['available'] ?? false)) {
            return;
        }

        $customer = DemoData::customer($this->customerId);
        $number = trim($this->number);

        $this->dispatch('notify', title: 'Novo cartão vinculado', description: "{$number} substitui {$customer['card']}. Dependentes atualizados.");
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        @php($customer = \App\Support\Prototype\DemoData::customer($customerId))
        @php($inStock = collect(\App\Support\Prototype\DemoData::stock())->where('status', \App\Enums\CardStatus::InStock)->pluck('number')->implode(', '))

        <x-ui.modal title="Segunda via do cartão" :subtitle="$customer['name'].' · cartão atual '.$customer['card']" close="close">
            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Motivo</span>
                <div class="grid grid-cols-2 gap-2">
                    <x-ui.choice :active="$reason === 'lost'" wire:click="$set('reason', 'lost')">Perda ou roubo</x-ui.choice>
                    <x-ui.choice :active="$reason === 'discarded'" wire:click="$set('reason', 'discarded')">Cartão danificado</x-ui.choice>
                </div>
            </div>

            <label class="flex flex-col gap-1.5 text-[13px] font-semibold text-ink-800">
                Número do novo cartão
                <input
                    wire:model.live.debounce.300ms="number"
                    placeholder="Digite ou passe no leitor"
                    autofocus
                    class="h-[52px] rounded-md border border-ink-200 px-4 font-mono text-xl font-medium tracking-[0.08em] outline-none focus:border-guardian-500 focus:shadow-focus"
                >
            </label>

            @if ($this->check)
                <div @class([
                    'rounded-md px-3.5 py-3 text-[13px] font-semibold',
                    match ($this->check['tone']) {
                        'green' => 'bg-success-50 text-success-700',
                        'red' => 'bg-danger-50 text-danger-700',
                        'amber' => 'bg-warning-50 text-warning-700',
                        default => 'bg-ink-100 text-ink-600',
                    },
                ])>{{ $this->check['label'] }}</div>
            @endif

            <span class="text-xs text-ink-500">Em estoque agora: {{ $inStock }}. O novo cartão herda o status do atual.</span>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" :disabled="! ($this->check['available'] ?? false)">Vincular novo cartão</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
