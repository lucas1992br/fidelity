<?php

use App\Actions\Billing\RegisterPayment;
use App\Actions\Finance\SettleFinancialEntry;
use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo('2026-10-15 00:20');
    settings(['suspend_after_days' => 30]);
});

/**
 * Active current card whose holder has an overdue invoice late by the given days.
 */
function activeCardOverdueBy(int $days): Card
{
    $card = Card::factory()->active()->create();
    Invoice::factory()->overdue($days)->create(['customer_id' => $card->customer_id]);

    return $card;
}

test('the limit is inclusive: 30 days late suspends and 29 does not', function () {
    $late30 = activeCardOverdueBy(30);
    $late29 = activeCardOverdueBy(29);

    $this->artisan('cards:suspend-delinquent')
        ->expectsOutputToContain('Cartões suspensos por inadimplência: 1')
        ->assertSuccessful();

    expect($late30->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($late30->is_current)->toBeTrue()
        ->and($late29->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($late29->statusLogs()->count())->toBe(0);
});

test('the status log carries the longest delay and no user', function () {
    $card = activeCardOverdueBy(32);
    Invoice::factory()->overdue(5)->create(['customer_id' => $card->customer_id]);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    $log = $card->refresh()->statusLogs()->sole();

    expect($log->from_card_status_id)->toBe(CardStatus::Active->id())
        ->and($log->to_card_status_id)->toBe(CardStatus::Suspended->id())
        ->and($log->reason)->toBe('Suspensão automática: 32 dias em atraso')
        ->and($log->user_id)->toBeNull();
});

test('an open invoice past the limit that is not yet flagged overdue does not suspend', function () {
    $card = Card::factory()->active()->create();
    Invoice::factory()->create(['customer_id' => $card->customer_id, 'due_date' => '2026-09-01']);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('suspended or cancelled cards are not changed', function (CardStatus $status) {
    $card = Card::factory()->status($status)->create();
    Invoice::factory()->overdue(45)->create(['customer_id' => $card->customer_id]);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    expect($card->refresh()->hasStatus($status))->toBeTrue()
        ->and($card->statusLogs()->count())->toBe(0);
})->with([
    'suspended' => [CardStatus::Suspended],
    'cancelled' => [CardStatus::Cancelled],
]);

test('the activity log records how many cards were suspended', function () {
    activeCardOverdueBy(30);
    activeCardOverdueBy(40);
    activeCardOverdueBy(10);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    $activity = Activity::query()->where('log_name', 'routines')->sole();

    expect($activity->event)->toBe('cards:suspend-delinquent')
        ->and($activity->properties['count'])->toBe(2)
        ->and($activity->causer)->toBeNull();
});

test('with a one-day limit the nightly routines suspend the card the day after the due date', function () {
    settings(['suspend_after_days' => 1]);
    $dueYesterday = Card::factory()->active()->create();
    Invoice::factory()->create(['customer_id' => $dueYesterday->customer_id, 'due_date' => '2026-10-14']);
    $dueToday = Card::factory()->active()->create();
    Invoice::factory()->create(['customer_id' => $dueToday->customer_id, 'due_date' => '2026-10-15']);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();
    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    expect($dueYesterday->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($dueYesterday->statusLogs()->sole()->reason)->toBe('Suspensão automática: 1 dia em atraso')
        ->and($dueToday->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('with 3 days of tolerance the card is suspended only on the 4th day late', function () {
    settings(['suspend_after_days' => 4]);
    $fourDaysLate = activeCardOverdueBy(4);
    $threeDaysLate = activeCardOverdueBy(3);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    expect($fourDaysLate->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($threeDaysLate->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('a late boleto installment suspends the card and settling it reactivates the card', function () {
    settings(['suspend_after_days' => 4]);
    $card = Card::factory()->active()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $card->customer_id, 'amount' => '300.00']);
    $payment = app(RegisterPayment::class)->handle($invoice, [
        'paid_at' => '2026-09-01',
        'amount' => '300.00',
        'payment_method' => PaymentMethod::Boleto->value,
        'installments' => 3,
    ], User::factory()->create());
    [$september, $october] = $payment->financialEntries->all();
    app(SettleFinancialEntry::class)->handle($september, ['paid_at' => '2026-09-01', 'payment_method' => 'boleto']);

    $this->artisan('cards:suspend-delinquent')->assertSuccessful();

    expect($invoice->refresh()->hasStatus(InvoiceStatus::Paid))->toBeTrue()
        ->and($card->refresh()->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($card->statusLogs()->sole()->reason)->toBe('Suspensão automática: 14 dias em atraso');

    app(SettleFinancialEntry::class)->handle($october, ['paid_at' => '2026-10-15', 'payment_method' => 'boleto']);

    expect($card->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});
