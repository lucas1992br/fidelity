<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

test('the list shows each company with its employees in force and finds it by CNPJ', function () {
    actingAsRole('financeiro');
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA', 'cnpj' => '11222333000181']);
    Customer::factory()->count(2)->forCompany($company)->withCard()->create(['monthly_amount' => '100.00']);
    Company::factory()->create(['name' => 'Padaria Central LTDA']);

    Livewire::test('pages::companies.index')
        ->set('search', '11.222.333')
        ->assertSee('Metalúrgica Souza LTDA')
        ->assertSee('R$ 200,00')
        ->assertDontSee('Padaria Central LTDA');
});

test('a new company created in the modal opens its sheet', function () {
    actingAsRole('financeiro');

    Livewire::test('companies.company-modal')
        ->call('open')
        ->set('name', 'Metalúrgica Souza LTDA')
        ->set('cnpj', '11.222.333/0001-81')
        ->set('phone', '(11) 3333-4444')
        ->set('due_day', 15)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('companies.show', Company::sole()));
});

test('the bills tab sums the employee invoices of each month', function () {
    actingAsRole('financeiro');
    $company = Company::factory()->create();
    foreach (['100.00', '50.00'] as $amount) {
        Invoice::factory()->for(Customer::factory()->forCompany($company)->withCard())->forMonth('2026-10')->create(['amount' => $amount]);
    }

    Livewire::test('pages::companies.show', ['company' => $company])
        ->set('tab', 'faturas')
        ->assertSee('10/2026')
        ->assertSee('R$ 150,00');
});

test('paying a bill on the sheet pays every employee invoice of the month with one receipt', function () {
    $this->travelTo('2026-10-05');
    actingAsRole('financeiro');
    $company = Company::factory()->create();
    $invoices = collect(['100.00', '50.00'])->map(fn (string $amount): Invoice => Invoice::factory()
        ->for(Customer::factory()->forCompany($company)->withCard())
        ->forMonth('2026-10')
        ->create(['amount' => $amount]));

    Livewire::test('pages::companies.show', ['company' => $company])
        ->set('tab', 'faturas')
        ->call('openPayment', '2026-10-01')
        ->assertSet('amount', '150,00')
        ->call('confirmPayment')
        ->assertHasNoErrors()
        ->assertSet('payingMonth', null);

    expect(Payment::sole()->company_id)->toBe($company->id)
        ->and($invoices->every(fn (Invoice $invoice): bool => $invoice->refresh()->hasStatus(InvoiceStatus::Paid)))->toBeTrue();
});

test('the employee sheet sends the payment of a company invoice to the company', function () {
    actingAsRole('financeiro');
    $employee = Customer::factory()->forCompany(Company::factory()->create())->withCard()->create();
    Invoice::factory()->for($employee)->open()->create();

    Livewire::test('pages::customers.show', ['customer' => $employee])
        ->assertSee('Funcionário de')
        ->assertDontSee('Registrar pagamento');
});

test('the sale opened from a company preselects it with its due day', function () {
    actingAsRole('atendente');
    $company = Company::factory()->create(['due_day' => 20]);

    Livewire::withQueryParams(['empresa' => $company->id])
        ->test('pages::sales.create')
        ->assertSet('customer.company_id', $company->id)
        ->assertSet('customer.due_day', 20);
});

test('choosing a plan in the sale suggests its price as the monthly amount', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->create(['price' => '1234.50']);

    Livewire::test('pages::sales.create')
        ->set('customer.plan_id', $plan->id)
        ->assertSet('customer.monthly_amount', '1.234,50');
});
