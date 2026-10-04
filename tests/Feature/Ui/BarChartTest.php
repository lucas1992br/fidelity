<?php

test('bar chart serializes labels, toned datasets and the forecast line for Alpine', function () {
    $view = $this->blade(
        '<x-ui.bar-chart :labels="$labels" :datasets="$datasets" :line="$line" label="Receita x despesa" />',
        [
            'labels' => ['out' => 'out', 'nov' => 'nov'],
            'datasets' => [
                ['label' => 'Receita', 'data' => ['out' => '1234.56', 'nov' => '980.00'], 'tone' => 'guardian'],
                ['label' => 'Despesa', 'data' => ['out' => '400.00', 'nov' => '310.50'], 'tone' => 'embrace'],
            ],
            'line' => ['label' => 'Previsto', 'data' => ['1500.00', '1500.00']],
        ],
    );

    $view->assertSee('x-data="barChart"', false)
        ->assertSee('aria-label="Receita x despesa"', false);

    preg_match('/data-chart="([^"]*)"/', (string) $view, $matches);

    expect(json_decode(html_entity_decode($matches[1]), true))->toBe([
        'labels' => ['out', 'nov'],
        'datasets' => [
            ['label' => 'Receita', 'data' => ['1234.56', '980.00'], 'tone' => 'guardian'],
            ['label' => 'Despesa', 'data' => ['400.00', '310.50'], 'tone' => 'embrace'],
        ],
        'line' => ['label' => 'Previsto', 'data' => ['1500.00', '1500.00']],
    ]);
});

test('bar chart keeps the canvas out of Livewire morphs so re-renders do not duplicate it', function () {
    $this->blade('<x-ui.bar-chart :labels="[\'jan\']" :datasets="[[\'label\' => \'Receita\', \'data\' => [10], \'tone\' => \'guardian\']]" />')
        ->assertSeeInOrder(['data-chart=', 'wire:ignore', '<canvas x-ref="canvas"'], false);
});

test('the barChart Alpine component updates in place, formats reais in pt-BR and cleans up on destroy', function () {
    expect(file_get_contents(resource_path('js/app.js')))
        ->toContain("Alpine.data('barChart', barChart)")
        ->and(file_get_contents(resource_path('js/bar-chart.js')))
        ->toContain("new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })")
        ->toContain("attributeFilter: ['data-chart']")
        ->toContain('chart.update()')
        ->toContain('window.Chart.getChart(canvas)?.destroy()')
        ->toContain('destroy() {');
});
