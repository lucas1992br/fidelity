<?php

use Barryvdh\DomPDF\Facade\Pdf;

test('the approved backend package configs are published', function (string $config) {
    expect(config_path("{$config}.php"))->toBeFile()
        ->and(config($config))->toBeArray()->not->toBeEmpty();
})->with(['fortify', 'permission', 'activitylog', 'settings', 'excel', 'dompdf']);

test('dompdf renders on A4 paper with a font that supports accents', function () {
    expect(config('dompdf.options.default_paper_size'))->toBe('a4')
        ->and(config('dompdf.options.default_font'))->toBe('DejaVu Sans');
});

test('a PDF with accented text can be generated', function () {
    $pdf = Pdf::loadHTML('<p>Cartão Fidelidade — ação, emissão</p>');

    expect($pdf->output())->toStartWith('%PDF');
});
