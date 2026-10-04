<?php

use App\Actions\Cards\AssignCard;
use App\Actions\Cards\ReplaceCard;
use App\Actions\Customers\SearchCustomers;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use Illuminate\Validation\ValidationException;

test('a suspended holder gets a suspended new card and the old one becomes lost', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create();
    $old = Card::factory()->suspended()->for($customer)->create(['number' => '000123']);
    Card::factory()->inStock()->create(['number' => '000456']);

    $new = app(ReplaceCard::class)->handle($customer, ' 000456 ', 'lost', $user);

    $old->refresh();
    $new->refresh();
    $oldLog = $old->statusLogs()->sole();
    $newLog = $new->statusLogs()->sole();

    expect($old->hasStatus(CardStatus::Lost))->toBeTrue()
        ->and($old->is_current)->toBeFalse()
        ->and($old->customer_id)->toBe($customer->id)
        ->and($oldLog->reason)->toBe('Segunda via: substituído por 000456')
        ->and($oldLog->user_id)->toBe($user->id)
        ->and($new->number)->toBe('000456')
        ->and($new->hasStatus(CardStatus::Suspended))->toBeTrue()
        ->and($new->is_current)->toBeTrue()
        ->and($new->customer_id)->toBe($customer->id)
        ->and($new->assigned_at->isToday())->toBeTrue()
        ->and($newLog->from_card_status_id)->toBe(CardStatus::InStock->id())
        ->and($newLog->reason)->toBe('Segunda via do cartão 000123')
        ->and($newLog->user_id)->toBe($user->id)
        ->and($customer->refresh()->currentCard->is($new))->toBeTrue();
});

test('an active holder replacing a damaged card keeps the new card active', function () {
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    $new = app(ReplaceCard::class)->handle($customer, '000456', 'discarded');

    expect($old->refresh()->hasStatus(CardStatus::Discarded))->toBeTrue()
        ->and($new->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
});

test('a new card out of stock is rejected without touching the old card', function (CardStatus $status, string $message) {
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create();
    $other = Card::factory()->status($status)->create(['number' => '000456']);

    expect(fn () => app(ReplaceCard::class)->handle($customer, '000456', 'lost'))
        ->toThrow(ValidationException::class, $message);

    expect($old->refresh()->hasStatus(CardStatus::Active))->toBeTrue()
        ->and($old->is_current)->toBeTrue()
        ->and($old->statusLogs()->count())->toBe(0)
        ->and($other->refresh()->hasStatus($status))->toBeTrue();
})->with([
    'linked' => [CardStatus::Active, 'Já vinculado'],
    'lost' => [CardStatus::Lost, 'Extraviado/Inutilizado'],
]);

test('an unknown new card or an invalid reason is rejected', function (string $number, string $reason) {
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    expect(fn () => app(ReplaceCard::class)->handle($customer, $number, $reason))
        ->toThrow(ValidationException::class);

    expect($old->refresh()->hasStatus(CardStatus::Active))->toBeTrue();
})->with([
    'unknown card' => ['999999', 'lost'],
    'invalid reason' => ['000456', 'cancelled'],
]);

test('a holder without a current card cannot get a second copy', function () {
    $customer = Customer::factory()->withCard(CardStatus::Cancelled)->create();
    Card::factory()->inStock()->create(['number' => '000456']);

    expect(fn () => app(ReplaceCard::class)->handle($customer, '000456', 'lost'))
        ->toThrow(ValidationException::class, 'O titular não possui cartão vigente para substituir.');
});

test('the dependents identification uses the new number', function () {
    $customer = Customer::factory()->create();
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    $dependent = Dependent::factory()->for($customer)->create(['sequence' => 2]);
    Card::factory()->inStock()->create(['number' => '000456']);

    expect($dependent->identification)->toBe('000123-02');

    app(ReplaceCard::class)->handle($customer, '000456', 'lost');

    expect(Dependent::find($dependent->id)->identification)->toBe('000456-02');
});

test('searching the old number finds the holder with the replacement warning', function () {
    $customer = Customer::factory()->create();
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReplaceCard::class)->handle($customer, '000456', 'lost');

    $result = app(SearchCustomers::class)->handle('000123')->sole();

    expect($result->customer->is($customer))->toBeTrue()
        ->and($result->replacedCardNumber)->toBe('000123');
});

test('the old card can never be linked again', function () {
    $customer = Customer::factory()->create();
    $old = Card::factory()->active()->for($customer)->create(['number' => '000123']);
    Card::factory()->inStock()->create(['number' => '000456']);

    app(ReplaceCard::class)->handle($customer, '000456', 'lost');

    expect(fn () => app(AssignCard::class)->handle(Customer::factory()->create(), '000123'))
        ->toThrow(ValidationException::class, 'Extraviado/Inutilizado');

    expect(fn () => app(ReplaceCard::class)->handle($customer, '000123', 'discarded'))
        ->toThrow(ValidationException::class, 'Extraviado/Inutilizado');

    expect($old->refresh()->hasStatus(CardStatus::Lost))->toBeTrue()
        ->and($old->customer_id)->toBe($customer->id);
});
