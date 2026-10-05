<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-15 10:00');

    $this->customer = Customer::factory()->withCard(CardStatus::Suspended)->create([
        'name' => 'Maria Conceição',
        'cpf' => '52998224725',
    ]);
    Invoice::factory()->for($this->customer)->forMonth('2026-10')->open()->create();
    Invoice::factory()->for($this->customer)->overdue(40)->create();
});

test('listings show the CPF masked, never the full number', function (string $role, Closure $uri) {
    actingAsRole($role);

    $this->get($uri())
        ->assertOk()
        ->assertSee('Maria Conceição')
        ->assertDontSee('529.982.247-25')
        ->assertDontSee('52998224725');
})->with([
    'clientes' => ['financeiro', fn (): string => route('customers.index')],
    'mensalidades' => ['financeiro', fn (): string => route('billing.index')],
    'inadimplentes' => ['financeiro', fn (): string => route('billing.delinquents')],
    'consulta do atendente' => ['atendente', fn (): string => route('quick-search', ['q' => 'Maria'])],
]);

test('the customer and attendant listings show the masked CPF', function (string $role, Closure $uri) {
    actingAsRole($role);

    $this->get($uri())->assertSee('***.982.247-**');
})->with([
    'clientes' => ['financeiro', fn (): string => route('customers.index')],
    'consulta do atendente' => ['atendente', fn (): string => route('quick-search', ['q' => 'Maria'])],
]);

test('the customer sheet shows the full CPF', function () {
    actingAsRole('financeiro');

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee('529.982.247-25');
});

test('registering and refunding a payment are logged with the user as causer', function () {
    $financeiro = actingAsRole('financeiro');
    $invoice = $this->customer->invoices()->orderBy('due_date')->first();

    Livewire::test('billing.payment-modal')
        ->call('open', $invoice->id)
        ->call('confirm')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();
    $paid = Activity::query()->whereMorphedTo('subject', $payment)->where('event', 'created')->sole();

    expect($paid->log_name)->toBe('billing')
        ->and($paid->causer->is($financeiro))->toBeTrue();

    $admin = actingAsRole('admin');

    Livewire::test('pages::customers.show', ['customer' => $this->customer])
        ->call('openRefund', $payment->id)
        ->set('refundReason', 'Pagamento em duplicidade')
        ->call('refund')
        ->assertHasNoErrors();

    $refunded = Activity::query()->whereMorphedTo('subject', $payment)->where('event', 'refunded')->sole();

    expect($refunded->log_name)->toBe('billing')
        ->and($refunded->causer->is($admin))->toBeTrue();
});

test('the audited models log creation and changes with the user as causer', function (Closure $makeSubject, array $changes) {
    $user = actingAsRole('admin');

    $subject = $makeSubject();
    $subject->update($changes);

    $activities = $subject->activitiesAsSubject()->whereIn('event', ['created', 'updated'])->get();

    expect($activities->pluck('event')->sort()->values()->all())->toBe(['created', 'updated'])
        ->and($activities->every(fn (Activity $activity): bool => $activity->causer_type === $user->getMorphClass() && (int) $activity->causer_id === $user->id))->toBeTrue();
})->with([
    'customer' => [fn () => Customer::factory()->create(), ['phone' => '11900000001']],
    'dependent' => [fn () => Dependent::factory()->create(), ['name' => 'Nome Alterado']],
    'plan' => [fn () => Plan::factory()->create(), ['description' => 'Descrição alterada']],
    'card' => [fn () => Card::factory()->inStock()->create(), ['number' => '999001']],
    'invoice' => [fn () => Invoice::factory()->open()->create(), ['amount' => '12.34']],
    'payment' => [fn () => Payment::factory()->create(), ['notes' => 'Observação alterada']],
    'financial entry' => [fn () => FinancialEntry::factory()->pending()->create(), ['description' => 'Descrição alterada']],
]);
