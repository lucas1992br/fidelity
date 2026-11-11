<?php

use App\Http\Controllers\DelinquentsExportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Home page: atendente goes to the quick search, other profiles to the dashboard.
    Route::get('/', HomeController::class)->name('home');
    Route::livewire('/painel', 'pages::dashboard')->middleware('can:dashboard.view')->name('dashboard');

    // --- Atendimento (atendente, financeiro, admin) ---
    Route::livewire('/consulta', 'pages::quick-search')->middleware('can:cards.check')->name('quick-search');
    Route::livewire('/vendas/nova', 'pages::sales.create')->middleware('can:sales.create')->name('sales.create');

    // --- Gestão de clientes ---
    Route::middleware('can:customers.view')->group(function () {
        Route::livewire('/clientes', 'pages::customers.index')->name('customers.index');
        Route::livewire('/clientes/{customer}', 'pages::customers.show')->name('customers.show');
        Route::livewire('/empresas', 'pages::companies.index')->name('companies.index');
        Route::livewire('/empresas/{company}', 'pages::companies.show')->name('companies.show');
    });
    Route::livewire('/clientes/{customer}/editar', 'pages::customers.edit')->middleware('can:customers.update')->name('customers.edit');

    Route::livewire('/planos', 'pages::plans')->middleware('can:plans.manage')->name('plans.index');
    Route::livewire('/cartoes', 'pages::cards.stock')->middleware('can:cards.stock.view')->name('cards.stock');

    // --- Faturamento ---
    Route::middleware('can:billing.view')->group(function () {
        Route::livewire('/faturamento', 'pages::billing.index')->name('billing.index');
        Route::livewire('/faturamento/inadimplentes', 'pages::billing.delinquents')->name('billing.delinquents');
        Route::get('/faturamento/inadimplentes/pdf', [DelinquentsExportController::class, 'pdf'])->name('billing.delinquents.pdf');
        Route::get('/faturamento/inadimplentes/excel', [DelinquentsExportController::class, 'excel'])->name('billing.delinquents.excel');
        Route::get('/recibos/{payment}', ReceiptController::class)->name('receipts.show');
    });

    // --- Financeiro ---
    Route::prefix('financeiro')->name('finance.')->middleware('can:finance.view')->group(function () {
        Route::livewire('/lancamentos', 'pages::finance.entries')->name('entries');
        Route::livewire('/contas-a-pagar', 'pages::finance.payables')->name('payables');
        Route::livewire('/contas-a-receber', 'pages::finance.receivables')->name('receivables');
        Route::livewire('/fluxo-de-caixa', 'pages::finance.cash-flow')->name('cash-flow');
        Route::livewire('/categorias', 'pages::finance.categories')->name('categories');
        Route::livewire('/conciliacao-cartao', 'pages::finance.card-reconciliation')->middleware('can:billing.pay')->name('card-reconciliation');
        Route::livewire('/taxas-da-maquininha', 'pages::finance.card-fees')->middleware('can:finance.manage')->name('card-fees');
    });

    Route::livewire('/relatorios', 'pages::reports')->middleware('can:reports.view')->name('reports.index');
    Route::livewire('/configuracoes', 'pages::settings')->middleware('can:settings.manage')->name('settings');
    Route::livewire('/usuarios', 'pages::users.index')->middleware('can:users.manage')->name('users.index');
});
