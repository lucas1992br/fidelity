<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
| Screens that are not built yet point at `pages::placeholder`; each phase
| swaps the destination when it builds the screen.
*/
Route::middleware('auth')->group(function () {
    // Home page: atendente goes to the quick search, other profiles to the dashboard.
    Route::get('/', HomeController::class)->name('home');
    Route::livewire('/painel', 'pages::dashboard')->middleware('can:dashboard.view')->name('dashboard');

    // --- Atendimento (atendente, financeiro, admin) ---
    Route::livewire('/consulta', 'pages::quick-search')->middleware('can:cards.check')->name('quick-search');
    Route::livewire('/vendas/nova', 'pages::placeholder')->middleware('can:sales.create')->name('sales.create');

    // --- Gestão de clientes ---
    Route::middleware('can:customers.view')->group(function () {
        Route::livewire('/clientes', 'pages::placeholder')->name('customers.index');
        Route::livewire('/clientes/{customer}', 'pages::placeholder')->name('customers.show');
    });
    Route::livewire('/clientes/{customer}/editar', 'pages::placeholder')->middleware('can:customers.update')->name('customers.edit');

    Route::livewire('/planos', 'pages::placeholder')->middleware('can:plans.manage')->name('plans.index');
    Route::livewire('/cartoes', 'pages::placeholder')->middleware('can:cards.stock.view')->name('cards.stock');

    // --- Faturamento ---
    Route::middleware('can:billing.view')->group(function () {
        Route::livewire('/faturamento', 'pages::placeholder')->name('billing.index');
        Route::livewire('/faturamento/inadimplentes', 'pages::placeholder')->name('billing.delinquents');
        Route::livewire('/recibos/{payment}', 'pages::placeholder')->name('receipts.show');
    });

    // --- Financeiro ---
    Route::prefix('financeiro')->name('finance.')->middleware('can:finance.view')->group(function () {
        Route::livewire('/lancamentos', 'pages::placeholder')->name('entries');
        Route::livewire('/contas-a-pagar', 'pages::placeholder')->name('payables');
        Route::livewire('/contas-a-receber', 'pages::placeholder')->name('receivables');
        Route::livewire('/fluxo-de-caixa', 'pages::placeholder')->name('cash-flow');
        Route::livewire('/categorias', 'pages::placeholder')->name('categories');
    });

    Route::livewire('/relatorios', 'pages::placeholder')->middleware('can:reports.view')->name('reports.index');
    Route::livewire('/configuracoes', 'pages::placeholder')->middleware('can:settings.manage')->name('settings');
    Route::livewire('/usuarios', 'pages::placeholder')->middleware('can:users.manage')->name('users.index');
});
