<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Every Livewire method that changes data, keyed by "area · component::method".
 * Each case mounts (and prepares) the component as admin and returns it with the
 * method and arguments to call; `financeiro` tells whether that profile may run it.
 *
 * @return array<string, array{financeiro: bool, build: Closure(): array{Testable, string, list<mixed>}}>
 */
function livewireMutations(): array
{
    $customerWithCard = fn (CardStatus $status = CardStatus::Active): Customer => Customer::factory()->withCard($status)->create();

    $mutations = [
        // Atendimento: venda
        'venda · sales.create::addDependent' => [true, fn () => [Livewire::test('pages::sales.create'), 'addDependent', []]],
        'venda · sales.create::removeDependent' => [true, fn () => [Livewire::test('pages::sales.create'), 'removeDependent', [0]]],
        'venda · sales.create::save' => [true, fn () => [Livewire::test('pages::sales.create'), 'save', []]],

        // Estoque de cartões: lote e inutilizar
        'lote · register-batch-modal::open' => [true, fn () => [Livewire::test('cards.register-batch-modal'), 'open', []]],
        'lote · register-batch-modal::previewBatch' => [true, fn () => [Livewire::test('cards.register-batch-modal'), 'previewBatch', []]],
        'lote · register-batch-modal::confirm' => [true, fn () => [Livewire::test('cards.register-batch-modal'), 'confirm', []]],
        'inutilizar · discard-card-modal::open' => [true, fn () => [Livewire::test('cards.discard-card-modal'), 'open', [Card::factory()->inStock()->create()->id]]],
        'inutilizar · discard-card-modal::confirm' => [true, fn () => [Livewire::test('cards.discard-card-modal')->call('open', Card::factory()->inStock()->create()->id), 'confirm', []]],

        // Ciclo de vida do cartão
        'segunda via · replace-card-modal::open' => [true, fn () => [Livewire::test('cards.replace-card-modal', ['customerId' => $customerWithCard()->id]), 'open', []]],
        'segunda via · replace-card-modal::confirm' => [true, fn () => [Livewire::test('cards.replace-card-modal', ['customerId' => $customerWithCard()->id]), 'confirm', []]],
        'suspender · card-status-modal::openSuspend' => [true, fn () => [Livewire::test('cards.card-status-modal', ['customerId' => $customerWithCard()->id]), 'openSuspend', []]],
        'suspender · card-status-modal::confirm' => [true, fn () => [Livewire::test('cards.card-status-modal', ['customerId' => $customerWithCard()->id])->call('openSuspend'), 'confirm', []]],
        'reativar · card-status-modal::openReactivate' => [true, fn () => [Livewire::test('cards.card-status-modal', ['customerId' => $customerWithCard(CardStatus::Suspended)->id]), 'openReactivate', []]],
        'reativar · card-status-modal::confirm' => [true, fn () => [Livewire::test('cards.card-status-modal', ['customerId' => $customerWithCard(CardStatus::Suspended)->id])->call('openReactivate'), 'confirm', []]],
        'cancelar · cancel-card-modal::open' => [true, fn () => [Livewire::test('cards.cancel-card-modal', ['customerId' => $customerWithCard()->id]), 'open', []]],
        'cancelar · cancel-card-modal::confirm' => [true, fn () => [Livewire::test('cards.cancel-card-modal', ['customerId' => $customerWithCard()->id])->call('open'), 'confirm', []]],
        'reingresso · reenroll-modal::open' => [true, fn () => [Livewire::test('cards.reenroll-modal', ['customerId' => $customerWithCard(CardStatus::Cancelled)->id]), 'open', []]],
        'reingresso · reenroll-modal::confirm' => [true, fn () => [Livewire::test('cards.reenroll-modal', ['customerId' => $customerWithCard(CardStatus::Cancelled)->id])->call('open'), 'confirm', []]],

        // Cliente: cadastro, dependentes, troca de plano e notas
        'cliente · customers.edit::save' => [true, fn () => [Livewire::test('pages::customers.edit', ['customer' => $customerWithCard()]), 'save', []]],
        'dependentes · dependent-modal::open' => [true, fn () => [Livewire::test('customers.dependent-modal', ['customerId' => $customerWithCard()->id]), 'open', []]],
        'dependentes · dependent-modal::save' => [true, fn () => [Livewire::test('customers.dependent-modal', ['customerId' => $customerWithCard()->id])->call('open'), 'save', []]],
        'dependentes · customers.show::deactivateDependent' => [true, function () {
            $dependent = Dependent::factory()->for($customer = Customer::factory()->withCard()->create())->create();

            return [Livewire::test('pages::customers.show', ['customer' => $customer]), 'deactivateDependent', [$dependent->id]];
        }],
        'troca de plano · change-plan-modal::open' => [true, fn () => [Livewire::test('customers.change-plan-modal', ['customerId' => $customerWithCard()->id]), 'open', []]],
        'troca de plano · change-plan-modal::confirm' => [true, fn () => [Livewire::test('customers.change-plan-modal', ['customerId' => $customerWithCard()->id])->call('open'), 'confirm', []]],
        'notas · customers.show::addNote' => [true, fn () => [Livewire::test('pages::customers.show', ['customer' => $customerWithCard()])->set('note', 'Observação'), 'addNote', []]],

        // Faturamento: pagamento e estorno
        'pagamento · payment-modal::open' => [true, fn () => [Livewire::test('billing.payment-modal'), 'open', [Invoice::factory()->open()->create()->id]]],
        'pagamento · payment-modal::confirm' => [true, fn () => [Livewire::test('billing.payment-modal')->call('open', Invoice::factory()->open()->create()->id), 'confirm', []]],
        'estorno · customers.show::openRefund' => [false, function () {
            $payment = Payment::factory()->create();

            return [Livewire::test('pages::customers.show', ['customer' => $payment->invoice->customer]), 'openRefund', [$payment->id]];
        }],
        'estorno · customers.show::refund' => [false, function () {
            $payment = Payment::factory()->create();

            return [Livewire::test('pages::customers.show', ['customer' => $payment->invoice->customer])->call('openRefund', $payment->id), 'refund', []];
        }],

        // Financeiro: lançamentos e categorias
        'lançamentos · entry-modal::open' => [true, fn () => [Livewire::test('finance.entry-modal'), 'open', []]],
        'lançamentos · entry-modal::save' => [true, fn () => [Livewire::test('finance.entry-modal')->call('open'), 'save', []]],
        'lançamentos · settle-modal::open' => [true, fn () => [Livewire::test('finance.settle-modal'), 'open', [FinancialEntry::factory()->pending()->create()->id]]],
        'lançamentos · settle-modal::confirm' => [true, fn () => [Livewire::test('finance.settle-modal')->call('open', FinancialEntry::factory()->pending()->create()->id), 'confirm', []]],
        'lançamentos · finance.entries::delete' => [true, fn () => [Livewire::test('pages::finance.entries'), 'delete', [FinancialEntry::factory()->pending()->create()->id]]],
        'categorias · finance.categories::create' => [true, fn () => [Livewire::test('pages::finance.categories'), 'create', []]],
        'categorias · finance.categories::edit' => [true, fn () => [Livewire::test('pages::finance.categories'), 'edit', [FinancialCategory::factory()->create()->id]]],
        'categorias · finance.categories::save' => [true, fn () => [Livewire::test('pages::finance.categories')->call('create'), 'save', []]],
        'categorias · finance.categories::toggleActive' => [true, fn () => [Livewire::test('pages::finance.categories'), 'toggleActive', [FinancialCategory::factory()->create()->id]]],
        'categorias · finance.categories::delete' => [true, fn () => [Livewire::test('pages::finance.categories'), 'delete', [FinancialCategory::factory()->create()->id]]],

        // Administração: planos, usuários e configurações
        'planos · plans::create' => [false, fn () => [Livewire::test('pages::plans'), 'create', []]],
        'planos · plans::edit' => [false, fn () => [Livewire::test('pages::plans'), 'edit', [Plan::factory()->create()->id]]],
        'planos · plans::save' => [false, fn () => [Livewire::test('pages::plans')->call('create'), 'save', []]],
        'planos · plans::delete' => [false, fn () => [Livewire::test('pages::plans')->call('edit', Plan::factory()->create()->id), 'delete', []]],
        'usuários · users.index::create' => [false, fn () => [Livewire::test('pages::users.index'), 'create', []]],
        'usuários · users.index::edit' => [false, fn () => [Livewire::test('pages::users.index'), 'edit', [User::factory()->create()->id]]],
        'usuários · users.index::save' => [false, fn () => [Livewire::test('pages::users.index')->call('create'), 'save', []]],
        'usuários · users.index::editPassword' => [false, fn () => [Livewire::test('pages::users.index'), 'editPassword', [User::factory()->create()->id]]],
        'usuários · users.index::resetPassword' => [false, fn () => [Livewire::test('pages::users.index')->call('editPassword', User::factory()->create()->id), 'resetPassword', []]],
        'usuários · users.index::toggleActive' => [false, fn () => [Livewire::test('pages::users.index'), 'toggleActive', [User::factory()->create()->id]]],
        'configurações · settings::save' => [false, fn () => [Livewire::test('pages::settings'), 'save', []]],
    ];

    return array_map(fn (array $mutation): array => ['financeiro' => $mutation[0], 'build' => $mutation[1]], $mutations);
}

/**
 * Mount and prepare the mutation as admin, then switch to the given profile and call it.
 */
function callMutationAs(string $mutation, string $role): Testable
{
    actingAsRole('admin');

    [$component, $method, $arguments] = (livewireMutations()[$mutation]['build'])();

    actingAsRole($role);

    return $component->call($method, ...$arguments);
}

/**
 * @return list<string>
 */
function mutationsWhere(Closure $filter): array
{
    return array_keys(array_filter(livewireMutations(), $filter, ARRAY_FILTER_USE_BOTH));
}

beforeEach(function () {
    $this->withoutVite();
});

test('atendente gets 403 on every data-changing method outside the sale', function (string $mutation) {
    callMutationAs($mutation, 'atendente')->assertForbidden();
})->with(fn (): array => mutationsWhere(fn (array $mutation, string $key): bool => ! str_starts_with($key, 'venda')));

test('atendente runs the methods of the sale', function (string $mutation) {
    callMutationAs($mutation, 'atendente')->assertOk();
})->with(fn (): array => mutationsWhere(fn (array $mutation, string $key): bool => str_starts_with($key, 'venda')));

test('financeiro gets 403 on plans, refund, settings and users', function (string $mutation) {
    callMutationAs($mutation, 'financeiro')->assertForbidden();
})->with(fn (): array => mutationsWhere(fn (array $mutation): bool => ! $mutation['financeiro']));

test('financeiro passes the authorization of the other data-changing methods', function (string $mutation) {
    callMutationAs($mutation, 'financeiro')->assertOk();
})->with(fn (): array => mutationsWhere(fn (array $mutation): bool => $mutation['financeiro']));

test('admin passes the authorization of the admin-only methods', function (string $mutation) {
    callMutationAs($mutation, 'admin')->assertOk();
})->with(fn (): array => mutationsWhere(fn (array $mutation): bool => ! $mutation['financeiro']));
