<?php

use App\Actions\Cards\CancelCard;
use App\Actions\Cards\ReenrollCustomer;
use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-10-04 09:00:00');
});

/**
 * Holder whose card 000123 was cancelled, on the "Individual" plan due on the 10th.
 */
function cancelledHolder(array $attributes = []): Customer
{
    $plan = Plan::factory()->create(['name' => 'Individual', 'price' => '49.90', 'max_dependents' => 0]);
    $customer = Customer::factory()->for($plan)->create(['due_day' => 10, ...$attributes]);
    Card::factory()->cancelled()->for($customer)->create(['number' => '000123']);

    return $customer;
}

test('a cancelled holder returns with an active card, the "Reingresso" plan change and a new invoice', function () {
    $user = actingAsRole('financeiro');
    $customer = cancelledHolder();
    $family = Plan::factory()->create(['name' => 'Familiar', 'price' => '89.90', 'max_dependents' => 4]);
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => ' 000456 ',
        'plan_id' => $family->id,
        'due_day' => 15,
    ], $user);

    $customer->refresh();
    $card = $customer->currentCard;
    $planChange = $customer->planChanges()->sole();
    $invoice = $customer->invoices()->sole();

    expect($customer->plan_id)->toBe($family->id)
        ->and($customer->due_day)->toBe(15)
        ->and($card->number)->toBe('000456')
        ->and($card->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($card->assigned_at->isToday())->toBeTrue()
        ->and($card->statusLogs()->sole()->user_id)->toBe($user->id)
        ->and($planChange->reason)->toBe('Reingresso')
        ->and($planChange->fromPlan->name)->toBe('Individual')
        ->and($planChange->from_price)->toBe('49.90')
        ->and($planChange->to_plan_id)->toBe($family->id)
        ->and($planChange->to_price)->toBe('89.90')
        ->and($planChange->user_id)->toBe($user->id)
        ->and($invoice->amount)->toBe('89.90')
        ->and($invoice->plan_id)->toBe($family->id)
        ->and($invoice->hasStatus(InvoiceStatus::Open))->toBeTrue()
        ->and($invoice->reference_month->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-15')
        ->and(Card::where('number', '000123')->sole()->hasStatus(CardStatus::Cancelled))->toBeTrue();
});

test('a holder with a current card cannot re-enroll', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    expect(fn () => app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $customer->plan_id,
        'due_day' => 10,
    ], $user))->toThrow(ValidationException::class, 'Só titulares sem cartão vigente e com o último cartão cancelado podem reingressar.');

    expect(Card::where('number', '000456')->sole()->hasStatus(CardStatus::InStock))->toBeTrue()
        ->and($customer->planChanges()->count())->toBe(0);
});

test('a holder whose last card was lost cannot re-enroll', function () {
    $customer = Customer::factory()->create();
    Card::factory()->lost()->for($customer)->create();

    expect(ReenrollCustomer::canReenroll($customer))->toBeFalse()
        ->and(ReenrollCustomer::canReenroll(cancelledHolder()))->toBeTrue();
});

test('a plan below the active dependents blocks telling how many to inactivate', function () {
    $user = actingAsRole('financeiro');
    $customer = cancelledHolder();
    Dependent::factory()->count(3)->for($customer)->create();
    Dependent::factory()->inactive()->for($customer)->create();
    $couple = Plan::factory()->create(['name' => 'Casal', 'max_dependents' => 1]);
    Card::factory()->inStock()->create(['number' => '000456']);

    expect(fn () => app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $couple->id,
        'due_day' => 10,
    ], $user))->toThrow(ValidationException::class, 'O plano Casal permite até 1 dependentes; inative 2 dependente(s) antes do reingresso.');

    expect($customer->refresh()->currentCard)->toBeNull()
        ->and($customer->plan_id)->not->toBe($couple->id)
        ->and(Card::where('number', '000456')->sole()->hasStatus(CardStatus::InStock))->toBeTrue();
});

test('an inactive plan or a card out of stock is rejected', function (string $case) {
    $user = actingAsRole('financeiro');
    $customer = cancelledHolder();
    $plan = $case === 'inactive plan' ? Plan::factory()->inactive()->create() : $customer->plan;
    Card::factory()->status($case === 'inactive plan' ? CardStatus::InStock : CardStatus::Lost)->create(['number' => '000456']);

    expect(fn () => app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $plan->id,
        'due_day' => 10,
    ], $user))->toThrow(ValidationException::class);

    expect($customer->refresh()->currentCard)->toBeNull()
        ->and($customer->invoices()->count())->toBe(0);
})->with(['inactive plan', 'card out of stock']);

test('an invoice kept at the cancellation is still overdue after the re-enrollment', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create(['due_day' => 10]);
    $card = Card::factory()->active()->for($customer)->create();
    $kept = Invoice::factory()->for($customer)->forMonth('2026-08')->create([
        'invoice_status_id' => InvoiceStatus::Overdue->id(),
        'due_date' => '2026-08-10',
    ]);
    app(CancelCard::class)->handle($card, 'Inadimplência', CancelCard::KeepPendingInvoices, $user);
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $customer->plan_id,
        'due_day' => 10,
    ], $user);

    expect($kept->refresh()->hasStatus(InvoiceStatus::Overdue))->toBeTrue()
        ->and($customer->invoices()->count())->toBe(2);
});

test('an already billed month moves the new invoice to the following month', function () {
    $user = actingAsRole('financeiro');
    $customer = cancelledHolder();
    Invoice::factory()->for($customer)->forMonth('2026-10')->cancelled()->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $customer->plan_id,
        'due_day' => 10,
    ], $user);

    $invoice = $customer->invoices()->latest('id')->first();

    expect($customer->invoices()->count())->toBe(2)
        ->and($invoice->reference_month->toDateString())->toBe('2026-11-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-11-10')
        ->and($invoice->hasStatus(InvoiceStatus::Open))->toBeTrue();
});

test('dependents keep their sequence and use the new card number', function () {
    $user = actingAsRole('financeiro');
    $customer = cancelledHolder();
    $customer->plan->update(['max_dependents' => 2]);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 3]);
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReenrollCustomer::class)->handle($customer, [
        'card_number' => '000456',
        'plan_id' => $customer->plan_id,
        'due_day' => 10,
    ], $user);

    $dependent = Dependent::find($dependent->id);

    expect($dependent->sequence)->toBe(3)
        ->and($dependent->identification)->toBe('000456-03');
});
