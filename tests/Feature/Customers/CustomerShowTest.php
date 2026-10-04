<?php

use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Livewire\Livewire;

test('financeiro opens the sheet and sees the full CPF, while the listing keeps it masked', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create(['name' => 'Maria Conceição', 'cpf' => '12345678909', 'joined_at' => '2024-02-14']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);

    $this->withoutVite()
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('Maria Conceição')
        ->assertSee('123.456.789-09')
        ->assertSee('Titular desde 14/02/2024 · Cartão 000123')
        ->assertSee('Ativo');

    Livewire::test('pages::customers.index')
        ->assertSee('***.456.789-**')
        ->assertDontSee('123.456.789-09');
});

test('the sheet shows the tabs with the dependents count and keeps the active tab in the URL', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();

    Livewire::withQueryParams(['tab' => 'mensalidades'])
        ->test('pages::customers.show', ['customer' => $customer])
        ->assertSet('tab', 'mensalidades')
        ->assertSeeInOrder(['Resumo', 'Dependentes · 0', 'Mensalidades', 'Histórico', 'Observações'])
        ->set('tab', 'historico')
        ->assertSet('tab', 'historico');
});

test('atendente gets 403 on the sheet', function () {
    actingAsRole('atendente');
    $customer = Customer::factory()->withCard()->create();

    $this->get(route('customers.show', $customer))->assertForbidden();

    Livewire::test('pages::customers.show', ['customer' => $customer])->assertForbidden();
});

test('a soft-deleted holder returns 404', function () {
    actingAsRole('admin');
    $customer = Customer::factory()->withCard()->create();
    $customer->delete();

    $this->get(route('customers.show', $customer))->assertNotFound();
});

test('register payment shows for a payable invoice but not for a cancelled card', function () {
    actingAsRole('financeiro');
    $active = Customer::factory()->withCard()->create();
    Invoice::factory()->for($active)->overdue(5)->create();
    $cancelled = Customer::factory()->withCard(CardStatus::Cancelled)->create();
    Invoice::factory()->for($cancelled)->overdue(5)->create();

    Livewire::test('pages::customers.show', ['customer' => $active])->assertSee('Registrar pagamento');
    Livewire::test('pages::customers.show', ['customer' => $cancelled])->assertDontSee('Registrar pagamento');
});

test('header actions follow the permissions of the profile', function () {
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->open()->create();

    actingAsRole('financeiro');
    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSee('Segunda via')
        ->assertSee('Trocar plano')
        ->assertSee('Editar')
        ->assertSee('Exportar dados')
        ->assertSee('Registrar pagamento');
});

test('the summary shows the current card, the previous cards with the final status and the holder data', function () {
    $user = actingAsRole('financeiro');
    $plan = Plan::factory()->create(['name' => 'Familiar', 'price' => '89.90', 'max_dependents' => 4]);
    $customer = Customer::factory()->for($plan)->create(['due_day' => 10, 'phone' => '11987654321', 'birth_date' => '1979-07-22']);
    Card::factory()->active()->for($customer)->create(['number' => '000123', 'assigned_at' => '2025-06-03']);
    $old = Card::factory()->lost()->for($customer)->create(['number' => '000087', 'assigned_at' => '2024-02-14']);
    CardStatusLog::factory()->for($old)->transition(CardStatus::Active, CardStatus::Lost)->create([
        'reason' => 'Perda relatada pelo titular',
        'user_id' => $user->id,
        'created_at' => '2025-06-03 10:00:00',
    ]);
    Dependent::factory()->count(2)->for($customer)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSee('000123')
        ->assertSee('desde 03/06/2025')
        ->assertSee('Familiar · R$ 89,90')
        ->assertSee('Todo dia 10')
        ->assertSee('2 de 4')
        ->assertSee('Cartões anteriores')
        ->assertSee('Extraviado em 03/06/2025 · Perda relatada pelo titular')
        ->assertSee('Suspender')
        ->assertSee('Cancelar cartão')
        ->assertDontSee('Reativar')
        ->assertSee('22/07/1979')
        ->assertSee('(11) 98765-4321');
});

test('a holder whose last card was cancelled is offered the re-enrollment', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard(CardStatus::Cancelled)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSee('Reingressar')
        ->assertDontSee('Cancelar cartão')
        ->assertDontSee('Segunda via');
});

test('the dependents tab lists active and inactive dependents with the card identification', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Dependent::factory()->for($customer)->create(['name' => 'Lívia Souza', 'sequence' => 2, 'birth_date' => '2012-09-18']);
    Dependent::factory()->inactive()->for($customer)->create(['name' => 'Pedro Souza', 'sequence' => 3]);

    Livewire::withQueryParams(['tab' => 'dependentes'])
        ->test('pages::customers.show', ['customer' => $customer])
        ->assertSee('Dependentes · 2')
        ->assertSeeInOrder(['000123-02', 'Lívia Souza', '18/09/2012', 'Ativo', '000123-03', 'Pedro Souza', 'Inativo']);
});

test('the invoices tab lists the most recent first and shows the days overdue', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->forMonth('2026-08')->paid()->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-09')->create([
        'invoice_status_id' => InvoiceStatus::Overdue->id(),
        'due_date' => today()->subDays(12)->toDateString(),
    ]);
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create();

    Livewire::withQueryParams(['tab' => 'mensalidades'])
        ->test('pages::customers.show', ['customer' => $customer])
        ->assertSeeInOrder(['10/2026', 'Em aberto', '09/2026', 'Em atraso · 12 dias', '08/2026', 'R$ 89,90', 'Pago']);
});

test('the history tab shows the timeline with the responsible user', function () {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    CustomerNote::factory()->for($customer)->for(User::factory()->create(['name' => 'Paulo Mendes']))
        ->create(['body' => 'Prefere WhatsApp', 'created_at' => '2026-09-10 11:00:00']);

    Livewire::withQueryParams(['tab' => 'historico'])
        ->test('pages::customers.show', ['customer' => $customer])
        ->assertSeeInOrder(['10/09/2026', 'Observação', 'Prefere WhatsApp', 'por Paulo Mendes']);
});
