<?php

use App\Settings\ClinicSettings;
use Livewire\Livewire;

test('the settings page loads the current values', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->assertSet('clinic_name', 'Policlínica')
        ->assertSet('default_due_day', 10)
        ->assertSet('suspend_after_days', 1);
});

test('a due day of 29 is rejected', function (string $field) {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set($field, 29)
        ->call('save')
        ->assertHasErrors([$field => 'between']);

    expect(app(ClinicSettings::class)->refresh()->{$field})->not->toBe(29);
})->with(['default_due_day', 'invoice_generation_day']);

test('an invalid regular expression is rejected', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('card_number_pattern', '[a-')
        ->call('save')
        ->assertHasErrors('card_number_pattern');

    expect(app(ClinicSettings::class)->refresh()->card_number_pattern)->toBeNull();
});

test('a valid regular expression is saved', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('card_number_pattern', '/^\d{6}$/')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ClinicSettings::class)->refresh()->card_number_pattern)->toBe('/^\d{6}$/');
});

test('the lower bounds of the program parameters are enforced', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('suspend_after_days', 0)
        ->set('min_card_stock', -1)
        ->call('save')
        ->assertHasErrors(['suspend_after_days' => 'min', 'min_card_stock' => 'min']);
});

test('saved values apply immediately', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('suspend_after_days', 15)
        ->set('clinic_name', 'Clínica Bem Viver')
        ->set('clinic_cnpj', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    $settings = app(ClinicSettings::class);

    expect($settings->suspend_after_days)->toBe(15)
        ->and($settings->clinic_name)->toBe('Clínica Bem Viver')
        ->and($settings->clinic_cnpj)->toBeNull();

    $this->withoutVite()->get(route('settings'))->assertOk()->assertSee('Clínica Bem Viver');
});

test('financeiro gets 403 on mount and on save', function () {
    actingAsRole('financeiro');

    Livewire::test('pages::settings')->assertForbidden();
    $this->withoutVite()->get(route('settings'))->assertForbidden();

    actingAsRole('admin');
    $component = Livewire::test('pages::settings')->set('suspend_after_days', 99);

    actingAsRole('financeiro');
    $component->call('save')->assertForbidden();

    expect(app(ClinicSettings::class)->refresh()->suspend_after_days)->toBe(1);
});

test('the enrollment commission percentage is saved', function () {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('enrollment_commission_percent', '12.5')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ClinicSettings::class)->refresh()->enrollment_commission_percent)->toBe(12.5);
});

test('an enrollment commission percentage outside 0 to 100 is rejected', function (string $percent) {
    actingAsRole('admin');

    Livewire::test('pages::settings')
        ->set('enrollment_commission_percent', $percent)
        ->call('save')
        ->assertHasErrors(['enrollment_commission_percent' => 'between']);

    expect(app(ClinicSettings::class)->refresh()->enrollment_commission_percent)->toBe(0.0);
})->with(['above 100' => '100.01', 'negative' => '-1']);
