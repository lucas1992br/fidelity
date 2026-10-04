<?php

use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Livewire\Livewire;

/**
 * Holder "Maria Aparecida Souza" on the Familiar plan with card 000123 in
 * the given status, one open and two overdue invoices.
 */
function quickSearchHolder(string $cardState = 'active'): Customer
{
    $plan = Plan::factory()->create(['name' => 'Familiar', 'price' => '89.90']);
    $customer = Customer::factory()->for($plan)->create(['name' => 'Maria Aparecida Souza', 'cpf' => '12345678909']);
    Card::factory()->{$cardState}()->for($customer)->create(['number' => '000123']);
    Invoice::factory()->for($customer)->open()->create(['due_date' => today()->addDays(6), 'amount' => '89.90']);
    Invoice::factory()->for($customer)->overdue(40)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->overdue(10)->create(['amount' => '89.90']);

    return $customer;
}

test('the atendente sees a restricted result without values, invoices or the full CPF', function () {
    actingAsRole('atendente');
    quickSearchHolder();

    Livewire::test('pages::quick-search')
        ->set('query', 'Maria')
        ->assertSee('Maria Aparecida Souza')
        ->assertSee('Familiar')
        ->assertSee('***.456.789-**')
        ->assertDontSee('123.456.789-09')
        ->assertDontSee('R$')
        ->assertDontSee('Próxima mensalidade')
        ->assertDontSee('Pendências')
        ->assertDontSee('Registrar pagamento');
});

test('the atendente sees only the forwarding notice for a suspended holder', function () {
    actingAsRole('atendente');
    quickSearchHolder('suspended');

    Livewire::test('pages::quick-search')
        ->set('query', '000123')
        ->assertSee('Suspenso')
        ->assertSee('Cartão suspenso — encaminhar ao financeiro')
        ->assertDontSee('em atraso');
});

test('the atendente does not see the open sheet button', function () {
    actingAsRole('atendente');
    $customer = quickSearchHolder();

    Livewire::test('pages::quick-search')
        ->set('query', '000123')
        ->assertSee('Maria Aparecida Souza')
        ->assertDontSee('Abrir ficha')
        ->assertDontSee(route('customers.show', $customer));
});

test('the financeiro sees the price, next invoice, overdue count and total and the payment button', function () {
    actingAsRole('financeiro');
    $customer = quickSearchHolder();
    $oldestOverdue = $customer->invoices()->orderBy('due_date')->first();

    Livewire::test('pages::quick-search')
        ->set('query', '123.456.789-09')
        ->assertSee('123.456.789-09')
        ->assertSee('Familiar · R$ 89,90')
        ->assertSee('Próxima mensalidade')
        ->assertSee(today()->addDays(6)->format('d/m/Y').' · R$ 89,90')
        ->assertSee('2 em atraso · R$ 179,80')
        ->assertSee('Abrir ficha')
        ->assertSee('Registrar pagamento')
        ->assertSeeHtml("\$dispatch('open-payment', { invoice: {$oldestOverdue->id} })");
});

test('the payment button is hidden without a payable invoice or with a cancelled card', function () {
    actingAsRole('financeiro');
    Customer::factory()->withCard()->create(['name' => 'Ana Clara Ferreira']);
    $cancelled = Customer::factory()->create(['name' => 'Juliana Rocha']);
    Card::factory()->cancelled()->for($cancelled)->create();
    Invoice::factory()->for($cancelled)->overdue(5)->create();

    Livewire::test('pages::quick-search')
        ->set('query', 'Ana Clara')
        ->assertSee('Nenhuma pendência')
        ->assertDontSee('Registrar pagamento')
        ->set('query', 'Juliana')
        ->assertSee('Cancelado')
        ->assertDontSee('Registrar pagamento');
});

test('searching a replaced card shows the replacement notice', function () {
    actingAsRole('atendente');
    $customer = Customer::factory()->create(['name' => 'Maria Aparecida Souza']);
    Card::factory()->lost()->for($customer)->create(['number' => '000087']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);

    Livewire::test('pages::quick-search')
        ->set('query', '000087')
        ->assertSee('O cartão 000087 foi substituído. Cartão atual: 000123.')
        ->assertSee('Ativo');
});

test('a dependent identification highlights the dependent found', function () {
    actingAsRole('atendente');
    $customer = Customer::factory()->create(['name' => 'Maria Aparecida Souza']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Dependent::factory()->for($customer)->create(['sequence' => 1, 'name' => 'Carlos Souza']);
    Dependent::factory()->for($customer)->create(['sequence' => 2, 'name' => 'Lívia Souza']);

    Livewire::test('pages::quick-search')
        ->set('query', '000123-02')
        ->assertSee('Dependente encontrado: Lívia Souza')
        ->assertSee('000123-01')
        ->assertSee('000123-02');
});

test('arriving with ?q= shows the result right away', function () {
    actingAsRole('atendente');
    quickSearchHolder();

    Livewire::withQueryParams(['q' => '000123'])
        ->test('pages::quick-search')
        ->assertSet('query', '000123')
        ->assertSee('Maria Aparecida Souza');
});

test('an unknown term shows the empty state and the demo chips are gone', function () {
    actingAsRole('atendente');

    Livewire::test('pages::quick-search')
        ->assertDontSee('Experimente')
        ->set('query', 'Inexistente')
        ->assertSee('Nenhum cliente encontrado');
});

test('a user without cards.check gets 403', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));

    $this->get(route('quick-search'))->assertForbidden();
    Livewire::test('pages::quick-search')->assertForbidden();
});
