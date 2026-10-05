<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Queries\DelinquentsQuery;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('the menu shows the number of delinquent holders', function () {
    actingAsRole('financeiro');
    Customer::factory()->withCard()->count(3)->create()->each(function (Customer $customer): void {
        Invoice::factory()->for($customer)->overdue(10)->create();
        Invoice::factory()->for($customer)->overdue(40)->create();
    });
    Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-10')->open()->create();

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertSeeHtml('data-nav-badge="billing.delinquents">3</span>');
});

test('the badge is hidden when nobody is delinquent', function () {
    actingAsRole('financeiro');

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertSee('Inadimplentes')
        ->assertDontSeeHtml('data-nav-badge="billing.delinquents"');
});

test('the atendente does not trigger the query because the item is hidden', function () {
    actingAsRole('atendente');
    $this->mock(DelinquentsQuery::class)->shouldNotReceive('holdersCount');

    $this->get(route('quick-search'))
        ->assertOk()
        ->assertDontSee('Inadimplentes')
        ->assertDontSeeHtml('data-nav-badge');
});
