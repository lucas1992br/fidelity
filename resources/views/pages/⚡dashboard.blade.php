<?php

use App\Enums\PaymentMethod;
use App\Services\DashboardMetrics;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Component;

/**
 * Dashboard (US-11.1): cards, lives served, month income and expense, open
 * and overdue amounts and the delinquency rate from DashboardMetrics, the
 * income x expense chart of the last 12 months, the longest delays and the
 * latest payments. Requires `dashboard.view`.
 */
new class extends Component
{
    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    /**
     * Indicator cards in the order of the design.
     *
     * @return list<array{label: string, value: string, note: string, icon: string, tone: string, alert?: bool}>
     */
    protected function kpis(DashboardMetrics $metrics): array
    {
        $cards = $metrics->cards();
        $month = $metrics->monthTotals();
        $delinquency = $metrics->delinquency();
        $monthName = ucfirst(today()->locale('pt_BR')->translatedFormat('F'));

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
            ['label' => 'Receita do mês', 'value' => Format::money($month['income']), 'note' => "{$monthName} · regime de caixa", 'icon' => 'arrow-down-left', 'tone' => 'green'],
            ['label' => 'Despesa do mês', 'value' => Format::money($month['expense']), 'note' => "{$monthName} · regime de caixa", 'icon' => 'arrow-up-right', 'tone' => 'gray'],
            ['label' => 'Valor em aberto', 'value' => Format::money($metrics->openAmount()), 'note' => 'Em aberto + em atraso', 'icon' => 'clock', 'tone' => 'blue'],
            ['label' => 'Valor em atraso', 'value' => Format::money($metrics->overdueAmount()), 'note' => 'Faturas vencidas', 'icon' => 'circle-alert', 'tone' => 'red'],
        ];
    }

    public function render(): View
    {
        $metrics = app(DashboardMetrics::class);

        return $this->view([
            'kpis' => $this->kpis($metrics),
            'series' => $metrics->monthlySeries(),
            'delinquents' => $metrics->topDelinquents(),
            'payments' => $metrics->latestPayments(),
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
        @foreach ($kpis as $kpi)
            <x-ui.stat wire:key="kpi-{{ $loop->index }}" :label="$kpi['label']" :value="$kpi['value']" :note="$kpi['note']" :icon="$kpi['icon']" :tone="$kpi['tone']" :alert="$kpi['alert'] ?? false" />
        @endforeach
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,460px),1fr))] gap-4">
        <x-ui.card class="flex flex-col gap-5">
            <x-ui.card-title>Receita x despesa · últimos 12 meses</x-ui.card-title>
            <x-ui.bar-chart
                label="Receita e despesa pagas nos últimos 12 meses"
                :labels="array_column($series, 'label')"
                :datasets="[
                    ['label' => 'Receita', 'data' => array_column($series, 'income'), 'tone' => 'guardian'],
                    ['label' => 'Despesa', 'data' => array_column($series, 'expense'), 'tone' => 'embrace'],
                ]"
            />
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-3">
            <div class="flex items-center">
                <x-ui.card-title class="flex-1">Maiores atrasos</x-ui.card-title>
                <a href="{{ route('billing.delinquents') }}" wire:navigate class="text-[13px] font-semibold">Ver inadimplentes →</a>
            </div>
            @forelse ($delinquents as $delinquent)
                <a
                    href="{{ route('customers.show', $delinquent) }}"
                    wire:navigate
                    wire:key="late-{{ $delinquent->id }}"
                    class="flex items-center gap-3 rounded-sm border-t border-ink-100 px-2 py-2.5 no-underline hover:bg-ink-50 hover:no-underline"
                >
                    <x-ui.avatar :initials="Format::initials($delinquent->name)" size="sm" tone="gray" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="text-sm font-semibold text-ink-800">{{ $delinquent->name }}</span>
                        <span class="text-xs text-ink-500">{{ $delinquent->overdue_invoices_count }} {{ $delinquent->overdue_invoices_count === 1 ? 'fatura' : 'faturas' }} · {{ $delinquent->card_status_id ? 'cartão '.mb_strtolower(\App\Enums\CardStatus::fromId($delinquent->card_status_id)->label()) : 'sem cartão' }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-0.5">
                        <span class="text-sm font-bold text-ink-800">{{ Format::money($delinquent->overdue_amount) }}</span>
                        <span class="text-xs font-semibold text-danger-600">{{ $delinquent->max_days_overdue }} {{ $delinquent->max_days_overdue === 1 ? 'dia' : 'dias' }}</span>
                    </div>
                </a>
            @empty
                <p class="border-t border-ink-100 px-2 py-4 text-sm text-ink-500">Nenhuma mensalidade em atraso.</p>
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card class="flex flex-col gap-2">
        <x-ui.card-title class="mb-2">Últimos pagamentos</x-ui.card-title>
        @if ($payments->isEmpty())
            <x-ui.empty-state title="Nenhum pagamento registrado" description="Os pagamentos de mensalidades aparecem aqui." />
        @else
            <x-ui.table :columns="['Recibo', 'Cliente', 'Referência', 'Forma', 'Data', '>Valor']" min-width="min-w-[600px]">
                @foreach ($payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}">
                        <td class="font-mono text-[13px] text-ink-500">#{{ $payment->receipt_number }}</td>
                        <td class="font-semibold text-ink-800">{{ $payment->invoice->customer->name }}</td>
                        <td>{{ $payment->invoice->reference_month->format('m/Y') }}</td>
                        <td>{{ PaymentMethod::fromId($payment->payment_method_id)->label() }}</td>
                        <td>{{ Format::date($payment->paid_at) }}</td>
                        <td class="text-right font-semibold text-ink-800">{{ Format::money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
