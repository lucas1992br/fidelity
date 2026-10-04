<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Actions\Billing\RefundPayment;
use App\Actions\Customers\AddCustomerNote;
use App\Actions\Customers\AddDependent;
use App\Actions\Customers\DeactivateDependent;
use App\Actions\Customers\ExportCustomerData;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CustomerTimeline;
use App\Support\Customers\TimelineEvent;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer sheet (US-4.7): header with the card status and the actions the
 * user may take, and the Resumo, Dependentes, Mensalidades, Histórico and
 * Observações tabs (active tab kept in the URL). Requires `customers.view`;
 * the full CPF is shown only here (LGPD). The Mensalidades tab registers
 * payments (`billing.pay`), opens receipts and refunds them (`billing.refund`).
 */
new class extends Component
{
    /**
     * @var array<string, string>
     */
    public const array TABS = [
        'resumo' => 'Resumo',
        'dependentes' => 'Dependentes',
        'mensalidades' => 'Mensalidades',
        'historico' => 'Histórico',
        'observacoes' => 'Observações',
    ];

    #[Locked]
    public Customer $customer;

    #[Url]
    public string $tab = 'resumo';

    public string $note = '';

    /**
     * Payment being refunded in the confirmation modal.
     */
    #[Locked]
    public ?int $refundingPaymentId = null;

    public string $refundReason = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);

        $this->customer = $customer;

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'resumo';
        }
    }

    /**
     * Reload the holder after a card, plan or dependent action of the sheet modals.
     */
    #[On('customer-card-updated')]
    #[On('customer-dependents-updated')]
    #[On('payment-registered')]
    public function refreshCard(): void
    {
        $this->customer->refresh();
        unset($this->card, $this->cardStatus, $this->payableInvoice, $this->canRegisterPayment, $this->previousCards, $this->dependents, $this->invoices, $this->timeline);
    }

    public function addNote(AddCustomerNote $addCustomerNote): void
    {
        $this->authorize('view', $this->customer);

        try {
            $addCustomerNote->handle($this->customer, $this->note, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['note' => $exception->validator->errors()->get('body')]);
        }

        $this->reset('note');
        unset($this->notes, $this->timeline);

        $this->dispatch('notify', title: 'Observação adicionada');
    }

    /**
     * Stop covering the dependent, keeping the sequence and the identification (US-7.1).
     */
    public function deactivateDependent(int $dependentId, DeactivateDependent $deactivateDependent): void
    {
        $this->authorize('update', $this->customer);

        $dependent = $deactivateDependent->handle(auth()->user(), $this->customer->dependents()->findOrFail($dependentId));

        unset($this->dependents, $this->timeline);

        $this->dispatch('notify', title: 'Dependente inativado', description: "{$dependent->name} · a vaga do plano foi liberada.");
    }

    /**
     * Open the refund confirmation of a payment of the holder (US-9.4).
     */
    public function openRefund(int $paymentId): void
    {
        $this->authorize('billing.refund');

        $payment = $this->findCustomerPayment($paymentId);
        $this->authorize('refund', $payment);

        $this->reset('refundReason');
        $this->resetValidation('refundReason');
        $this->refundingPaymentId = $payment->id;
    }

    public function closeRefund(): void
    {
        $this->reset('refundingPaymentId', 'refundReason');
        $this->resetValidation('refundReason');
    }

    /**
     * Refund the payment: the invoice returns to open or overdue and the income is cancelled.
     */
    public function refund(RefundPayment $refundPayment): void
    {
        $this->authorize('billing.refund');

        if ($this->refundingPaymentId === null) {
            return;
        }

        $payment = $this->findCustomerPayment($this->refundingPaymentId);

        try {
            $payment = $refundPayment->handle($payment, $this->refundReason, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['refundReason' => collect($exception->errors())->flatten()->all()]);
        }

        $this->closeRefund();
        $this->refreshCard();

        $this->dispatch('notify', title: "Pagamento estornado · recibo nº {$payment->receipt_number}", description: 'A mensalidade voltou a ficar pendente e a receita foi cancelada.');
    }

    /**
     * Payment being refunded, shown in the confirmation modal.
     */
    #[Computed]
    public function refundingPayment(): ?Payment
    {
        return $this->refundingPaymentId ? $this->findCustomerPayment($this->refundingPaymentId) : null;
    }

    /**
     * A payment of this holder's invoices, so another holder's payment cannot be targeted.
     */
    protected function findCustomerPayment(int $paymentId): Payment
    {
        return Payment::query()
            ->with('invoice')
            ->whereRelation('invoice', 'customer_id', $this->customer->id)
            ->findOrFail($paymentId);
    }

    /**
     * Download every stored data of the holder as JSON (LGPD), logged in the activity log.
     */
    public function export(ExportCustomerData $exportCustomerData): StreamedResponse
    {
        $this->authorize('export', $this->customer);

        $json = json_encode(
            $exportCustomerData->handle($this->customer, auth()->user()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return response()->streamDownload(function () use ($json): void {
            echo $json;
        }, $exportCustomerData->filename($this->customer), ['Content-Type' => 'application/json']);
    }

    /**
     * Notes, most recent first, with the author and the author's profile.
     *
     * @return Collection<int, CustomerNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        return $this->customer->notes()->with('user.roles')->get();
    }

    /**
     * The card in force, or the last card when none is (e.g. cancelled).
     */
    #[Computed]
    public function card(): ?Card
    {
        return $this->customer->currentCard ?? $this->customer->lastCard;
    }

    #[Computed]
    public function cardStatus(): ?CardStatus
    {
        return $this->card ? CardStatus::fromId($this->card->card_status_id) : null;
    }

    /**
     * Oldest invoice still payable, offered by "Registrar pagamento".
     */
    #[Computed]
    public function payableInvoice(): ?Invoice
    {
        return $this->customer->invoices()->payable()->orderBy('due_date')->orderBy('id')->first();
    }

    /**
     * Whether "Registrar pagamento" applies: a payable invoice and a card that is not cancelled.
     */
    #[Computed]
    public function canRegisterPayment(): bool
    {
        return $this->payableInvoice !== null && $this->cardStatus !== CardStatus::Cancelled;
    }

    /**
     * Cards no longer in force, each with its last status log (final status, reason and date).
     *
     * @return Collection<int, Card>
     */
    #[Computed]
    public function previousCards(): Collection
    {
        return $this->customer->previousCards()->with('latestStatusLog')->get();
    }

    /**
     * Holder data shown in the Resumo tab, with the full CPF.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function holderFields(): array
    {
        $customer = $this->customer;
        $address = collect([
            trim("{$customer->street}, {$customer->number}".($customer->complement ? " - {$customer->complement}" : '')),
            $customer->district,
            "{$customer->city}/{$customer->state}",
            'CEP '.Format::zip($customer->zip_code),
        ])->implode(' · ');

        return [
            'CPF' => $customer->formatted_cpf,
            'Nascimento' => Format::date($customer->birth_date),
            'Celular' => Format::phone($customer->phone),
            'Telefone' => $customer->phone_secondary ? Format::phone($customer->phone_secondary) : '—',
            'E-mail' => $customer->email ?: '—',
            'Endereço' => $address,
            'Adesão' => Format::date($customer->joined_at),
        ];
    }

    /**
     * Dependents usage of the plan, e.g. "2 de 4" or "Não inclui".
     */
    #[Computed]
    public function dependentsUsage(): string
    {
        $max = $this->customer->plan->max_dependents;

        return $max === 0 ? 'Não inclui' : $this->customer->activeDependents()->count()." de {$max}";
    }

    /**
     * Why "Adicionar dependente" is disabled (plan limit reached or plan
     * without dependents), or null when a new dependent fits the plan.
     */
    #[Computed]
    public function addDependentBlockedMessage(): ?string
    {
        return AddDependent::limitMessage($this->customer->plan, $this->customer->activeDependents()->count() + 1);
    }

    /**
     * Every dependent, inactive ones included, sharing the loaded holder so
     * the identification reuses its card.
     *
     * @return Collection<int, Dependent>
     */
    #[Computed]
    public function dependents(): Collection
    {
        $this->customer->loadMissing('currentCard');

        return $this->customer->dependents()->with('kinship')->get()
            ->each(fn (Dependent $dependent) => $dependent->setRelation('customer', $this->customer));
    }

    /**
     * Invoices from the most recent reference month to the oldest, with the
     * active payment used by the "Recibo" and "Estornar" actions.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return $this->customer->invoices()->with('activePayment')->orderByDesc('reference_month')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    #[Computed]
    public function timeline(): Collection
    {
        return app(CustomerTimeline::class)->for($this->customer);
    }

    /**
     * @return array<string, string>
     */
    protected function tabs(): array
    {
        return [
            ...self::TABS,
            'dependentes' => 'Dependentes · '.$this->customer->dependents()->count(),
        ];
    }

    public function render(): View
    {
        return $this->view([
            'tabs' => $this->tabs(),
        ])->title('Ficha do cliente')->layoutData(['crumb' => 'Clientes']);
    }
};
?>

<div class="flex flex-col gap-5">
    <x-ui.card size="hero" padding="p-6" class="flex flex-wrap items-center gap-6">
        <x-ui.avatar :initials="\App\Support\Format::initials($customer->name)" size="xl" />
        <div class="flex min-w-60 flex-1 flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="font-display text-[26px] leading-[1.2] font-bold tracking-tight text-ink-800">{{ $customer->name }}</span>
                @if ($this->cardStatus)
                    <x-ui.badge :tone="$this->cardStatus->color()" size="lg" dot>{{ $this->cardStatus->label() }}</x-ui.badge>
                @else
                    <x-ui.badge size="lg" dot>Sem cartão</x-ui.badge>
                @endif
            </div>
            <span class="text-sm text-ink-500">Titular desde {{ \App\Support\Format::date($customer->joined_at) }} · Cartão {{ $this->card?->number ?? '—' }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('cards.manage')
                @if ($customer->currentCard)
                    <x-ui.button variant="secondary" size="lg" x-on:click="$dispatch('open-replace-card')">Segunda via</x-ui.button>
                    <x-ui.button variant="secondary" size="lg" x-on:click="$dispatch('open-change-plan')">Trocar plano</x-ui.button>
                @endif
            @endcan
            @can('customers.update')
                <x-ui.button variant="secondary" size="lg" :href="route('customers.edit', $customer)" wire:navigate>Editar</x-ui.button>
            @endcan
            @can('export', $customer)
                <x-ui.button variant="secondary" size="lg" wire:click="export">Exportar dados</x-ui.button>
            @endcan
            @can('billing.pay')
                @if ($this->canRegisterPayment)
                    <x-ui.button size="lg" x-on:click="$dispatch('open-payment', { invoice: {{ $this->payableInvoice->id }} })">Registrar pagamento</x-ui.button>
                @endif
            @endcan
        </div>
    </x-ui.card>

    <x-ui.tabs :tabs="$tabs" :active="$tab" model="tab" />

    @switch($tab)
        @case('resumo')
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,340px),1fr))] gap-4" wire:key="tab-resumo">
                <div class="flex flex-col gap-[18px] rounded-2xl bg-guardian-50 p-6">
                    @if ($customer->currentCard)
                        @php($currentCard = $customer->currentCard)
                        <div class="flex items-center gap-2.5">
                            <span class="flex-1 text-xs font-semibold tracking-[0.08em] text-guardian-700 uppercase">Cartão vigente</span>
                            <span class="text-xs text-guardian-700">desde {{ \App\Support\Format::date($currentCard->assigned_at) }}</span>
                        </div>
                        <div class="font-mono text-4xl font-semibold tracking-[0.08em] text-guardian-900">{{ $currentCard->number }}</div>
                        <div class="flex flex-wrap gap-6 text-sm">
                            <div class="flex flex-col"><span class="text-xs text-guardian-700">Plano</span><span class="font-semibold text-guardian-900">{{ $customer->plan->name }} · {{ \App\Support\Format::money($customer->plan->price) }}</span></div>
                            <div class="flex flex-col"><span class="text-xs text-guardian-700">Vencimento</span><span class="font-semibold text-guardian-900">Todo dia {{ $customer->due_day }}</span></div>
                            <div class="flex flex-col"><span class="text-xs text-guardian-700">Dependentes</span><span class="font-semibold text-guardian-900">{{ $this->dependentsUsage }}</span></div>
                        </div>
                    @else
                        <span class="text-xs font-semibold tracking-[0.08em] text-guardian-700 uppercase">Cartão vigente</span>
                        <p class="m-0 text-sm text-guardian-900">O titular não tem cartão vigente. Plano {{ $customer->plan->name }} · {{ \App\Support\Format::money($customer->plan->price) }} · vencimento todo dia {{ $customer->due_day }}.</p>
                    @endif

                    @if ($this->previousCards->isNotEmpty())
                        <div class="flex flex-col gap-1.5 border-t border-guardian-100 pt-3.5">
                            <span class="text-xs text-guardian-700">Cartões anteriores</span>
                            @foreach ($this->previousCards as $previousCard)
                                @php($finalStatus = \App\Enums\CardStatus::fromId($previousCard->card_status_id))
                                <div wire:key="previous-card-{{ $previousCard->id }}" class="flex flex-wrap items-center gap-2.5 text-[13px]">
                                    <span class="font-mono text-guardian-900 line-through">{{ $previousCard->number }}</span>
                                    <span class="text-guardian-700">{{ collect([
                                        $finalStatus->label().' em '.\App\Support\Format::date($previousCard->latestStatusLog?->created_at ?? $previousCard->status_changed_at),
                                        $previousCard->latestStatusLog?->reason,
                                    ])->filter()->implode(' · ') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @can('cards.manage')
                        <div class="flex flex-wrap gap-2" data-slot="card-status-actions">
                            @if ($this->cardStatus === \App\Enums\CardStatus::Active)
                                <button type="button" x-on:click="$dispatch('open-suspend-card')" class="h-10 cursor-pointer rounded-full bg-white px-4 text-[13px] font-semibold text-warning-700">Suspender</button>
                            @endif
                            @if ($this->cardStatus === \App\Enums\CardStatus::Suspended)
                                <button type="button" x-on:click="$dispatch('open-reactivate-card')" class="h-10 cursor-pointer rounded-full bg-white px-4 text-[13px] font-semibold text-success-700">Reativar</button>
                            @endif
                            @if ($customer->currentCard)
                                <button type="button" x-on:click="$dispatch('open-cancel-card')" class="h-10 cursor-pointer rounded-full bg-white px-4 text-[13px] font-semibold text-danger-600">Cancelar cartão</button>
                            @elseif ($this->cardStatus === \App\Enums\CardStatus::Cancelled)
                                <button type="button" x-on:click="$dispatch('open-reenroll')" class="h-10 cursor-pointer rounded-full bg-white px-4 text-[13px] font-semibold text-guardian-700">Reingressar</button>
                            @endif
                        </div>
                    @endcan
                </div>

                <x-ui.card class="flex flex-col gap-0.5">
                    <x-ui.card-title class="mb-2.5">Dados do titular</x-ui.card-title>
                    @foreach ($this->holderFields as $label => $value)
                        <div wire:key="field-{{ $label }}" class="grid grid-cols-[130px_minmax(0,1fr)] gap-3 border-t border-ink-100 py-2.5 text-sm">
                            <span class="text-ink-500">{{ $label }}</span>
                            <span class="font-medium text-ink-800">{{ $value }}</span>
                        </div>
                    @endforeach
                </x-ui.card>
            </div>
            @break
        @case('dependentes')
            @php($canUpdateCustomer = auth()->user()->can('update', $customer))
            <x-ui.card class="flex flex-col gap-3" wire:key="tab-dependentes">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="flex-1 text-sm text-ink-500">
                        {{ $customer->plan->max_dependents === 0 ? "O plano {$customer->plan->name} não inclui dependentes." : "{$this->dependentsUsage} dependentes do plano {$customer->plan->name}" }}
                    </span>
                    @if ($canUpdateCustomer)
                        <x-ui.button icon="user-plus" x-on:click="$dispatch('open-dependent')" :disabled="$this->addDependentBlockedMessage !== null">Adicionar dependente</x-ui.button>
                    @endif
                </div>
                @if ($canUpdateCustomer && $this->addDependentBlockedMessage)
                    <x-ui.callout tone="warning" data-slot="dependents-limit">{{ $this->addDependentBlockedMessage }}</x-ui.callout>
                @endif
                @if ($this->dependents->isEmpty())
                    <x-ui.empty-state icon="users" title="Nenhum dependente" description="Os dependentes usam o mesmo cartão do titular." />
                @else
                    <x-ui.table :columns="$canUpdateCustomer ? ['Identificação', 'Nome', 'Parentesco', 'Nascimento', 'Status', '>Ações'] : ['Identificação', 'Nome', 'Parentesco', 'Nascimento', 'Status']" min-width="min-w-[620px]">
                        @foreach ($this->dependents as $dependent)
                            <tr wire:key="dependent-{{ $dependent->id }}" @class(['text-ink-400' => ! $dependent->is_active])>
                                <td class="font-mono text-[13px]">{{ $dependent->identification ?? '—' }}</td>
                                <td class="font-semibold text-ink-800">{{ $dependent->name }}</td>
                                <td>{{ $dependent->kinship->name }}</td>
                                <td>{{ \App\Support\Format::date($dependent->birth_date) }}</td>
                                <td>
                                    @if ($dependent->is_active)
                                        <x-ui.badge tone="green" dot>Ativo</x-ui.badge>
                                    @else
                                        <x-ui.badge dot>Inativo</x-ui.badge>
                                    @endif
                                </td>
                                @if ($canUpdateCustomer)
                                    <td class="text-right whitespace-nowrap">
                                        <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-dependent', { dependent: {{ $dependent->id }} })">Editar</x-ui.button>
                                        @if ($dependent->is_active)
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                class="text-danger-600"
                                                wire:click="deactivateDependent({{ $dependent->id }})"
                                                wire:confirm="Inativar {{ $dependent->name }}? A vaga no plano será liberada; a identificação e o histórico são mantidos."
                                            >Inativar</x-ui.button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
            @break
        @case('mensalidades')
            <x-ui.card wire:key="tab-mensalidades">
                @if ($this->invoices->isEmpty())
                    <x-ui.empty-state icon="receipt" title="Nenhuma mensalidade" description="As mensalidades geradas para o titular aparecem aqui." />
                @else
                    @php($canPay = auth()->user()->can('billing.pay'))
                    @php($canViewReceipt = auth()->user()->can('billing.view'))
                    @php($canRefund = auth()->user()->can('billing.refund'))
                    <x-ui.table :columns="['Referência', 'Vencimento', 'Valor', 'Status', '>Ações']" min-width="min-w-[620px]">
                        @foreach ($this->invoices as $invoice)
                            @php($invoiceStatus = \App\Enums\InvoiceStatus::fromId($invoice->invoice_status_id))
                            @php($daysOverdue = $invoice->days_overdue)
                            @php($activePayment = $invoice->activePayment)
                            <tr wire:key="invoice-{{ $invoice->id }}">
                                <td class="font-semibold text-ink-800">{{ $invoice->reference_month->format('m/Y') }}</td>
                                <td>{{ \App\Support\Format::date($invoice->due_date) }}</td>
                                <td>{{ \App\Support\Format::money($invoice->amount) }}</td>
                                <td>
                                    @if ($daysOverdue > 0)
                                        <x-ui.badge tone="red">Em atraso · {{ $daysOverdue }} {{ $daysOverdue === 1 ? 'dia' : 'dias' }}</x-ui.badge>
                                    @else
                                        <x-ui.badge :tone="$invoiceStatus->color()">{{ $invoiceStatus->label() }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($canPay && $invoiceStatus->isPayable())
                                        <x-ui.button variant="soft" size="sm" x-on:click="$dispatch('open-payment', { invoice: {{ $invoice->id }} })">Registrar pagamento</x-ui.button>
                                    @endif
                                    @if ($invoiceStatus === \App\Enums\InvoiceStatus::Paid && $activePayment)
                                        @if ($canViewReceipt)
                                            <x-ui.button variant="link" size="sm" :href="route('receipts.show', $activePayment)" target="_blank" rel="noopener">Recibo</x-ui.button>
                                        @endif
                                        @if ($canRefund)
                                            <x-ui.button variant="ghost" size="sm" class="text-danger-600" wire:click="openRefund({{ $activePayment->id }})">Estornar</x-ui.button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
            @break
        @case('historico')
            <x-ui.card padding="p-6" class="flex flex-col" wire:key="tab-historico">
                @forelse ($this->timeline as $index => $event)
                    <div wire:key="event-{{ $index }}" class="grid grid-cols-[96px_20px_minmax(0,1fr)] gap-3">
                        <span class="pt-0.5 text-right text-xs text-ink-500">{{ \App\Support\Format::date($event->date) }}</span>
                        <div class="flex flex-col items-center">
                            <span class="mt-1 size-3 flex-none rounded-full {{ $event->dotClass() }}"></span>
                            <span class="min-h-5 w-0.5 flex-1 bg-ink-100"></span>
                        </div>
                        <div class="flex flex-col gap-0.5 pb-5">
                            <span class="text-sm font-semibold text-ink-800">{{ $event->title }}</span>
                            @if ($event->description)
                                <span class="text-[13px] break-words text-ink-500">{{ $event->description }}</span>
                            @endif
                            <span class="text-xs text-ink-400">por {{ $event->user }}</span>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="clock" title="Sem eventos" description="O histórico do titular aparece aqui." />
                @endforelse
            </x-ui.card>
            @break
        @case('observacoes')
            <x-ui.card class="flex flex-col gap-3.5" wire:key="tab-observacoes">
                <form wire:submit="addNote" class="flex flex-col gap-1.5">
                    <div class="flex flex-wrap gap-2.5">
                        <x-ui.input
                            wire:model="note"
                            error="note"
                            placeholder="Escreva uma observação sobre o cliente"
                            aria-label="Observação"
                            class="min-w-[220px] flex-1"
                        />
                        <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="addNote">Adicionar</x-ui.button>
                    </div>
                    @error('note')
                        <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
                    @enderror
                </form>
                @forelse ($this->notes as $customerNote)
                    <div wire:key="note-{{ $customerNote->id }}" class="flex flex-col gap-1 rounded-md bg-ink-50 px-4 py-3.5">
                        <span class="text-sm break-words whitespace-pre-line text-ink-800">{{ $customerNote->body }}</span>
                        <span class="text-xs text-ink-500">{{ $customerNote->user->role_label }} · {{ $customerNote->user->name }} · {{ \App\Support\Format::date($customerNote->created_at) }}</span>
                    </div>
                @empty
                    <x-ui.empty-state icon="message-circle" title="Nenhuma observação" description="Registre combinados e preferências do cliente para a equipe." />
                @endforelse
            </x-ui.card>
            @break
    @endswitch

    @if ($refundingPaymentId && $this->refundingPayment)
        @php($refundingPayment = $this->refundingPayment)
        <x-ui.modal
            title="Estornar pagamento"
            :subtitle="'Recibo nº '.$refundingPayment->receipt_number.' · ref. '.$refundingPayment->invoice->reference_month->format('m/Y').' · '.\App\Support\Format::money($refundingPayment->amount)"
            close="closeRefund"
        >
            <x-ui.callout tone="warning">A mensalidade volta a ficar pendente e a receita vinculada é cancelada. O pagamento continua registrado com o estorno.</x-ui.callout>

            <x-ui.field label="Motivo (opcional)" for="refund-reason" error="refundReason">
                <x-ui.textarea id="refund-reason" wire:model="refundReason" error="refundReason" rows="3" placeholder="Ex.: pagamento lançado em duplicidade" />
            </x-ui.field>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="refund" wire:loading.attr="disabled" wire:target="refund">Estornar pagamento</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif

    @can('update', $customer)
        <livewire:customers.dependent-modal :customer-id="$customer->id" />
    @endcan

    @can('cards.manage')
        <livewire:cards.replace-card-modal :customer-id="$customer->id" />
        <livewire:cards.card-status-modal :customer-id="$customer->id" />
        <livewire:cards.cancel-card-modal :customer-id="$customer->id" />
        <livewire:cards.reenroll-modal :customer-id="$customer->id" />
        <livewire:customers.change-plan-modal :customer-id="$customer->id" />
    @endcan
</div>
