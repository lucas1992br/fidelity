<?php

use App\Settings\ClinicSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

test('guest layout centers the slot under the brand with the clinic name from settings', function () {
    $settings = app(ClinicSettings::class);
    $settings->clinic_name = 'Clínica São Lucas';
    $settings->save();

    $this->view('layouts.guest', ['slot' => new HtmlString('<form id="login"></form>')])
        ->assertSeeInOrder(['M3.22 12H9.5', 'Clínica São Lucas', 'Cartão fidelidade', '<form id="login">'], false);
});

test('a forbidden request renders the pt-BR 403 page with a link to the home page', function () {
    Route::get('/_forbidden', fn () => abort(403))->middleware('web');

    $this->get('/_forbidden')
        ->assertForbidden()
        ->assertSee('Você não tem permissão para acessar esta página')
        ->assertSee('href="'.route('home').'"', false)
        ->assertSee('Ir para a página inicial');
});

test('placeholder page shows the under construction block with a way back', function () {
    Livewire::test('pages::placeholder')
        ->assertSee('Esta tela ainda não foi desenhada')
        ->assertSee('Voltar ao painel')
        ->assertSeeHtml('href="'.route('home').'"');
});
