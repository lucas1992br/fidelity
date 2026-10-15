<?php

use App\Settings\ClinicSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

beforeEach(function () {
    $this->withoutVite();
});

test('guest layout centers the slot under the logo, described by the clinic name from settings', function () {
    $settings = app(ClinicSettings::class);
    $settings->clinic_name = 'Clínica São Lucas';
    $settings->save();

    $this->view('layouts.guest', ['slot' => new HtmlString('<form id="login"></form>')])
        ->assertSeeInOrder(['src="'.asset('images/logo.png').'" alt="Clínica São Lucas"', '<form id="login">'], false);
});

test('a forbidden request renders the pt-BR 403 page with a link to the home page', function () {
    Route::get('/_forbidden', fn () => abort(403))->middleware('web');

    $this->get('/_forbidden')
        ->assertForbidden()
        ->assertSee('Você não tem permissão para acessar esta página')
        ->assertSee('href="'.route('home').'"', false)
        ->assertSee('Ir para a página inicial');
});
