<?php

test('chart.js is a frontend dependency exposed on window', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);

    expect($package['dependencies'])->toHaveKey('chart.js')
        ->and(file_get_contents(resource_path('js/app.js')))
        ->toContain("import Chart from 'chart.js/auto'")
        ->toContain('window.Chart = Chart');
});

test('the Alpine mask plugin comes bundled with Livewire instead of a separate dependency', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);
    $declared = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

    expect($declared)->not->toHaveKey('@alpinejs/mask')
        ->and(file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.esm.js')))
        ->toContain('directive("mask"');
});
