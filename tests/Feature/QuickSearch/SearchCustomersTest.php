<?php

use App\Actions\Customers\SearchCustomers;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Support\Customers\CustomerSearchResult;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, CustomerSearchResult>
 */
function searchCustomers(string $term): Collection
{
    return app(SearchCustomers::class)->handle($term);
}

test('"conceicao" finds "Maria Conceição" ignoring accents and case', function () {
    $maria = Customer::factory()->create(['name' => 'Maria Conceição']);
    Customer::factory()->create(['name' => 'José Ribeiro']);

    $results = searchCustomers('CONCEICAO');

    expect($results)->toHaveCount(1)
        ->and($results->first()->customer->is($maria))->toBeTrue()
        ->and($results->first()->dependent)->toBeNull()
        ->and($results->first()->replacedCardNumber)->toBeNull();
});

test('the name search returns at most 10 results with the result relations loaded', function () {
    Customer::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'Silva '.$sequence->index])->withCard()->create();

    $results = searchCustomers('silva');

    expect($results)->toHaveCount(SearchCustomers::Limit)
        ->and($results->first()->customer->relationLoaded('plan'))->toBeTrue()
        ->and($results->first()->customer->relationLoaded('currentCard'))->toBeTrue()
        ->and($results->first()->customer->relationLoaded('activeDependents'))->toBeTrue();
});

test('the CPF finds the holder with and without the mask', function (string $term) {
    $holder = Customer::factory()->create(['cpf' => '12345678909']);
    Customer::factory()->create();

    $results = searchCustomers($term);

    expect($results)->toHaveCount(1)
        ->and($results->first()->customer->is($holder))->toBeTrue();
})->with(['masked' => '123.456.789-09', 'digits only' => '12345678909']);

test('000123-02 finds the holder and highlights dependent 2', function () {
    $holder = Customer::factory()->create();
    Card::factory()->active()->for($holder)->create(['number' => '000123']);
    Dependent::factory()->for($holder)->create(['sequence' => 1, 'name' => 'Carlos']);
    $second = Dependent::factory()->for($holder)->create(['sequence' => 2, 'name' => 'Lívia']);

    $result = searchCustomers('000123-02')->sole();

    expect($result->customer->is($holder))->toBeTrue()
        ->and($result->dependent?->is($second))->toBeTrue()
        ->and($result->dependent->identification)->toBe('000123-02')
        ->and($result->replacedCardNumber)->toBeNull();
});

test('the current card number finds the holder without a warning', function () {
    $holder = Customer::factory()->create();
    Card::factory()->active()->for($holder)->create(['number' => '000150']);

    $result = searchCustomers('000150')->sole();

    expect($result->customer->is($holder))->toBeTrue()
        ->and($result->replacedCardNumber)->toBeNull()
        ->and($result->currentCardNumber())->toBe('000150');
});

test('a lost card number returns the holder with the warning and the current number', function () {
    $holder = Customer::factory()->create();
    Card::factory()->lost()->for($holder)->create(['number' => '000087']);
    Card::factory()->active()->for($holder)->create(['number' => '000123']);

    $result = searchCustomers('000087')->sole();

    expect($result->customer->is($holder))->toBeTrue()
        ->and($result->replacedCardNumber)->toBe('000087')
        ->and($result->currentCardNumber())->toBe('000123');
});

test('a cancelled card number finds the holder', function () {
    $holder = Customer::factory()->create();
    Card::factory()->cancelled()->for($holder)->create(['number' => '000163']);

    $result = searchCustomers('000163')->sole();

    expect($result->customer->is($holder))->toBeTrue()
        ->and($result->replacedCardNumber)->toBe('000163')
        ->and($result->currentCardNumber())->toBeNull();
});

test('an unknown term returns nothing', function (string $term) {
    Customer::factory()->withCard()->create(['name' => 'Maria Conceição']);
    Card::factory()->inStock()->create(['number' => '000999']);

    expect(searchCustomers($term))->toBeEmpty();
})->with(['name' => 'Inexistente', 'stock card' => '000999', 'cpf' => '111.111.111-11', 'blank' => '   ']);
