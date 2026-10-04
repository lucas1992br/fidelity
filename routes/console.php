<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Billing routines
|--------------------------------------------------------------------------
|
| Daily, in order: flag overdue invoices, suspend delinquent cards, then
| generate the next month's invoices (only on the configured day).
|
*/

Schedule::command('invoices:mark-overdue')
    ->dailyAt('00:10')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('cards:suspend-delinquent')
    ->dailyAt('00:20')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('invoices:generate')
    ->dailyAt('01:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();
