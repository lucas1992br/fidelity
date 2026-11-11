<?php

use App\Enums\PaymentMethod;
use App\Services\DashboardMetrics;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard (US-11.1) with a month filter kept in the URL (`?mes=2026-10`,
 * the current month by default, never a future one). The month cards
 * (income and expense on cash basis, sales, billed and received), the
 * income x expense chart of the 12 months up to it and its payments follow
 * the chosen month; the current position cards (cards, lives, delinquency,
 * open and overdue amounts) and the longest delays are always today's,
 * since their past states are not kept. Requires `dashboard.view`.
 */
new class extends Component
{
    #[Url(as: 'mes')]
    public string $month = '';

    public function mount(): void
    {
        $this->authorize('dashboard.view');

        $this->month = $this->selectedMonth->format('Y-m');
    }

    /**
     * Chosen month; an invalid or future value falls back to the current month.
     */
    #[Computed]
    public function selectedMonth(): CarbonImmutable
    {
        $current = CarbonImmutable::today()->startOfMonth();

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            return $current;
        }

        $month = CarbonImmutable::parse("{$this->month}-01");

        return $month->gt($current) ? $current : $month;
    }

    #[Computed]
    public function isCurrentMonth(): bool
    {
        return $this->selectedMonth->isSameMonth(CarbonImmutable::today());
    }

    /**
     * Keep the typed month valid (never empty nor in the future).
     */
    public function updatedMonth(): void
    {
        $this->showMonth($this->month);
    }

    public function previousMonth(): void
    {
        $this->showMonth($this->selectedMonth->subMonthNoOverflow()->format('Y-m'));
    }

    public function nextMonth(): void
    {
        $this->showMonth($this->selectedMonth->addMonthNoOverflow()->format('Y-m'));
    }

    public function currentMonth(): void
    {
        $this->showMonth(today()->format('Y-m'));
    }

    protected function showMonth(string $month): void
    {
        $this->month = $month;
        unset($this->selectedMonth, $this->isCurrentMonth);
        $this->month = $this->selectedMonth->format('Y-m');
    }

    /**
     * Cards of the chosen month.
     *
     * @return list<array{label: string, value: string, note: string, icon: string, tone: string}>
     */
    protected function monthKpis(DashboardMetrics $metrics, CarbonImmutable $month): array
    {
        $totals = $metrics->monthTotals($month);
        $sales = $metrics->monthSales($month);
        $billing = $metrics->monthBilling($month);
        $receivedRate = bccomp($billing['billed'], '0', 2) === 0
            ? 0.0
            : round((float) bcdiv(bcmul($billing['received'], '100', 4), $billing['billed'], 4), 1);

        return [
            ['label' => 'Receita do mês', 'value' => Format::money($totals['income']), 'note' => 'Recebido no mês · regime de caixa', 'icon' => 'arrow-down-left', 'tone' => 'green'],
            ['label' => 'Despesa do mês', 'value' => Format::money($totals['expense']), 'note' => 'Pago no mês · regime de caixa', 'icon' => 'arrow-up-right', 'tone' => 'gray'],
            [
                'label' => 'Vendas do mês',
                'value' => Number::format($sales['sales'], locale: 'pt_BR'),
                'note' => $sales['reenrollments'] === 1 ? 'Adesões · 1 reingresso' : "Adesões · {$sales['reenrollments']} reingressos",
                'icon' => 'user-plus',
                'tone' => 'blue',
            ],
            [
                'label' => 'Mensalidades do mês',
                'value' => Format::money($billing['billed']),
                'note' => Format::money($billing['received']).' recebido · '.Number::format($receivedRate, precision: 1, locale: 'pt_BR').'%',
                'icon' => 'receipt',
                'tone' => 'blue',
            ],
        ];
    }

    /**
     * Cards of the current position, whatever the chosen month.
     *
     * @return list<array{label: string, value: string, note: string, icon: string, tone: string, alert?: bool}>
     */
    protected function positionKpis(DashboardMetrics $metrics): array
    {
        $cards = $metrics->cards();
        $delinquency = $metrics->delinquency();

        return [
            ['label' => 'Cartões ativos', 'value' => Number::format($cards['active'], locale: 'pt_BR'), 'note' => 'Titulares com cartão ativo', 'icon' => 'credit-card', 'tone' => 'blue'],
            [
                'label' => 'Cartões em estoque',
                'value' => Number::format($cards['in_stock'], locale: 'pt_BR'),
                'note' => $cards['low_stock'] ? "Abaixo do mínimo de {$cards['min_stock']}" : "Mínimo de {$cards['min_stock']}",
                'icon' => 'package',
                'tone' => $cards['low_stock'] ? 'amber' : 'blue',
                'alert' => $cards['low_stock'],
            ],
            ['label' => 'Vidas atendidas', 'value' => Number::format($metrics->livesServed(), locale: 'pt_BR'), 'note' => 'Titulares + dependentes ativos', 'icon' => 'heart-handshake', 'tone' => 'blue'],
            [
                'label' => 'Inadimplência',
                'value' => Number::format($delinquency['rate'], precision: 1, locale: 'pt_BR').'%',
                'note' => "{$delinquency['delinquent']} de {$delinquency['holders']} titulares",
                'icon' => 'trending-down',
                'tone' => 'red',
            ],
            ['label' => 'Valor em aberto', 'value' => Format::money($metrics->openAmount()), 'note' => 'Em aberto + em atraso', 'icon' => 'clock', 'tone' => 'blue'],
            ['label' => 'Valor em atraso', 'value' => Format::money($metrics->overdueAmount()), 'note' => 'Faturas vencidas', 'icon' => 'circle-alert', 'tone' => 'red'],
        ];
    }

    public function render(): View
    {
        $metrics = app(DashboardMetrics::class);
        $month = $this->selectedMonth;

        return $this->view([
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'monthKpis' => $this->monthKpis($metrics, $month),
            'positionKpis' => $this->positionKpis($metrics),
            'series' => $metrics->monthlySeries(until: $month),
            'delinquents' => $metrics->topDelinquents(),
            'payments' => $metrics->latestPayments(month: $month),
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex flex-1 flex-col">
            <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Visão do mês</span>
            <span class="font-display text-2xl font-bold text-ink-800">{{ $monthLabel }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <x-ui.button variant="secondary" size="sm" icon="chevron-left" wire:click="previousMonth" aria-label="Mês anterior" />
            <x-ui.input type="month" wire:model.live="month" :max="today()->format('Y-m')" aria-label="Mês do painel" class="w-44" />
            <x-ui.button variant="secondary" size="sm" icon="chevron-right" wire:click="nextMonth" aria-label="Próximo mês" :disabled="$this->isCurrentMonth" />
            @unless ($this->isCurrentMonth)
                <x-ui.button variant="link" size="sm" wire:click="currentMonth">Mês atual</x-ui.button>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4" wire:loading.class="opacity-60" wire:target="month,previousMonth,nextMonth,currentMonth">
        @foreach ($monthKpis as $kpi)
            <x-ui.stat wire:key="month-kpi-{{ $loop->index }}" :label="$kpi['label']" :value="$kpi['value']" :note="$kpi['note']" :icon="$kpi['icon']" :tone="$kpi['tone']" />
        @endforeach
    </div>

    <div class="flex flex-col gap-3">
        <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Posição atual · {{ Format::date(today()) }}</span>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
            @foreach ($positionKpis as $kpi)
                <x-ui.stat wire:key="position-kpi-{{ $loop->index }}" :label="$kpi['label']" :value="$kpi['value']" :note="$kpi['note']" :icon="$kpi['icon']" :tone="$kpi['tone']" :alert="$kpi['alert'] ?? false" />
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,460px),1fr))] gap-4">
        <x-ui.card class="flex flex-col gap-5">
            <x-ui.card-title>Receita x despesa · 12 meses até {{ $this->selectedMonth->format('m/Y') }}</x-ui.card-title>
            <x-ui.bar-chart
                wire:key="chart-{{ $this->selectedMonth->format('Y-m') }}"
                label="Receita e despesa pagas nos 12 meses até o mês escolhido"
                :labels="array_column($series, 'label')"
                :datasets="[
                    ['label' => 'Receita', 'data' => array_column($series, 'income'), 'tone' => 'guardian'],
                    ['label' => 'Despesa', 'data' => array_column($series, 'expense'), 'tone' => 'embrace'],
                ]"
            />
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-3">
            <div class="flex items-center">
                <x-ui.card-title class="flex-1">Maiores atrasos · hoje</x-ui.card-title>
                <a href="{{ route('billing.delinquents') }}" wire:navigate class="text-[13px] font-semibold">Ver inadimplentes →</a>
            </div>
            @forelse ($delinquents as $delinquent)
                <a
                    href="{{ $delinquent->url() }}"
                    wire:navigate
                    wire:key="late-{{ $delinquent->kind }}-{{ $delinquent->id }}"
                    class="flex items-center gap-3 rounded-sm border-t border-ink-100 px-2 py-2.5 no-underline hover:bg-ink-50 hover:no-underline"
                >
                    <x-ui.avatar :initials="Format::initials($delinquent->name)" size="sm" tone="gray" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="text-sm font-semibold text-ink-800">{{ $delinquent->name }}</span>
                        <span class="text-xs text-ink-500">{{ $delinquent->overdueCount }} {{ $delinquent->overdueCount === 1 ? 'fatura' : 'faturas' }} · {{ $delinquent->isCompany() ? 'empresa, '.$delinquent->situation() : ($delinquent->cardStatus ? 'cartão '.mb_strtolower($delinquent->cardStatus->label()) : 'sem cartão') }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-0.5">
                        <span class="text-sm font-bold text-ink-800">{{ Format::money($delinquent->overdueAmount) }}</span>
                        <span class="text-xs font-semibold text-danger-600">{{ $delinquent->maxDaysOverdue }} {{ $delinquent->maxDaysOverdue === 1 ? 'dia' : 'dias' }}</span>
                    </div>
                </a>
            @empty
                <p class="border-t border-ink-100 px-2 py-4 text-sm text-ink-500">Nenhuma mensalidade em atraso.</p>
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card class="flex flex-col gap-2">
        <x-ui.card-title class="mb-2">Últimos pagamentos · {{ $monthLabel }}</x-ui.card-title>
        @if ($payments->isEmpty())
            <x-ui.empty-state title="Nenhum pagamento no mês" description="Os pagamentos de mensalidades do mês escolhido aparecem aqui." />
        @else
            <x-ui.table :columns="['Recibo', 'Cliente', 'Referência', 'Forma', 'Data', '>Valor']" min-width="min-w-[600px]">
                @foreach ($payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}">
                        <td class="font-mono text-[13px] text-ink-500">#{{ $payment->receipt_number }}</td>
                        <td class="font-semibold text-ink-800">{{ $payment->isCompanyPayment() ? $payment->company->display_name : $payment->invoice->customer->name }}</td>
                        <td>{{ $payment->referencePeriod() }}</td>
                        <td>{{ PaymentMethod::fromId($payment->payment_method_id)->label() }}</td>
                        <td>{{ Format::date($payment->paid_at) }}</td>
                        <td class="text-right font-semibold text-ink-800">{{ Format::money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
