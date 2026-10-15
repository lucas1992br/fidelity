<?php

use App\Enums\InvoiceStatus;
use App\Models\Plan;
use App\Queries\InvoicesQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invoices screen (US-8.4): summary cards and a paginated table of the
 * invoices filtered by period (reference month or due date interval),
 * status, plan and holder, all kept in the URL. Requires `billing.view`;
 * "Registrar pagamento" opens the payment modal (`billing.pay`).
 */
new class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $month = '';

    #[Url(as: 'due_from')]
    public string $dueFrom = '';

    #[Url(as: 'due_until')]
    public string $dueUntil = '';

    #[Url]
    public string $plan = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('billing.view');

        if ($this->month === '' && $this->dueFrom === '' && $this->dueUntil === '') {
            $this->month = today()->format('Y-m');
        }
    }

    /**
     * The period is either a reference month or a due date interval: picking
     * one clears the other. Any filter change goes back to the first page.
     */
    public function updated(string $property): void
    {
        if ($property === 'month' && $this->month !== '') {
            $this->reset('dueFrom', 'dueUntil');
        }

        if (in_array($property, ['dueFrom', 'dueUntil'], true) && ($this->dueFrom !== '' || $this->dueUntil !== '')) {
            $this->month = '';
        }

        if (in_array($property, ['status', 'month', 'dueFrom', 'dueUntil', 'plan', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'dueFrom', 'dueUntil', 'plan', 'search');
        $this->month = today()->format('Y-m');
        $this->resetPage();
    }

    /**
     * Render again after a payment so the summary and the statuses are up to date.
     */
    #[On('payment-registered')]
    public function refreshInvoices(): void {}

    protected function invoicesQuery(): InvoicesQuery
    {
        return new InvoicesQuery(
            month: $this->month ?: null,
            dueFrom: $this->dueFrom ?: null,
            dueUntil: $this->dueUntil ?: null,
            status: InvoiceStatus::tryFrom($this->status),
            planId: ctype_digit($this->plan) ? (int) $this->plan : null,
            customer: $this->search,
        );
    }

    public function render(): View
    {
        $query = $this->invoicesQuery();

        return $this->view([
            'invoices' => $query->ordered()->paginate(20),
            'summary' => $query->summary(),
            'statusFilters' => [
                '' => 'Todas',
                InvoiceStatus::Open->value => 'Em aberto',
                InvoiceStatus::Overdue->value => 'Em atraso',
                InvoiceStatus::Paid->value => 'Pagas',
                InvoiceStatus::Cancelled->value => 'Canceladas',
            ],
            'planOptions' => Plan::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canPay' => auth()->user()->can('billing.pay'),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        @foreach ([
            'total' => ['label' => 'Total', 'icon' => 'receipt', 'tone' => 'blue'],
            'paid' => ['label' => 'Pago', 'icon' => 'circle-check', 'tone' => 'green'],
            'open' => ['label' => 'Em aberto', 'icon' => 'clock', 'tone' => 'amber'],
            'overdue' => ['label' => 'Em atraso', 'icon' => 'circle-alert', 'tone' => 'red'],
        ] as $key => $card)
            <x-ui.stat
                wire:key="summary-{{ $key }}"
                :label="$card['label']"
                :icon="$card['icon']"
                :tone="$card['tone']"
                :value="\App\Support\Format::money($summary[$key]['amount'])"
                :note="$summary[$key]['count'].' '.($summary[$key]['count'] === 1 ? 'mensalidade' : 'mensalidades')"
            />
        @endforeach
    </div>

    <x-ui.card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.input
                wire:model.live.debounce.300ms="search"
                placeholder="Cliente: nome, CPF ou cartão"
                aria-label="Buscar cliente"
                class="min-w-[220px] flex-1"
            />
            <div class="w-48">
                <x-ui.select wire:model.live="plan" :options="$planOptions" placeholder="Todos os planos" aria-label="Plano" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
                Referência
                <x-ui.input type="month" wire:model.live="month" aria-label="Mês de referência" class="w-44" />
            </label>
            <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
                Vencimento de
                <x-ui.input type="date" wire:model.live="dueFrom" aria-label="Vencimento a partir de" class="w-44" />
            </label>
            <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
                até
                <x-ui.input type="date" wire:model.live="dueUntil" aria-label="Vencimento até" class="w-44" />
            </label>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($statusFilters as $value => $label)
                    <x-ui.pill wire:key="status-{{ $value ?: 'all' }}" :active="$status === $value" wire:click="$set('status', '{{ $value }}')">{{ $label }}</x-ui.pill>
                @endforeach
            </div>
        </div>

        @if ($invoices->isEmpty())
            <x-ui.empty-state icon="receipt" title="Nenhuma mensalidade encontrada" description="Ajuste o período ou os filtros para ver outras mensalidades.">
                <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
            </x-ui.empty-state>
        @else
            <x-ui.table :columns="['Cliente', 'Cartão', 'Referência', 'Vencimento', 'Valor', 'Status', '>Ações']" min-width="min-w-[820px]">
                @foreach ($invoices as $invoice)
                    @php($invoiceStatus = InvoiceStatus::fromId($invoice->invoice_status_id))
                    @php($daysOverdue = $invoice->days_overdue)
                    @php($card = $invoice->customer->currentCard ?? $invoice->customer->lastCard)
                    <tr wire:key="invoice-{{ $invoice->id }}">
                        <td>
                            <a href="{{ route('customers.show', $invoice->customer) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $invoice->customer->name }}</a>
                        </td>
                        <td class="font-mono text-[13px]">{{ $card?->number ?? '—' }}</td>
                        <td>{{ $invoice->reference_month->format('m/Y') }}</td>
                        <td>{{ \App\Support\Format::date($invoice->due_date) }}</td>
                        <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($invoice->amount) }}</td>
                        <td>
                            @if ($daysOverdue > 0)
                                <x-ui.badge tone="red">Em atraso · {{ $daysOverdue }} {{ $daysOverdue === 1 ? 'dia' : 'dias' }}</x-ui.badge>
                            @else
                                <x-ui.badge :tone="$invoiceStatus->color()">{{ $invoiceStatus->label() }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($invoice->isCompanyBilled() && $invoiceStatus->isPayable())
                                <x-ui.button variant="link" size="sm" :href="route('companies.show', $invoice->company_id)" wire:navigate>Fatura da empresa</x-ui.button>
                            @elseif ($canPay && $invoiceStatus->isPayable())
                                <x-ui.button variant="soft" size="sm" x-on:click="$dispatch('open-payment', { invoice: {{ $invoice->id }} })">Registrar pagamento</x-ui.button>
                            @elseif ($invoiceStatus === InvoiceStatus::Paid && $invoice->activePayment)
                                <x-ui.button variant="link" size="sm" :href="route('receipts.show', $invoice->activePayment)" target="_blank" rel="noopener">Recibo</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="text-[13px] text-ink-500">{{ $invoices->total() }} {{ $invoices->total() === 1 ? 'mensalidade' : 'mensalidades' }}</div>

            {{ $invoices->links() }}
        @endif
    </x-ui.card>
</div>
