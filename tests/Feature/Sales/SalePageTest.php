<?php

use App\Enums\CardStatus;
use App\Enums\Kinship;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The sale page with a valid holder, the card number and the plan filled in.
 */
function salePage(Plan $plan, string $cardNumber = '000123'): Testable
{
    return Livewire::test('pages::sales.create')
        ->set('cardNumber', $cardNumber)
        ->fill([
            'customer.name' => 'Maria Souza',
            'customer.cpf' => '529.982.247-25',
            'customer.birth_date' => '1985-06-15',
            'customer.phone' => '(11) 98765-4321',
            'customer.zip_code' => '01310-100',
            'customer.street' => 'Avenida Paulista',
            'customer.number' => '1000',
            'customer.district' => 'Bela Vista',
            'customer.city' => 'São Paulo',
            'customer.state' => 'SP',
        ])
        ->set('customer.plan_id', $plan->id);
}

function addSaleDependent(Testable $page, string $name): Testable
{
    return $page
        ->set('dependent.name', $name)
        ->set('dependent.birth_date', '2015-03-10')
        ->set('dependent.kinship_id', Kinship::Child->id())
        ->call('addDependent');
}

test('the atendente completes a sale and is redirected to the quick search with the card number', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(4)->create(['price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    $page = salePage($plan)->assertSee('Disponível');
    addSaleDependent($page, 'Ana Souza')->assertHasNoErrors();

    $page->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('quick-search', ['q' => '000123']));

    $customer = Customer::where('cpf', '52998224725')->sole();

    expect($customer->currentCard->number)->toBe('000123')
        ->and($customer->dependents()->count())->toBe(1)
        ->and($customer->invoices()->count())->toBe(1)
        ->and(session('notify.title'))->toBe('Venda concluída');
});

test('the sale route renders the page for the atendente without invoice values', function () {
    actingAsRole('atendente');
    Plan::factory()->create(['name' => 'Individual']);

    $this->withoutVite()->get(route('sales.create'))
        ->assertOk()
        ->assertSee('Concluir venda')
        ->assertSee('Individual')
        ->assertDontSee('Mensalidade');
});

test('the card check shows the state without exposing the other customer to the atendente', function () {
    actingAsRole('atendente');
    $owner = Customer::factory()->create(['name' => 'Joana Lima']);
    Card::factory()->active()->for($owner)->create(['number' => '000999']);

    Livewire::test('pages::sales.create')
        ->set('cardNumber', '000999')
        ->assertSee('Já vinculado')
        ->assertDontSee('Joana Lima')
        ->set('cardNumber', '123456')
        ->assertSee('Não cadastrado no estoque');
});

test('a card that left the stock between typing and submitting is rejected on the server', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->create();
    $card = Card::factory()->inStock()->create(['number' => '000123']);

    $page = salePage($plan)->assertSee('Disponível');

    $card->update(['card_status_id' => CardStatus::Discarded->id()]);

    $page->call('save')
        ->assertHasErrors('cardNumber')
        ->assertNoRedirect();

    expect(Customer::where('cpf', '52998224725')->exists())->toBeFalse()
        ->and(Invoice::count())->toBe(0);
});

test('switching from Familiar to Individual with 2 dependents shows the error and does not save', function () {
    actingAsRole('atendente');
    $familiar = Plan::factory()->withDependents(4)->create(['name' => 'Familiar']);
    $individual = Plan::factory()->create(['name' => 'Individual', 'max_dependents' => 0]);
    Card::factory()->inStock()->create(['number' => '000123']);

    $page = salePage($familiar);
    addSaleDependent($page, 'Ana Souza');
    addSaleDependent($page, 'Pedro Souza')->assertCount('dependents', 2);

    $page->set('customer.plan_id', $individual->id)
        ->assertHasErrors('dependents')
        ->assertSee('O plano Individual não inclui dependentes. Troque de plano para incluir.')
        ->call('save')
        ->assertHasErrors('dependents')
        ->assertNoRedirect();

    expect(Customer::where('cpf', '52998224725')->exists())->toBeFalse()
        ->and(Card::where('number', '000123')->sole()->hasStatus(CardStatus::InStock))->toBeTrue();
});

test('the add dependent button is disabled once the plan limit is reached', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->withDependents(1)->create(['name' => 'Duo']);

    $page = salePage($plan)->assertSet('canAddDependent', true);
    addSaleDependent($page, 'Ana Souza')->assertSet('canAddDependent', false);

    addSaleDependent($page, 'Pedro Souza')
        ->assertHasErrors('dependents')
        ->assertCount('dependents', 1);
});

test('a user without sales.create gets 403 on save', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    $page = salePage($plan);

    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user);

    $page->call('save')->assertForbidden();

    expect(Customer::where('cpf', '52998224725')->exists())->toBeFalse();
});

test('a user without sales.create gets 403 on mount', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));

    Livewire::test('pages::sales.create')->assertForbidden();

    $this->withoutVite()->get(route('sales.create'))->assertForbidden();
});

test('the sale saves the typed monthly amount', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->create(['price' => '89.90']);
    Card::factory()->inStock()->create(['number' => '000123']);

    salePage($plan)
        ->set('customer.monthly_amount', '65,00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::sole()->monthly_amount)->toBe('65.00');
});

test('choosing the plan prefills its enrollment fee and the typed fee is charged', function () {
    actingAsRole('atendente');
    $plan = Plan::factory()->withEnrollmentFee('150.00')->create();
    Card::factory()->inStock()->create(['number' => '000123']);

    salePage($plan)
        ->assertSet('customer.enrollment_fee', '150,00')
        ->set('customer.enrollment_fee', '1.050,00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::sole()->planChanges()->sole()->enrollment_fee)->toBe('1050.00');
});
