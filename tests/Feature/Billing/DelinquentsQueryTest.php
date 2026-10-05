<?php

use App\Actions\Cards\CancelCard;
use App\Enums\CardStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Queries\DelinquentsQuery;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
});

test('a holder with two overdue invoices shows the count, the sum and the longest delay', function () {
    $customer = Customer::factory()->withCard(CardStatus::Suspended)->create(['phone' => '11987654321']);
    Invoice::factory()->for($customer)->overdue(55)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->overdue(24)->create(['amount' => '89.90']);
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create(['amount' => '89.90']);

    $rows = app(DelinquentsQuery::class)->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->id)->toBe($customer->id)
        ->and($row->overdue_invoices_count)->toBe(2)
        ->and($row->overdue_amount)->toBe('179.80')
        ->and($row->max_days_overdue)->toBe(55)
        ->and($row->oldest_due_date->toDateString())->toBe('2026-08-21')
        ->and($row->card_status_id)->toBe(CardStatus::Suspended->id());

    expect(app(DelinquentsQuery::class)->summary())->toBe(['holders' => 1, 'amount' => '179.80']);
});

test('holders are ordered by the longest delay', function () {
    $recent = Customer::factory()->withCard()->create(['name' => 'Ana Recente']);
    Invoice::factory()->for($recent)->overdue(3)->create();
    $oldest = Customer::factory()->withCard()->create(['name' => 'Bruno Antigo']);
    Invoice::factory()->for($oldest)->overdue(90)->create();
    $middle = Customer::factory()->withCard()->create(['name' => 'Carla Meio']);
    Invoice::factory()->for($middle)->overdue(30)->create();

    expect(app(DelinquentsQuery::class)->get()->pluck('id')->all())->toBe([$oldest->id, $middle->id, $recent->id])
        ->and(app(DelinquentsQuery::class)->get(limit: 2)->pluck('id')->all())->toBe([$oldest->id, $middle->id]);
});

test('a holder with only open invoices is not delinquent', function () {
    $customer = Customer::factory()->withCard()->create();
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create();
    Invoice::factory()->for($customer)->forMonth('2026-09')->paid()->create();

    expect(app(DelinquentsQuery::class)->get())->toBeEmpty()
        ->and(app(DelinquentsQuery::class)->holdersCount())->toBe(0)
        ->and(app(DelinquentsQuery::class)->summary())->toBe(['holders' => 0, 'amount' => '0.00']);
});

test('a holder whose card was cancelled with the invoices kept is still delinquent', function () {
    $customer = Customer::factory()->withCard()->create();
    $invoice = Invoice::factory()->for($customer)->overdue(40)->create();

    app(CancelCard::class)->handle($customer->currentCard, 'Inadimplência', CancelCard::KeepPendingInvoices, User::factory()->create());

    $row = app(DelinquentsQuery::class)->get()->sole();

    expect($row->id)->toBe($customer->id)
        ->and($row->overdue_amount)->toBe($invoice->amount)
        ->and($row->card_status_id)->toBe(CardStatus::Cancelled->id());
});

test('the listing loads every holder in a single query', function () {
    Customer::factory()->withCard()->count(3)->create()->each(
        fn (Customer $customer) => Invoice::factory()->for($customer)->overdue(10)->create(),
    );

    DB::enableQueryLog();
    $rows = app(DelinquentsQuery::class)->get();
    $rows->each(fn (Customer $row) => [$row->name, $row->phone, $row->card_status_id, $row->max_days_overdue]);

    expect($rows)->toHaveCount(3)
        ->and(DB::getQueryLog())->toHaveCount(1);
});
