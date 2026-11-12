<?php

use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('the dashboard shows the calculated indicators formatted in reais', function () {
    actingAsRole('financeiro');
    settings(['min_card_stock' => 5]);
    Card::factory()->inStock()->count(2)->create();

    $maria = Customer::factory()->withCard()->create(['name' => 'Maria Conceição']);
    Invoice::factory()->for($maria)->overdue(42)->create(['amount' => '1234.56']);
    Customer::factory()->withCard()->create();

    $paid = Invoice::factory()->for($maria)->forMonth('2026-10')->paid()->create(['amount' => '89.90']);
    $payment = Payment::factory()->for($paid)->method(PaymentMethod::Cash)->create(['paid_at' => '2026-10-12']);
    FinancialEntry::factory()->automatic($payment)->create();
    FinancialEntry::factory()->expense()->paid()->create(['due_date' => '2026-10-05', 'paid_at' => '2026-10-05', 'amount' => '2500.00']);

    Livewire::test('pages::dashboard')
        ->assertSee([
            'Abaixo do mínimo de 5',
            'R$ 89,90',
            'R$ 2.500,00',
            'R$ 1.234,56',
            '50,0%',
            '1 de 2 titulares e empresas',
            'Maria Conceição',
            '42 dias',
            '#'.$payment->refresh()->receipt_number,
            '10/2026',
            'Dinheiro',
            '12/10/2026',
        ])
        ->assertSeeHtml(route('customers.show', $maria))
        ->assertSeeHtml(route('billing.delinquents'))
        ->assertSeeHtml('x-data="barChart"');
});

test('the dashboard route renders the page for the financeiro', function () {
    actingAsRole('financeiro');

    $this->withoutVite()->get(route('dashboard'))->assertOk()->assertSee('Receita x despesa · 12 meses até 10/2026');
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    Livewire::test('pages::dashboard')->assertForbidden();
    $this->get(route('dashboard'))->assertForbidden();
});

test('the month filter loads the month cards and payments of the chosen month', function () {
    actingAsRole('financeiro');
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-08-10', 'paid_at' => '2026-08-10', 'amount' => '4321.00']);
    FinancialEntry::factory()->income()->paid()->create(['due_date' => '2026-10-10', 'paid_at' => '2026-10-10', 'amount' => '777.00']);
    $august = Payment::factory()->create(['paid_at' => '2026-08-12']);
    $october = Payment::factory()->create(['paid_at' => '2026-10-12']);

    Livewire::withQueryParams(['mes' => '2026-08'])
        ->test('pages::dashboard')
        ->assertSet('month', '2026-08')
        ->assertSee(['Agosto de 2026', 'R$ 4.321,00', '#'.$august->refresh()->receipt_number, 'Receita x despesa · 12 meses até 08/2026', 'Mês atual'])
        ->assertDontSee(['R$ 777,00', '#'.$october->refresh()->receipt_number])
        ->call('nextMonth')
        ->assertSet('month', '2026-09')
        ->call('currentMonth')
        ->assertSet('month', '2026-10')
        ->assertSee('R$ 777,00')
        ->assertDontSee('Mês atual');
});

test('a future or invalid month falls back to the current month', function (string $month) {
    actingAsRole('financeiro');

    Livewire::withQueryParams(['mes' => $month])
        ->test('pages::dashboard')
        ->assertSet('month', '2026-10')
        ->call('nextMonth')
        ->assertSet('month', '2026-10');
})->with(['future' => '2026-11', 'invalid' => '2026-13']);
