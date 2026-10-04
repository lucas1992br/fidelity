{{--
    Bar chart (Chart.js via Alpine). Datasets: [['label' => 'Receita', 'data' => [...], 'tone' => 'guardian'|'embrace']].
    Optional line series: ['label' => 'Previsto', 'data' => [...]]. Values are reais (decimal strings or numbers).
--}}
@props([
    'labels' => [],
    'datasets' => [],
    'line' => null,
    'label' => 'Gráfico de barras',
    'height' => 'h-[220px]',
])

@php
    $chart = [
        'labels' => array_values($labels),
        'datasets' => array_map(fn (array $dataset): array => [...$dataset, 'data' => array_values($dataset['data'])], $datasets),
        'line' => $line ? [...$line, 'data' => array_values($line['data'])] : null,
    ];
@endphp

<div
    x-data="barChart"
    data-chart="{{ json_encode($chart) }}"
    {{ $attributes->class(['relative w-full min-w-0', $height]) }}
>
    <div wire:ignore class="absolute inset-0">
        <canvas x-ref="canvas" role="img" aria-label="{{ $label }}"></canvas>
    </div>
</div>
