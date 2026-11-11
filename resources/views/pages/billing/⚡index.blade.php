<?php

use App\Enums\InvoiceStatus;
use App\Models\Plan;
use App\Queries\InvoicesQuery;
use App\Support\Billing\BillingRow;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invoices screen (US-8.4): summary cards and a paginated table of the
 * individual holders' invoices and the company bills (one line per company
 * and month, in place of the employees), filtered by period (reference
 * month or due date interval), status, plan and holder or company, all kept
 * in the URL. Requires `billing.view`; "Registrar pagamento" opens the
 * payment modal of a holder invoice, or the company sheet of a company bill
 * (`billing.pay`).
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
            'invoices' => $query->rows()->paginate(20)->through(fn (object $record): BillingRow => BillingRow::fromRecord($record)),
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
                placeholder="Cliente (nome, CPF ou cartão) ou empresa (nome ou CNPJ)"
                aria-label="Buscar cliente ou empresa"
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
                @foreach ($invoices as $row)
                    @php($daysOverdue = $row->daysOverdue())
                    <tr wire:key="{{ $row->kind }}-{{ $row->id }}-{{ $row->referenceMonth->format('Y-m') }}">
                        <td>
                            @if ($row->isCompanyBill())
                                <a href="{{ route('companies.show', ['company' => $row->companyId, 'tab' => 'faturas']) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $row->name }}</a>
                                <div class="text-xs text-ink-500">Fatura da empresa · {{ $row->employees }} {{ $row->employees === 1 ? 'funcionário' : 'funcionários' }}</div>
                            @else
                                <a href="{{ route('customers.show', $row->customerId) }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $row->name }}</a>
                            @endif
                        </td>
                        <td class="font-mono text-[13px]">{{ $row->cardNumber ?? '—' }}</td>
                        <td>{{ $row->referenceMonth->format('m/Y') }}</td>
                        <td>{{ \App\Support\Format::date($row->dueDate) }}</td>
                        <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($row->amount) }}</td>
                        <td>
                            @if ($daysOverdue > 0)
                                <x-ui.badge tone="red">Em atraso · {{ $daysOverdue }} {{ $daysOverdue === 1 ? 'dia' : 'dias' }}</x-ui.badge>
                            @else
                                <x-ui.badge :tone="$row->status->color()">{{ $row->status->label() }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($canPay && $row->status->isPayable())
                                @if ($row->isCompanyBill())
                                    <x-ui.button variant="soft" size="sm" :href="route('companies.show', ['company' => $row->companyId, 'tab' => 'faturas'])" wire:navigate>Registrar pagamento</x-ui.button>
                                @else
                                    <x-ui.button variant="soft" size="sm" x-on:click="$dispatch('open-payment', { invoice: {{ $row->id }} })">Registrar pagamento</x-ui.button>
                                @endif
                            @elseif ($row->status === InvoiceStatus::Paid && $row->paymentId)
                                <x-ui.button variant="link" size="sm" :href="route('receipts.show', $row->paymentId)" target="_blank" rel="noopener">Recibo</x-ui.button>
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
