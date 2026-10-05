<?php

use App\Services\CashFlowReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cash flow (US-10.6): daily view of a month or monthly view of a year on a
 * cash basis, with the opening balance, inflows, outflows, period and
 * cumulative balances, a bar chart and the optional forecast line. View,
 * period and forecast are kept in the URL. Requires `finance.view`.
 */
new class extends Component
{
    #[Url(as: 'view')]
    public string $mode = CashFlowReport::Daily;

    #[Url]
    public string $month = '';

    #[Url]
    public string $year = '';

    #[Url]
    public bool $forecast = false;

    public function mount(): void
    {
        $this->authorize('finance.view');

        if ($this->month === '') {
            $this->month = today()->format('Y-m');
        }

        if ($this->year === '') {
            $this->year = today()->format('Y');
        }
    }

    /**
     * Selected month as [year, month], falling back to the current month when the URL value does not parse.
     *
     * @return array{int, int}
     */
    protected function selectedMonth(): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $this->month, $parts) === 1 && (int) $parts[2] >= 1 && (int) $parts[2] <= 12) {
            return [(int) $parts[1], (int) $parts[2]];
        }

        return [today()->year, today()->month];
    }

    protected function selectedYear(): int
    {
        return preg_match('/^\d{4}$/', $this->year) === 1 ? (int) $this->year : today()->year;
    }

    public function render(): View
    {
        $cashFlowReport = app(CashFlowReport::class);
        [$year, $month] = $this->selectedMonth();

        $report = $this->mode === CashFlowReport::Monthly
            ? $cashFlowReport->monthly($this->selectedYear(), $this->forecast)
            : $cashFlowReport->daily($year, $month, $this->forecast);

        $currentYear = today()->year;

        return $this->view([
            'report' => $report,
            'modeOptions' => [CashFlowReport::Daily => 'Diário', CashFlowReport::Monthly => 'Mensal'],
            'yearOptions' => collect(range($currentYear + 1, $currentYear - 5))->mapWithKeys(fn (int $year): array => [(string) $year => (string) $year])->all(),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($modeOptions as $value => $label)
                <x-ui.pill wire:key="mode-{{ $value }}" :active="$mode === $value" wire:click="$set('mode', '{{ $value }}')">{{ $label }}</x-ui.pill>
            @endforeach
        </div>

        @if ($mode === CashFlowReport::Monthly)
            <div class="w-32">
                <x-ui.select wire:model.live="year" :options="$yearOptions" aria-label="Ano" />
            </div>
        @else
            <x-ui.input type="month" wire:model.live="month" aria-label="Mês" class="w-44" />
        @endif

        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
            <input type="checkbox" wire:model.live="forecast" class="size-4 accent-guardian-500" />
            Mostrar previsto
        </label>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        <x-ui.stat label="Saldo inicial" icon="calendar" tone="gray" :value="\App\Support\Format::money($report['opening_balance'])" :note="'Antes de '.\App\Support\Format::date($report['start'])" />
        <x-ui.stat label="Entradas" icon="arrow-down-left" tone="green" :value="\App\Support\Format::money($report['total_income'])" />
        <x-ui.stat label="Saídas" icon="arrow-up-right" tone="red" :value="\App\Support\Format::money($report['total_expense'])" />
        <x-ui.stat label="Saldo final" icon="chart-column" tone="blue" :value="\App\Support\Format::money($report['closing_balance'])" :note="'Em '.\App\Support\Format::date($report['end'])" />
    </div>

    <x-ui.card class="flex flex-col gap-4">
        <x-ui.card-title>Entradas x saídas</x-ui.card-title>
        <x-ui.bar-chart
            label="Entradas e saídas do período"
            :labels="array_column($report['rows'], 'label')"
            :datasets="[
                ['label' => 'Entradas', 'data' => array_column($report['rows'], 'income'), 'tone' => 'success'],
                ['label' => 'Saídas', 'data' => array_column($report['rows'], 'expense'), 'tone' => 'danger'],
            ]"
            :line="$report['with_forecast'] ? [
                'label' => 'Previsto (saldo)',
                'data' => array_map(fn (array $row): string => bcsub($row['forecast_income'], $row['forecast_expense'], 2), $report['rows']),
            ] : null"
            height="h-[260px]"
        />
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-3">
        <x-ui.table
            :columns="[
                $mode === CashFlowReport::Monthly ? 'Mês' : 'Dia',
                '>Entradas',
                '>Saídas',
                '>Saldo',
                '>Saldo acumulado',
                ...($report['with_forecast'] ? ['>Previsto entradas', '>Previsto saídas'] : []),
            ]"
            min-width="min-w-[720px]"
        >
            @foreach ($report['rows'] as $row)
                <tr wire:key="row-{{ $row['key'] }}" data-row="{{ $row['key'] }}">
                    <td class="font-semibold text-ink-800 whitespace-nowrap">{{ $row['label'] }}</td>
                    <td class="text-right whitespace-nowrap text-success-700">{{ \App\Support\Format::money($row['income']) }}</td>
                    <td class="text-right whitespace-nowrap text-danger-700">{{ \App\Support\Format::money($row['expense']) }}</td>
                    <td @class(['text-right font-semibold whitespace-nowrap', 'text-danger-700' => str_starts_with($row['balance'], '-'), 'text-ink-800' => ! str_starts_with($row['balance'], '-')])>{{ \App\Support\Format::money($row['balance']) }}</td>
                    <td @class(['text-right font-semibold whitespace-nowrap', 'text-danger-700' => str_starts_with($row['cumulative'], '-'), 'text-ink-800' => ! str_starts_with($row['cumulative'], '-')])>{{ \App\Support\Format::money($row['cumulative']) }}</td>
                    @if ($report['with_forecast'])
                        <td class="text-right whitespace-nowrap text-ink-500">{{ \App\Support\Format::money($row['forecast_income']) }}</td>
                        <td class="text-right whitespace-nowrap text-ink-500">{{ \App\Support\Format::money($row['forecast_expense']) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
