<?php

use App\Actions\Billing\RefundPayment;
use App\Actions\Billing\RegisterCompanyPayment;
use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Company sheet: header with the company data, the Funcionários tab (each
 * employee with card, dependents and monthly amount) and the Faturas tab with
 * one consolidated bill per reference month (sum of the employees' invoices).
 * A bill is paid at once with a single receipt (`RegisterCompanyPayment`,
 * `billing.pay`) and refunded with `RefundPayment` (`billing.refund`).
 * Requires `customers.view`; amounts and bills require `billing.view`.
 */
new class extends Component
{
    /**
     * @var array<string, string>
     */
    public const array TABS = [
        'funcionarios' => 'Funcionários',
        'faturas' => 'Faturas',
    ];

    #[Locked]
    public Company $company;

    #[Url]
    public string $tab = 'funcionarios';

    /**
     * Reference month ("Y-m-d") of the bill being paid in the payment modal.
     */
    #[Locked]
    public ?string $payingMonth = null;

    public string $paidAt = '';

    public string $amount = '';

    public string $method = '';

    public int $installments = 1;

    public string $notes = '';

    /**
     * Company payment being refunded in the confirmation modal.
     */
    #[Locked]
    public ?int $refundingPaymentId = null;

    public string $refundReason = '';

    public function mount(Company $company): void
    {
        $this->authorize('customers.view');

        $this->company = $company;

        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'funcionarios';
        }
    }

    #[On('company-saved')]
    public function refreshCompany(): void
    {
        $this->company->refresh();
        unset($this->employees);
    }

    /**
     * Employees with card, plan and active dependents, those in force first.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function employees(): Collection
    {
        return $this->company->employees()
            ->with(['plan:id,name', 'currentCard', 'lastCard'])
            ->withCount('activeDependents')
            ->get()
            ->sortBy([
                fn (Customer $a, Customer $b): int => ($b->currentCard !== null) <=> ($a->currentCard !== null),
                fn (Customer $a, Customer $b): int => strcoll($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * Consolidated bills, most recent month first: employees, total, amount
     * still payable, due date, status and the non-refunded company payments.
     *
     * @return Collection<int, array{month: CarbonImmutable, due_date: CarbonImmutable, employees: int, total: string, payable: string, status: InvoiceStatus, days_overdue: int, payments: Collection<int, Payment>}>
     */
    #[Computed]
    public function bills(): Collection
    {
        $payments = $this->company->payments()
            ->whereNull('refunded_at')
            ->with('invoice:id,reference_month')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Payment $payment): string => $payment->invoice->reference_month->toDateString());

        return $this->company->invoices()
            ->orderByDesc('reference_month')
            ->get()
            ->groupBy(fn (Invoice $invoice): string => $invoice->reference_month->toDateString())
            ->map(function (Collection $invoices, string $month) use ($payments): array {
                $billed = $invoices->reject(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Cancelled));
                $payable = $invoices->filter(fn (Invoice $invoice): bool => InvoiceStatus::fromId($invoice->invoice_status_id)->isPayable());
                $daysOverdue = (int) $payable->max(fn (Invoice $invoice): int => $invoice->days_overdue);

                return [
                    'month' => CarbonImmutable::parse($month),
                    'due_date' => CarbonImmutable::instance(($payable->isNotEmpty() ? $payable : $invoices)->min('due_date')),
                    'employees' => $billed->count(),
                    'total' => self::sum($billed),
                    'payable' => self::sum($payable),
                    'status' => match (true) {
                        $payable->isEmpty() && $billed->isEmpty() => InvoiceStatus::Cancelled,
                        $payable->isEmpty() => InvoiceStatus::Paid,
                        $daysOverdue > 0 => InvoiceStatus::Overdue,
                        default => InvoiceStatus::Open,
                    },
                    'days_overdue' => $daysOverdue,
                    'payments' => $payments->get($month, collect()),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    protected static function sum(Collection $invoices): string
    {
        return $invoices->reduce(fn (string $sum, Invoice $invoice): string => bcadd($sum, $invoice->amount, 2), '0.00');
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

    #[Computed]
    public function allowsInstallments(): bool
    {
        return PaymentMethodEnum::tryFrom($this->method)?->allowsInstallments() ?? false;
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
     * Bill being paid in the payment modal.
     *
     * @return array{month: CarbonImmutable, due_date: CarbonImmutable, employees: int, total: string, payable: string, status: InvoiceStatus, days_overdue: int, payments: Collection<int, Payment>}|null
     */
    #[Computed]
    public function payingBill(): ?array
    {
        return $this->payingMonth
            ? $this->bills->first(fn (array $bill): bool => $bill['month']->toDateString() === $this->payingMonth)
            : null;
    }

    public function openPayment(string $month): void
    {
        $this->authorize('billing.pay');

        $bill = $this->bills->first(fn (array $bill): bool => $bill['month']->toDateString() === $month);

        if ($bill === null || ! $bill['status']->isPayable()) {
            $this->dispatch('notify', type: 'error', title: 'Esta fatura não pode receber baixa.');

            return;
        }

        $this->resetValidation();
        $this->reset('notes', 'installments');
        $this->payingMonth = $bill['month']->toDateString();
        $this->paidAt = today()->format('d/m/Y');
        $this->amount = number_format((float) $bill['payable'], 2, ',', '.');
        $this->method = $this->methods->first()?->slug->value ?? '';
        unset($this->payingBill);
    }

    /**
     * Only card and boleto are split; switching to another method pays at once.
     */
    public function updatedMethod(): void
    {
        $this->installments = 1;
    }

    public function closePayment(): void
    {
        $this->reset('payingMonth', 'notes', 'installments');
        $this->resetValidation();
    }

    public function confirmPayment(RegisterCompanyPayment $registerCompanyPayment): void
    {
        $this->authorize('billing.pay');

        if ($this->payingMonth === null) {
            return;
        }

        $this->validate([
            'paidAt' => ['required', 'date_format:d/m/Y'],
            'amount' => ['required', 'regex:/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$/'],
            'method' => ['required', Rule::in($this->methods->map(fn (PaymentMethod $method): string => $method->slug->value)->all())],
            'installments' => ['required', 'integer', 'between:1,'.Payment::MaxInstallments],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['paidAt' => 'data do pagamento', 'amount' => 'valor recebido', 'method' => 'forma de pagamento', 'installments' => 'parcelas', 'notes' => 'observação']);

        try {
            $payment = $registerCompanyPayment->handle($this->company, $this->payingMonth, [
                'paid_at' => CarbonImmutable::createFromFormat('!d/m/Y', $this->paidAt)->toDateString(),
                'amount' => str_replace(['.', ','], ['', '.'], $this->amount),
                'payment_method' => $this->method,
                'installments' => $this->installments,
                'notes' => $this->notes,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $fields = ['paid_at' => 'paidAt', 'payment_method' => 'method'];

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])
                ->all());
        }

        $this->dispatch(
            'notify',
            title: "Pagamento registrado · recibo nº {$payment->receipt_number}",
            description: "{$this->company->display_name} · ref. {$payment->referencePeriod()}",
            receiptUrl: route('receipts.show', $payment),
        );

        $this->closePayment();
        unset($this->bills, $this->employees);
    }

    /**
     * Open the refund confirmation of a company payment.
     */
    public function openRefund(int $paymentId): void
    {
        $this->authorize('billing.refund');

        $payment = $this->findCompanyPayment($paymentId);
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
     * Refund the payment: every covered invoice returns to open or overdue and the income is cancelled.
     */
    public function refund(RefundPayment $refundPayment): void
    {
        $this->authorize('billing.refund');

        if ($this->refundingPaymentId === null) {
            return;
        }

        try {
            $payment = $refundPayment->handle($this->findCompanyPayment($this->refundingPaymentId), $this->refundReason, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['refundReason' => collect($exception->errors())->flatten()->all()]);
        }

        $this->closeRefund();
        unset($this->bills);

        $this->dispatch('notify', title: "Pagamento estornado · recibo nº {$payment->receipt_number}", description: 'A fatura voltou a ficar pendente e a receita foi cancelada.');
    }

    #[Computed]
    public function refundingPayment(): ?Payment
    {
        return $this->refundingPaymentId ? $this->findCompanyPayment($this->refundingPaymentId) : null;
    }

    /**
     * A payment of this company, so another company's or holder's payment cannot be targeted.
     */
    protected function findCompanyPayment(int $paymentId): Payment
    {
        return $this->company->payments()->with('invoice')->findOrFail($paymentId);
    }

    /**
     * Tabs the user may open; the bills require `billing.view`.
     *
     * @return array<string, string>
     */
    protected function tabs(): array
    {
        return auth()->user()->can('billing.view') ? self::TABS : ['funcionarios' => self::TABS['funcionarios']];
    }

    public function render(): View
    {
        $tabs = $this->tabs();
        $tabs['funcionarios'] .= ' · '.$this->employees->count();

        return $this->view([
            'tabs' => $tabs,
            'canViewAmounts' => auth()->user()->can('billing.view'),
            'employeesInForce' => $this->employees->filter(fn (Customer $employee): bool => $employee->currentCard !== null),
        ])->title('Ficha da empresa')->layoutData(['crumb' => 'Empresas']);
    }
};
?>

<div class="flex flex-col gap-5">
    <x-ui.card size="hero" padding="p-6" class="flex flex-wrap items-center gap-6">
        <x-ui.avatar :initials="\App\Support\Format::initials($company->display_name)" size="xl" />
        <div class="flex min-w-60 flex-1 flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="font-display text-[26px] leading-[1.2] font-bold tracking-tight text-ink-800">{{ $company->display_name }}</span>
                <x-ui.badge :tone="$company->is_active ? 'green' : 'gray'" size="lg" dot>{{ $company->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge>
            </div>
            <span class="text-sm text-ink-500">{{ $company->name }} · CNPJ {{ $company->formatted_cnpj }}</span>
            <span class="text-sm text-ink-500">
                Vencimento todo dia {{ $company->due_day }} · {{ \App\Support\Format::phone($company->phone) }}
                @if ($company->contact_name) · {{ $company->contact_name }} @endif
                @if ($company->email) · {{ $company->email }} @endif
            </span>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('customers.update')
                <x-ui.button variant="secondary" size="lg" x-on:click="$dispatch('open-company', { company: {{ $company->id }} })">Editar</x-ui.button>
            @endcan
            @if ($company->is_active)
                @can('sales.create')
                    <x-ui.button size="lg" icon="user-plus" :href="route('sales.create', ['empresa' => $company->id])" wire:navigate>Novo funcionário</x-ui.button>
                @endcan
            @endif
        </div>
    </x-ui.card>

    @if ($canViewAmounts)
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-4">
            <x-ui.card class="flex flex-col gap-1">
                <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Funcionários ativos</span>
                <span class="font-display text-2xl font-bold text-ink-800">{{ $employeesInForce->count() }}</span>
            </x-ui.card>
            <x-ui.card class="flex flex-col gap-1">
                <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Vidas</span>
                <span class="font-display text-2xl font-bold text-ink-800">{{ $employeesInForce->count() + $employeesInForce->sum('active_dependents_count') }}</span>
            </x-ui.card>
            <x-ui.card class="flex flex-col gap-1">
                <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Fatura mensal</span>
                <span class="font-display text-2xl font-bold text-ink-800">{{ \App\Support\Format::money($employeesInForce->reduce(fn (string $sum, $employee): string => bcadd($sum, $employee->monthly_amount, 2), '0.00')) }}</span>
            </x-ui.card>
        </div>
    @endif

    <x-ui.tabs :tabs="$tabs" :active="$tab" model="tab" />

    @switch($tab)
        @case('faturas')
            <x-ui.card class="flex flex-col gap-4" wire:key="tab-faturas">
                @if ($this->bills->isEmpty())
                    <x-ui.empty-state icon="receipt" title="Nenhuma fatura" description="As mensalidades dos funcionários são somadas aqui, uma fatura por mês." />
                @else
                    @php($canPay = auth()->user()->can('billing.pay'))
                    @php($canRefund = auth()->user()->can('billing.refund'))
                    <x-ui.table :columns="['Referência', 'Vencimento', 'Funcionários', 'Total', 'A pagar', 'Status', '>Ações']" min-width="min-w-[820px]">
                        @foreach ($this->bills as $bill)
                            <tr wire:key="bill-{{ $bill['month']->toDateString() }}">
                                <td class="font-semibold text-ink-800">{{ $bill['month']->format('m/Y') }}</td>
                                <td>{{ \App\Support\Format::date($bill['due_date']) }}</td>
                                <td>{{ $bill['employees'] }}</td>
                                <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($bill['total']) }}</td>
                                <td>{{ bccomp($bill['payable'], '0', 2) === 0 ? '—' : \App\Support\Format::money($bill['payable']) }}</td>
                                <td>
                                    @if ($bill['days_overdue'] > 0)
                                        <x-ui.badge tone="red">Em atraso · {{ $bill['days_overdue'] }} {{ $bill['days_overdue'] === 1 ? 'dia' : 'dias' }}</x-ui.badge>
                                    @else
                                        <x-ui.badge :tone="$bill['status']->color()">{{ $bill['status']->label() }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($canPay && $bill['status']->isPayable())
                                        <x-ui.button variant="soft" size="sm" wire:click="openPayment('{{ $bill['month']->toDateString() }}')">Registrar pagamento</x-ui.button>
                                    @endif
                                    @foreach ($bill['payments'] as $payment)
                                        <x-ui.button wire:key="receipt-{{ $payment->id }}" variant="link" size="sm" :href="route('receipts.show', $payment)" target="_blank" rel="noopener">Recibo nº {{ $payment->receipt_number }}</x-ui.button>
                                        @if ($canRefund)
                                            <x-ui.button wire:key="refund-{{ $payment->id }}" variant="ghost" size="sm" class="text-danger-600" wire:click="openRefund({{ $payment->id }})">Estornar</x-ui.button>
                                        @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
            @break
        @default
            <x-ui.card class="flex flex-col gap-4" wire:key="tab-funcionarios">
                @if ($this->employees->isEmpty())
                    <x-ui.empty-state icon="users" title="Nenhum funcionário" description="Use “Novo funcionário” para vender o cartão a um funcionário da empresa." />
                @else
                    <x-ui.table :columns="array_values(array_filter(['Cartão', 'Nome', 'CPF', 'Plano', 'Dependentes', $canViewAmounts ? 'Valor mensal' : null, 'Status']))" min-width="min-w-[820px]">
                        @foreach ($this->employees as $employee)
                            @php($card = $employee->currentCard ?? $employee->lastCard)
                            @php($cardStatus = $card ? \App\Enums\CardStatus::fromId($card->card_status_id) : null)
                            <tr
                                wire:key="employee-{{ $employee->id }}"
                                x-on:click="Livewire.navigate(@js(route('customers.show', $employee)))"
                                class="cursor-pointer hover:bg-ink-25"
                            >
                                <td class="font-mono text-[13px]">{{ $employee->currentCard?->number ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('customers.show', $employee) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $employee->name }}</a>
                                </td>
                                <td class="font-mono text-[13px] text-ink-500">{{ $employee->masked_cpf }}</td>
                                <td>{{ $employee->plan->name }}</td>
                                <td>{{ $employee->active_dependents_count === 0 ? '—' : $employee->active_dependents_count }}</td>
                                @if ($canViewAmounts)
                                    <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($employee->monthly_amount) }}</td>
                                @endif
                                <td>
                                    @if ($cardStatus)
                                        <x-ui.badge :tone="$cardStatus->color()" dot>{{ $cardStatus->label() }}</x-ui.badge>
                                    @else
                                        <x-ui.badge dot>Sem cartão</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
    @endswitch

    @if ($this->payingBill)
        @php($bill = $this->payingBill)
        <x-ui.modal
            title="Registrar pagamento da fatura"
            :subtitle="$company->display_name.' · ref. '.$bill['month']->format('m/Y').' · '.$bill['employees'].' funcionário(s) · venc. '.\App\Support\Format::date($bill['due_date'])"
            close="closePayment"
        >
            @error('invoice')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

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
                        <x-ui.choice wire:key="company-method-{{ $option->slug->value }}" :active="$method === $option->slug->value" wire:click="$set('method', '{{ $option->slug->value }}')">
                            {{ $option->slug->label() }}
                        </x-ui.choice>
                    @endforeach
                </div>
                @error('method') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror
            </div>

            @if ($this->allowsInstallments)
                <x-ui.field label="Parcelas" for="company-payment-installments" error="installments">
                    <x-ui.select id="company-payment-installments" wire:model.number="installments" error="installments" :options="$this->installmentOptions" />
                </x-ui.field>
            @endif

            <x-ui.field label="Observação" for="company-payment-notes" error="notes">
                <x-ui.textarea id="company-payment-notes" wire:model="notes" error="notes" rows="2" placeholder="Opcional" />
            </x-ui.field>

            <span class="text-xs text-ink-500">Um único recibo, em nome da empresa, quita a mensalidade de todos os funcionários em aberto neste mês. Cartões suspensos são reativados.</span>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirmPayment" wire:loading.attr="disabled" wire:target="confirmPayment">Confirmar baixa</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif

    @if ($refundingPaymentId && $this->refundingPayment)
        @php($refundingPayment = $this->refundingPayment)
        <x-ui.modal
            title="Estornar pagamento"
            :subtitle="'Recibo nº '.$refundingPayment->receipt_number.' · ref. '.$refundingPayment->invoice->reference_month->format('m/Y').' · '.\App\Support\Format::money($refundingPayment->amount)"
            close="closeRefund"
        >
            <x-ui.callout tone="warning">As mensalidades dos funcionários voltam a ficar pendentes e a receita vinculada é cancelada. O pagamento continua registrado com o estorno.</x-ui.callout>

            <x-ui.field label="Motivo (opcional)" for="company-refund-reason" error="refundReason">
                <x-ui.textarea id="company-refund-reason" wire:model="refundReason" error="refundReason" rows="3" placeholder="Ex.: pagamento lançado em duplicidade" />
            </x-ui.field>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="refund" wire:loading.attr="disabled" wire:target="refund">Estornar pagamento</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif

    @can('customers.update')
        <livewire:companies.company-modal />
    @endcan
</div>
