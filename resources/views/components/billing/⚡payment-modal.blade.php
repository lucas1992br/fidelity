<?php

use App\Enums\PaymentMethod;
use App\Support\Format;
use App\Support\Prototype\DemoData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $invoiceId = null;

    public string $paidAt = '';

    public string $amount = '';

    public string $method = 'pix';

    #[On('open-payment')]
    public function open(int $invoice): void
    {
        $found = DemoData::invoice($invoice);

        if (! $found || ! $found['status']->isPayable()) {
            return;
        }

        $this->resetValidation();
        $this->invoiceId = $invoice;
        $this->paidAt = DemoData::TODAY;
        $this->amount = number_format($found['amount'], 2, ',', '.');
        $this->method = PaymentMethod::Pix->value;
    }

    public function close(): void
    {
        $this->reset('invoiceId');
    }

    /**
     * @return array{invoice: array<string, mixed>, customer: array<string, mixed>}|null
     */
    #[Computed]
    public function context(): ?array
    {
        $invoice = $this->invoiceId ? DemoData::invoice($this->invoiceId) : null;

        return $invoice ? ['invoice' => $invoice, 'customer' => DemoData::customer($invoice['cid'])] : null;
    }

    public function confirm(): void
    {
        $context = $this->context;

        if (! $context) {
            return;
        }

        $this->validate([
            'paidAt' => ['required', 'date_format:d/m/Y'],
            'amount' => ['required', 'regex:/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$/'],
            'method' => ['required', Illuminate\Validation\Rule::enum(PaymentMethod::class)],
        ], [], ['paidAt' => 'data do pagamento', 'amount' => 'valor recebido', 'method' => 'forma de pagamento']);

        $paid = (float) str_replace(['.', ','], ['', '.'], $this->amount);

        if ($paid < $context['invoice']['amount']) {
            $this->addError('amount', 'O valor recebido não pode ser menor que '.Format::money($context['invoice']['amount']).'.');

            return;
        }

        $reactivates = DemoData::paymentReactivates($context['invoice']['id']);
        $receipt = DemoData::lastPayments()[0]['receipt'] + 1;

        $this->dispatch(
            'notify',
            title: "Pagamento registrado · recibo nº {$receipt}",
            description: $reactivates ? 'Cartão reativado por pagamento.' : "{$context['customer']['name']} · ref. {$context['invoice']['ref']}",
            receipt: $receipt,
        );

        $this->close();
    }
};
?>

<div>
    @if ($this->context)
        @php(['invoice' => $invoice, 'customer' => $customer] = $this->context)

        <x-ui.modal
            title="Registrar pagamento"
            :subtitle="$customer['name'].' · cartão '.$customer['card'].' · ref. '.$invoice['ref'].' · venc. '.$invoice['due']"
            close="close"
        >
            <div class="grid grid-cols-2 gap-3">
                <div class="flex flex-col gap-1">
                    <x-ui.input label="Data do pagamento" wire:model="paidAt" inputmode="numeric" />
                    @error('paidAt') <span class="text-xs font-semibold text-danger-600">{{ $message }}</span> @enderror
                </div>
                <div class="flex flex-col gap-1">
                    <x-ui.input label="Valor recebido" wire:model="amount" inputmode="decimal" />
                    @error('amount') <span class="text-xs font-semibold text-danger-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Forma de pagamento</span>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (\App\Enums\PaymentMethod::cases() as $option)
                        <x-ui.choice wire:key="method-{{ $option->value }}" :active="$method === $option->value" wire:click="$set('method', '{{ $option->value }}')">
                            {{ $option->label() }}
                        </x-ui.choice>
                    @endforeach
                </div>
            </div>

            @if (\App\Support\Prototype\DemoData::paymentReactivates($invoice['id']))
                <div class="rounded-md bg-success-50 px-3.5 py-3 text-[13px] font-semibold text-success-700">
                    O cartão está suspenso. Com este pagamento, ele será reativado.
                </div>
            @endif

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm">Confirmar baixa</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
