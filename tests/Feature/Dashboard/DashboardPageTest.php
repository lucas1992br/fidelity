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
            '1 de 2 titulares',
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

    $this->withoutVite()->get(route('dashboard'))->assertOk()->assertSee('Receita x despesa · últimos 12 meses');
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    Livewire::test('pages::dashboard')->assertForbidden();
    $this->get(route('dashboard'))->assertForbidden();
});
