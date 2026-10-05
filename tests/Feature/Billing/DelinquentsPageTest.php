<?php

use App\Enums\CardStatus;
use App\Models\Customer;
use App\Models\Invoice;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('the page lists the delinquent holders with the summary and the WhatsApp link', function () {
    actingAsRole('financeiro');
    $maria = Customer::factory()->withCard(CardStatus::Suspended)->create(['name' => 'Maria Conceição', 'phone' => '11987654321']);
    Invoice::factory()->for($maria)->overdue(55)->create(['amount' => '89.90']);
    Invoice::factory()->for($maria)->overdue(24)->create(['amount' => '89.90']);
    Invoice::factory()->for(Customer::factory()->withCard()->create(['name' => 'José Ribeiro']))->overdue(10)->create(['amount' => '50.00']);
    Invoice::factory()->for(Customer::factory()->withCard()->create(['name' => 'Ana Em Dia']))->forMonth('2026-10')->open()->create();

    $this->get(route('billing.delinquents'))
        ->assertOk()
        ->assertSeeLivewire('pages::billing.delinquents')
        ->assertSee('2 titulares · R$ 229,80 em atraso')
        ->assertSeeInOrder(['Maria Conceição', 'José Ribeiro'])
        ->assertSee('55 dias')
        ->assertSee('R$ 179,80')
        ->assertSee('Suspenso')
        ->assertSeeHtml('href="https://wa.me/5511987654321" target="_blank"')
        ->assertSeeHtml('href="'.route('customers.show', $maria).'"')
        ->assertSeeHtml('href="'.route('billing.delinquents.pdf').'"')
        ->assertSeeHtml('href="'.route('billing.delinquents.excel').'"')
        ->assertDontSee('Ana Em Dia');
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    $this->get(route('billing.delinquents'))->assertForbidden();
    Livewire::test('pages::billing.delinquents')->assertForbidden();
});
