<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RegisterPayment;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('the overdue status filter lists only overdue invoices with the days late', function () {
    actingAsRole('financeiro');
    $late = Customer::factory()->withCard()->create(['name' => 'Maria Atrasada']);
    Invoice::factory()->for($late)->overdue(5)->create();
    Invoice::factory()->for(Customer::factory()->withCard()->create(['name' => 'José Em Dia']))->forMonth('2026-10')->paid()->create();
    Invoice::factory()->for(Customer::factory()->withCard()->create(['name' => 'Ana Aberta']))->forMonth('2026-10')->open()->create();

    Livewire::test('pages::billing.index')
        ->set('status', 'overdue')
        ->assertSee('Maria Atrasada')
        ->assertSee('Em atraso · 5 dias')
        ->assertDontSee('José Em Dia')
        ->assertDontSee('Ana Aberta');
});

test('the filters are read from the query string', function () {
    actingAsRole('financeiro');
    $plan = Plan::factory()->create();
    $maria = Customer::factory()->for($plan)->withCard()->create(['name' => 'Maria Conceição']);
    Invoice::factory()->for($maria)->overdue(20)->create();
    Invoice::factory()->for(Customer::factory()->for($plan)->withCard()->create(['name' => 'José Ribeiro']))->overdue(20)->create();

    Livewire::withQueryParams([
        'status' => 'overdue',
        'due_from' => '2026-09-01',
        'due_until' => '2026-09-30',
        'plan' => (string) $plan->id,
        'q' => 'Maria',
    ])
        ->test('pages::billing.index')
        ->assertSet('status', 'overdue')
        ->assertSet('month', '')
        ->assertSet('dueFrom', '2026-09-01')
        ->assertSet('dueUntil', '2026-09-30')
        ->assertSet('plan', (string) $plan->id)
        ->assertSet('search', 'Maria')
        ->assertSee('Maria Conceição')
        ->assertDontSee('José Ribeiro');
});

test('the page route renders the invoices screen with the filters in the URL', function () {
    actingAsRole('financeiro');
    Invoice::factory()->for(Customer::factory()->withCard()->create(['name' => 'Maria Atrasada']))->overdue(5)->create();

    $this->get(route('billing.index', ['status' => 'overdue', 'month' => '2026-10']))
        ->assertOk()
        ->assertSeeLivewire('pages::billing.index')
        ->assertSee('Maria Atrasada');
});

test('picking a reference month clears the due date interval', function () {
    actingAsRole('financeiro');

    Livewire::withQueryParams(['due_from' => '2026-09-01'])
        ->test('pages::billing.index')
        ->set('month', '2026-08')
        ->assertSet('dueFrom', '')
        ->assertSet('month', '2026-08');
});

test('the paid invoice offers the receipt and the payable one the payment', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    $payable = Invoice::factory()->for($customer)->forMonth('2026-10')->create();
    $paid = Invoice::factory()->for(Customer::factory()->withCard())->forMonth('2026-10')->create();
    $payment = app(RegisterPayment::class)->handle($paid, [
        'paid_at' => '2026-10-15',
        'amount' => $paid->amount,
        'payment_method' => PaymentMethod::Pix->value,
    ], User::factory()->create());

    Livewire::test('pages::billing.index')
        ->assertSeeHtml("\$dispatch('open-payment', { invoice: {$payable->id} })")
        ->assertSeeHtml(route('receipts.show', $payment))
        ->assertDontSeeHtml("\$dispatch('open-payment', { invoice: {$paid->id} })");
});

test('the atendente gets 403', function () {
    actingAsRole('atendente');

    $this->get(route('billing.index'))->assertForbidden();
    Livewire::test('pages::billing.index')->assertForbidden();
});

test('the screen lists the company bill in place of its employees and sends its payment to the company sheet', function () {
    actingAsRole('financeiro');
    $company = Company::factory()->create(['name' => 'Metalúrgica Souza LTDA', 'monthly_amount' => '1200.00', 'due_day' => 20]);
    foreach (['Funcionário Um', 'Funcionário Dois'] as $name) {
        app(IssueInvoice::class)->handle(Customer::factory()->forCompany($company)->withCard()->create(['name' => $name]), today());
    }

    Livewire::test('pages::billing.index')
        ->assertSee('Metalúrgica Souza LTDA')
        ->assertSee('Fatura da empresa · 2 funcionários')
        ->assertSee('R$ 1.200,00')
        ->assertSee(route('companies.show', ['company' => $company->id, 'tab' => 'faturas']), escape: false)
        ->assertDontSee('Funcionário Um')
        ->assertDontSee('Funcionário Dois');
});
