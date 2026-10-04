<?php

use App\Settings\ClinicSettings;
use Illuminate\Support\Facades\DB;

test('clinic settings start with the initial program parameters', function () {
    $settings = app(ClinicSettings::class);

    expect($settings->clinic_name)->toBe('Policlínica')
        ->and($settings->clinic_cnpj)->toBeNull()
        ->and($settings->clinic_address)->toBeNull()
        ->and($settings->clinic_phone)->toBeNull()
        ->and($settings->default_due_day)->toBe(10)
        ->and($settings->suspend_after_days)->toBe(30)
        ->and($settings->invoice_generation_day)->toBe(20)
        ->and($settings->min_card_stock)->toBe(50)
        ->and($settings->card_number_pattern)->toBeNull()
        ->and(DB::table('settings')->where('group', 'clinic')->count())->toBe(9);
});

test('changing a clinic setting is persisted immediately', function () {
    settings(['suspend_after_days' => 45]);

    expect(json_decode(DB::table('settings')->where('group', 'clinic')->where('name', 'suspend_after_days')->value('payload')))->toBe(45)
        ->and(app(ClinicSettings::class)->refresh()->suspend_after_days)->toBe(45);
});
