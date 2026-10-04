<?php

use App\Support\Prototype\DemoData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

new class extends Component
{
    public function render(): View
    {
        return $this->view([
            'kpis' => DemoData::kpis(),
            'chart' => DemoData::chart(),
            'chartMax' => 40,
            'delinquents' => DemoData::delinquents()->take(5),
            'payments' => DemoData::lastPayments(),
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
            <div class="flex flex-wrap items-center gap-4">
                <x-ui.card-title class="flex-1">Receita x despesa · últimos 12 meses</x-ui.card-title>
                <div class="flex gap-3.5 text-xs text-ink-500">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-guardian-500"></span>Receita</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-embrace-300"></span>Despesa</span>
                </div>
            </div>
            <div class="flex h-[180px] items-end gap-2 border-b border-ink-100">
                @foreach ($chart as $bar)
                    <div
                        wire:key="bar-{{ $bar['month'] }}"
                        title="{{ $bar['month'] }}: receita R$ {{ number_format($bar['income'], 1, ',', '.') }} mil · despesa R$ {{ number_format($bar['expense'], 1, ',', '.') }} mil"
                        class="flex h-full flex-1 items-end justify-center gap-[3px]"
                    >
                        <div class="w-[42%] max-w-3.5 rounded-t bg-guardian-500" style="height: {{ $bar['income'] / $chartMax * 100 }}%"></div>
                        <div class="w-[42%] max-w-3.5 rounded-t bg-embrace-300" style="height: {{ $bar['expense'] / $chartMax * 100 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="-mt-3 flex gap-2">
                @foreach ($chart as $bar)
                    <div wire:key="month-{{ $bar['month'] }}" class="flex-1 text-center text-[11px] text-ink-400">{{ $bar['month'] }}</div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-3">
            <div class="flex items-center">
                <x-ui.card-title class="flex-1">Maiores atrasos</x-ui.card-title>
                <a href="{{ route('billing.delinquents') }}" wire:navigate class="text-[13px] font-semibold">Ver inadimplentes →</a>
            </div>
            @foreach ($delinquents as $row)
                <a
                    href="{{ route('customers.show', $row['customer']['id']) }}"
                    wire:navigate
                    wire:key="late-{{ $row['customer']['id'] }}"
                    class="flex items-center gap-3 rounded-sm border-t border-ink-100 px-2 py-2.5 no-underline hover:bg-ink-50 hover:no-underline"
                >
                    <x-ui.avatar :initials="\App\Support\Format::initials($row['customer']['name'])" size="sm" tone="gray" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="text-sm font-semibold text-ink-800">{{ $row['customer']['name'] }}</span>
                        <span class="text-xs text-ink-500">{{ $row['count'] }} {{ Str::plural('fatura', $row['count']) }} · cartão {{ $row['customer']['card'] }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-0.5">
                        <span class="text-sm font-bold text-ink-800">{{ \App\Support\Format::money($row['total']) }}</span>
                        <span class="text-xs font-semibold text-danger-600">{{ $row['maxDays'] }} dias</span>
                    </div>
                </a>
            @endforeach
        </x-ui.card>
    </div>

    <x-ui.card class="flex flex-col gap-2">
        <x-ui.card-title class="mb-2">Últimos pagamentos</x-ui.card-title>
        <x-ui.table :columns="['Recibo', 'Cliente', 'Referência', 'Forma', 'Data', '>Valor']" min-width="min-w-[600px]">
            @foreach ($payments as $payment)
                <tr wire:key="payment-{{ $payment['receipt'] }}">
                    <td class="font-mono text-[13px] text-ink-500">#{{ $payment['receipt'] }}</td>
                    <td class="font-semibold text-ink-800">{{ $payment['name'] }}</td>
                    <td>{{ $payment['ref'] }}</td>
                    <td>{{ $payment['method']->label() }}</td>
                    <td>{{ $payment['date'] }}</td>
                    <td class="text-right font-semibold text-ink-800">{{ \App\Support\Format::money($payment['amount']) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
