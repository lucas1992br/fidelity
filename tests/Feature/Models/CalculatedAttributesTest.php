<?php

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

test('dependent identification joins the holder current card number and the sequence', function () {
    $customer = Customer::factory()->create();
    Card::factory()->for($customer)->active()->create(['number' => '000123']);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 2]);

    expect($dependent->identification)->toBe('000123-02');
});

test('dependent identification follows a card replacement because it is not stored', function () {
    $customer = Customer::factory()->create();
    $oldCard = Card::factory()->for($customer)->active()->create(['number' => '000123']);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 2]);

    $oldCard->update(['card_status_id' => CardStatusEnum::Lost->id(), 'is_current' => false]);
    Card::factory()->for($customer)->active()->create(['number' => '000200']);

    expect($dependent->refresh()->identification)->toBe('000200-02')
        ->and($dependent->getAttributes())->not->toHaveKey('identification');
});

test('dependent identification falls back to the last card when none is current', function () {
    $customer = Customer::factory()->create();
    Card::factory()->for($customer)->lost()->create(['number' => '000050', 'assigned_at' => '2026-01-10']);
    Card::factory()->for($customer)->cancelled()->create(['number' => '000123', 'assigned_at' => '2026-05-10']);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 1]);

    expect($dependent->identification)->toBe('000123-01');
});

test('dependent identification is null while the holder never had a card', function () {
    expect(Dependent::factory()->create()->identification)->toBeNull();
});

test('days overdue counts the days since the due date', function () {
    Carbon::setTestNow('2026-10-04');

    $overdue = Invoice::factory()->overdue(32)->create();
    $openAndLate = Invoice::factory()->create(['due_date' => '2026-09-29']);

    expect($overdue->days_overdue)->toBe(32)
        ->and($openAndLate->days_overdue)->toBe(5);
});

test('days overdue is zero for invoices that are not late', function (Closure $makeInvoice) {
    Carbon::setTestNow('2026-10-04');

    expect($makeInvoice()->days_overdue)->toBe(0);
})->with([
    'paid after the due date' => fn () => Invoice::factory()->paid()->create(['due_date' => '2026-09-01']),
    'cancelled' => fn () => Invoice::factory()->cancelled()->create(['due_date' => '2026-09-01']),
    'open and not yet due' => fn () => Invoice::factory()->open()->create(),
]);

test('customer cpf is masked and formatted from the stored digits', function () {
    $customer = Customer::factory()->create(['cpf' => '12345678909']);

    expect($customer->masked_cpf)->toBe('***.456.789-**')
        ->and($customer->formatted_cpf)->toBe('123.456.789-09');
});
