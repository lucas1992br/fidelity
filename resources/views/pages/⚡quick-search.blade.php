<?php

use App\Actions\Customers\SearchCustomers;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Customers\CustomerSearchResult;
use App\Support\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quick search at the counter (US-5.1, US-5.2, US-5.3). Anyone with
 * `cards.check` sees the card status; billing data (prices, next invoice,
 * overdue amounts, full CPF) only shows with `billing.view`, the sheet link
 * with `customers.view` and the payment shortcut with `billing.pay`.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    public function mount(): void
    {
        $this->authorize('cards.check');
    }

    /**
     * Next open invoice, overdue count and total, and the invoice to pay
     * first, per customer id. Loaded in one query for all the results.
     *
     * @param  Collection<int, CustomerSearchResult>  $results
     * @return array<int, array{next: Invoice|null, overdueCount: int, overdueTotal: float, payable: Invoice|null}>
     */
    protected function billingSummaries(Collection $results): array
    {
        $invoices = Invoice::query()
            ->payable()
            ->whereIn('customer_id', $results->map(fn (CustomerSearchResult $result): int => $result->customer->id))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy('customer_id');

        return $results->mapWithKeys(function (CustomerSearchResult $result) use ($invoices): array {
            /** @var Collection<int, Invoice> $customerInvoices */
            $customerInvoices = $invoices->get($result->customer->id, collect());
            $overdue = $customerInvoices->filter(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Overdue));

            return [$result->customer->id => [
                'next' => $customerInvoices->first(fn (Invoice $invoice): bool => $invoice->hasStatus(InvoiceStatus::Open)),
                'overdueCount' => $overdue->count(),
                'overdueTotal' => $overdue->sum(fn (Invoice $invoice): int => (int) str_replace('.', '', $invoice->amount)) / 100,
                'payable' => $customerInvoices->first(),
            ]];
        })->all();
    }

    public function render(SearchCustomers $searchCustomers): View
    {
        $results = $searchCustomers->handle($this->query);
        $canSeeBilling = Navigation::allows('billing.view');

        return $this->view([
            'results' => $results,
            'canSeeBilling' => $canSeeBilling,
            'canOpenCustomer' => Navigation::allows('customers.view'),
            'canPay' => Navigation::allows('billing.pay'),
            'billing' => $canSeeBilling && $results->isNotEmpty() ? $this->billingSummaries($results) : [],
        ]);
    }
};
?>

<div class="mx-auto flex w-full max-w-[880px] flex-col gap-5">
    <div class="flex h-16 items-center gap-3.5 rounded-full border-2 border-guardian-500 bg-white px-5.5 shadow-focus">
        <x-ui.icon name="scan-line" class="size-[22px] text-guardian-500" />
        <input
            wire:model.live.debounce.300ms="query"
            autofocus
            placeholder="Digite o nome, CPF ou passe o cartão no leitor"
            aria-label="Buscar cliente"
            class="min-w-0 flex-1 border-0 bg-transparent text-[19px] text-ink-800 outline-none"
        >
        @if ($query !== '')
            <button type="button" wire:click="$set('query', '')" class="h-8 cursor-pointer rounded-full bg-ink-50 px-3 text-[13px] font-semibold text-ink-500">Limpar</button>
        @endif
    </div>

    @if ($query !== '' && $results->isEmpty())
        <div class="flex flex-col items-center gap-1.5 rounded-2xl bg-white p-10 text-center shadow-sm">
            <span class="text-[17px] font-semibold text-ink-800">Nenhum cliente encontrado</span>
            <span class="text-sm text-ink-500">Confira o número do cartão ou busque pelo CPF.</span>
        </div>
    @endif

    @foreach ($results as $result)
        @php
            $customer = $result->customer;
            $card = $customer->currentCard ?? $customer->lastCard;
            $status = $card ? \App\Enums\CardStatus::fromId($card->card_status_id) : null;
            $summary = $billing[$customer->id] ?? null;
            $payable = $canPay && $summary ? $summary['payable'] : null;
        @endphp

        <div wire:key="result-{{ $customer->id }}" class="overflow-hidden rounded-2xl bg-white shadow-md">
            @if ($result->replacedCardNumber)
                <div class="bg-warning-50 px-6 py-2.5 text-[13px] font-semibold text-warning-700">
                    @if ($result->currentCardNumber())
                        O cartão {{ $result->replacedCardNumber }} foi substituído. Cartão atual: {{ $result->currentCardNumber() }}.
                    @else
                        O cartão {{ $result->replacedCardNumber }} não está mais vigente. O cliente não tem cartão atual.
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-start gap-6 p-6">
                <div class="flex min-w-[260px] flex-1 flex-col gap-1.5">
                    <span class="font-display text-[26px] leading-[1.2] font-bold tracking-tight text-ink-800">{{ $customer->name }}</span>
                    <span class="text-sm text-ink-500">
                        CPF {{ $canSeeBilling ? $customer->formatted_cpf : $customer->masked_cpf }} · {{ \App\Support\Format::phone($customer->phone) }}
                    </span>
                    @if ($result->dependent)
                        <span class="text-sm font-semibold text-guardian-700">Dependente encontrado: {{ $result->dependent->name }} ({{ $result->dependent->kinship->name }})</span>
                    @endif
                </div>
                <div @class([
                    'flex flex-col items-center gap-1.5 rounded-xl px-7 py-4',
                    match ($status?->color()) {
                        'green' => 'bg-success-50 text-success-700',
                        'amber' => 'bg-warning-50 text-warning-700',
                        'red' => 'bg-danger-50 text-danger-700',
                        'blue' => 'bg-guardian-50 text-guardian-700',
                        default => 'bg-ink-100 text-ink-600',
                    },
                ])>
                    <span class="text-xs font-semibold tracking-[0.08em] uppercase">{{ $card ? 'Cartão '.$card->number : 'Sem cartão' }}</span>
                    <span class="font-display text-[32px] leading-none font-extrabold tracking-tight">{{ $status?->label() ?? '—' }}</span>
                </div>
            </div>

            @if ($canSeeBilling)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] border-t border-ink-100 [&>div]:flex [&>div]:flex-col [&>div]:gap-0.5 [&>div]:px-6 [&>div]:py-4 [&>div:not(:last-child)]:border-r [&>div:not(:last-child)]:border-ink-100">
                    <div>
                        <span class="text-xs text-ink-500">Plano</span>
                        <span class="text-[15px] font-semibold text-ink-800">{{ $customer->plan->name }} · {{ \App\Support\Format::money($customer->plan->price) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-500">Próxima mensalidade</span>
                        <span class="text-[15px] font-semibold text-ink-800">{{ $summary['next'] ? \App\Support\Format::date($summary['next']->due_date).' · '.\App\Support\Format::money($summary['next']->amount) : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-500">Pendências</span>
                        @if ($summary['overdueCount'] > 0)
                            <span class="text-[15px] font-semibold text-danger-600">{{ $summary['overdueCount'] }} em atraso · {{ \App\Support\Format::money($summary['overdueTotal']) }}</span>
                        @else
                            <span class="text-[15px] font-semibold text-success-700">Nenhuma pendência</span>
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-0.5 border-t border-ink-100 px-6 py-4">
                    <span class="text-xs text-ink-500">Plano</span>
                    <span class="text-[15px] font-semibold text-ink-800">{{ $customer->plan->name }}</span>
                    @if ($status === \App\Enums\CardStatus::Suspended)
                        <span class="mt-2 text-sm font-semibold text-warning-700">Cartão suspenso — encaminhar ao financeiro</span>
                    @endif
                </div>
            @endif

            @if ($customer->activeDependents->isNotEmpty())
                <div class="flex flex-col gap-2.5 border-t border-ink-100 px-6 py-4">
                    <span class="text-xs text-ink-500">Dependentes · usam o mesmo cartão</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($customer->activeDependents as $dependent)
                            <span wire:key="dep-{{ $dependent->id }}" @class([
                                'flex items-center gap-2 rounded-full px-3.5 py-1.5 text-[13px]',
                                'bg-guardian-50 ring-2 ring-guardian-500' => $result->dependent?->is($dependent),
                                'bg-ink-50' => ! $result->dependent?->is($dependent),
                            ])>
                                <span class="font-mono text-xs text-ink-500">{{ $dependent->identification ?? '—' }}</span>
                                <span class="font-semibold text-ink-800">{{ $dependent->name }}</span>
                                <span class="text-ink-500">{{ $dependent->kinship->name }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            @php($showPayment = $payable && $status !== \App\Enums\CardStatus::Cancelled)
            @if ($canOpenCustomer || $showPayment)
                <div class="flex flex-wrap gap-2.5 border-t border-ink-100 bg-ink-25 px-6 py-4">
                    @if ($canOpenCustomer)
                        <x-ui.button size="lg" :href="route('customers.show', $customer)" wire:navigate>Abrir ficha</x-ui.button>
                    @endif
                    @if ($showPayment)
                        <x-ui.button size="lg" variant="secondary" wire:click="$dispatch('open-payment', { invoice: {{ $payable->id }} })">Registrar pagamento</x-ui.button>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
