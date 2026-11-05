<?php

use App\Actions\Billing\PaymentPeriodAmount;
use App\Actions\Billing\PaymentWillReactivateCard;
use App\Actions\Billing\RegisterPayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Payment modal (US-9.1, US-9.2), opened by the `open-payment` event from the
 * quick search, the customer sheet and the billing screens. Confirming runs
 * `RegisterPayment`, shows the receipt toast and dispatches
 * `payment-registered` so the screens reload. The payment may cover up to 12
 * months from the invoice's month (the amount follows the period) and card or
 * boleto payments may be split into up to 12 installments, one per covered
 * month by default. A card payment may record the payer's card (first 6 +
 * last 4 digits), prefilled with the holder's last card, for the card
 * reconciliation. Requires `billing.pay`.
 */
new class extends Component
{
    public ?int $invoiceId = null;

    public string $paidAt = '';

    public string $amount = '';

    public string $method = '';

    public int $months = 1;

    public int $installments = 1;

    /**
     * Payer's card as first 6 + last 4 digits ("498407-9593"), card payments only.
     */
    public string $cardDigits = '';

    public string $notes = '';

    #[On('open-payment')]
    public function open(int $invoice): void
    {
        $this->authorize('billing.pay');

        $found = Invoice::query()->findOrFail($invoice);
        $this->authorize('pay', $found);

        if ($found->isCompanyBilled()) {
            $this->dispatch('notify', type: 'error', title: 'Esta mensalidade faz parte da fatura da empresa; registre o pagamento na página da empresa.');

            return;
        }

        if (! InvoiceStatus::fromId($found->invoice_status_id)->isPayable()) {
            $this->dispatch('notify', type: 'error', title: 'Esta mensalidade não pode receber baixa.');

            return;
        }

        $this->resetValidation();
        $this->reset('notes', 'months', 'installments');
        $this->invoiceId = $found->id;
        $lastCard = $found->customer->paymentCards()->latest('updated_at')->latest('id')->first();
        $this->cardDigits = $lastCard ? "{$lastCard->card_bin}-{$lastCard->card_last4}" : '';
        $this->paidAt = today()->format('d/m/Y');
        $this->amount = number_format((float) $found->amount, 2, ',', '.');
        $this->method = $this->methods->first()?->slug->value ?? '';
        unset($this->invoice, $this->willReactivateCard);
    }

    /**
     * The amount follows the period, and card or boleto default to one installment per month.
     */
    public function updatedMonths(): void
    {
        $this->months = max(1, min(Payment::MaxMonths, $this->months));

        if ($this->invoice !== null) {
            $this->amount = number_format((float) app(PaymentPeriodAmount::class)->handle($this->invoice, $this->months), 2, ',', '.');
        }

        $this->installments = $this->defaultInstallments();
    }

    /**
     * Only card and boleto are split; switching to another method pays at once.
     */
    public function updatedMethod(): void
    {
        $this->installments = $this->defaultInstallments();
    }

    protected function defaultInstallments(): int
    {
        return $this->allowsInstallments ? min($this->months, Payment::MaxInstallments) : 1;
    }

    public function close(): void
    {
        $this->reset('invoiceId', 'notes', 'months', 'installments', 'cardDigits');
        $this->resetValidation();
    }

    /**
     * The invoice being paid, with the holder and the card shown in the subtitle.
     */
    #[Computed]
    public function invoice(): ?Invoice
    {
        return $this->invoiceId
            ? Invoice::query()->with(['customer.currentCard', 'customer.lastCard'])->find($this->invoiceId)
            : null;
    }

    /**
     * Active payment methods offered as choices.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()->active()->orderBy('id')->get();
    }

    /**
     * Whether the chosen method may be split into installments.
     */
    #[Computed]
    public function allowsInstallments(): bool
    {
        return PaymentMethodEnum::tryFrom($this->method)?->allowsInstallments() ?? false;
    }

    /**
     * Period choices: "1 mês" up to "12 meses (1 ano)".
     *
     * @return array<int, string>
     */
    #[Computed]
    public function monthOptions(): array
    {
        return collect(range(1, Payment::MaxMonths))
            ->mapWithKeys(fn (int $count): array => [$count => match ($count) {
                1 => '1 mês',
                12 => '12 meses (1 ano)',
                default => "{$count} meses",
            }])
            ->all();
    }

    /**
     * Covered months, e.g. "10/2026 a 09/2027", shown under the period.
     */
    #[Computed]
    public function periodLabel(): string
    {
        return Payment::formatPeriod($this->invoice->reference_month, $this->months);
    }

    /**
     * Installment choices: "À vista" and "2x" up to the maximum.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function installmentOptions(): array
    {
        return collect(range(1, Payment::MaxInstallments))
            ->mapWithKeys(fn (int $count): array => [$count => $count === 1 ? 'À vista' : "{$count}x"])
            ->all();
    }

    /**
     * Whether confirming reactivates the holder's suspended card (warning of US-9.2).
     */
    #[Computed]
    public function willReactivateCard(): bool
    {
        return $this->invoice !== null && app(PaymentWillReactivateCard::class)->handle($this->invoice);
    }

    public function confirm(RegisterPayment $registerPayment): void
    {
        $this->authorize('billing.pay');

        $invoice = $this->invoice;

        if ($invoice === null) {
            $this->close();

            return;
        }

        $this->authorize('pay', $invoice);

        $this->validate([
            'paidAt' => ['required', 'date_format:d/m/Y'],
            'amount' => ['required', 'regex:/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$/'],
            'method' => ['required', Rule::in($this->methods->map(fn (PaymentMethod $method): string => $method->slug->value)->all())],
            'months' => ['required', 'integer', 'between:1,'.Payment::MaxMonths],
            'installments' => ['required', 'integer', 'between:1,'.Payment::MaxInstallments],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['paidAt' => 'data do pagamento', 'amount' => 'valor recebido', 'method' => 'forma de pagamento', 'months' => 'período', 'installments' => 'parcelas', 'notes' => 'observação']);

        $reactivatesCard = $this->willReactivateCard;

        try {
            $payment = $registerPayment->handle($invoice, [
                'paid_at' => CarbonImmutable::createFromFormat('!d/m/Y', $this->paidAt)->toDateString(),
                'amount' => str_replace(['.', ','], ['', '.'], $this->amount),
                'payment_method' => $this->method,
                'months' => $this->months,
                'installments' => $this->installments,
                'card_digits' => $this->method === PaymentMethodEnum::Card->value ? $this->cardDigits : null,
                'notes' => $this->notes,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $fields = ['paid_at' => 'paidAt', 'payment_method' => 'method', 'card_digits' => 'cardDigits'];

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])
                ->all());
        }

        $this->dispatch(
            'notify',
            title: "Pagamento registrado · recibo nº {$payment->receipt_number}",
            description: $reactivatesCard
                ? 'Cartão reativado por pagamento.'
                : "{$invoice->customer->name} · ref. {$payment->referencePeriod()}",
            receiptUrl: route('receipts.show', $payment),
        );
        $this->dispatch('payment-registered', invoice: $invoice->id);

        $this->close();
    }
};
?>

<div>
    @if ($this->invoice)
        @php($invoice = $this->invoice)
        @php($card = $invoice->customer->currentCard ?? $invoice->customer->lastCard)

        <x-ui.modal
            title="Registrar pagamento"
            :subtitle="$invoice->customer->name.' · cartão '.($card?->number ?? '—').' · ref. '.$invoice->reference_month->format('m/Y').' · venc. '.\App\Support\Format::date($invoice->due_date)"
            close="close"
        >
            @error('invoice')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

            <x-ui.field label="Período" for="payment-months" error="months">
                <x-ui.select id="payment-months" wire:model.live.number="months" error="months" :options="$this->monthOptions" />
                <span class="text-xs text-ink-500" data-slot="payment-period">Referência {{ $this->periodLabel }}</span>
            </x-ui.field>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field error="paidAt">
                    <x-ui.input label="Data do pagamento" wire:model="paidAt" mask="date" error="paidAt" />
                </x-ui.field>
                <x-ui.field error="amount">
                    <x-ui.input label="Valor recebido" wire:model="amount" mask="money" error="amount" />
                </x-ui.field>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Forma de pagamento</span>
                <div class="grid grid-cols-4 gap-2">
                    @foreach ($this->methods as $option)
                        <x-ui.choice wire:key="method-{{ $option->slug->value }}" :active="$method === $option->slug->value" wire:click="$set('method', '{{ $option->slug->value }}')">
                            {{ $option->slug->label() }}
                        </x-ui.choice>
                    @endforeach
                </div>
                @error('method') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror
            </div>

            @if ($this->allowsInstallments)
                <x-ui.field label="Parcelas" for="payment-installments" error="installments">
                    <x-ui.select id="payment-installments" wire:model.number="installments" error="installments" :options="$this->installmentOptions" />
                </x-ui.field>
            @endif

            @if ($method === 'card')
                <x-ui.field label="Cartão do pagador" for="payment-card-digits" error="cardDigits" hint="6 primeiros e 4 últimos dígitos, para conciliar com o extrato da Granito. Nunca o número completo.">
                    <x-ui.input id="payment-card-digits" wire:model="cardDigits" error="cardDigits" mask="card-digits" autocomplete="off" />
                </x-ui.field>
            @endif

            <x-ui.field label="Observação" for="payment-notes" error="notes">
                <x-ui.textarea id="payment-notes" wire:model="notes" error="notes" rows="2" placeholder="Opcional" />
            </x-ui.field>

            @if ($this->willReactivateCard)
                <div class="rounded-md bg-success-50 px-3.5 py-3 text-[13px] font-semibold text-success-700" data-slot="reactivation-warning">
                    O cartão está suspenso. Com este pagamento, ele será reativado.
                </div>
            @endif

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">Confirmar baixa</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
