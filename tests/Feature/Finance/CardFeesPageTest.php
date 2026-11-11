<?php

use App\Models\CardFee;
use App\Settings\ClinicSettings;
use Livewire\Livewire;

/**
 * Rate of a modality as stored.
 */
function cardFeeRate(bool $isDebit, int $installments): string
{
    return CardFee::query()->where('is_debit', $isDebit)->where('installments', $installments)->value('rate');
}

test('the page lists debit, credit at once and credit 2x to 12x and saves the typed rates and the settlement days', function () {
    actingAsRole('financeiro');
    $debit = CardFee::query()->where('is_debit', true)->sole();
    $credit12 = CardFee::query()->where('is_debit', false)->where('installments', 12)->sole();

    Livewire::test('pages::finance.card-fees')
        ->assertSeeInOrder(['Débito (%)', 'Crédito à vista (%)', 'Crédito 2x (%)', 'Crédito 12x (%)'])
        ->set("rates.{$debit->id}", '1,39')
        ->set("rates.{$credit12->id}", '12,5')
        ->set('settlementDays', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect(cardFeeRate(true, 1))->toBe('1.39')
        ->and(cardFeeRate(false, 12))->toBe('12.50')
        ->and(cardFeeRate(false, 1))->toBe('0.00')
        ->and(app(ClinicSettings::class)->refresh()->card_settlement_days)->toBe(2);
});

test('a rate above 100 or with more than 2 decimals is rejected', function (string $rate, string $message) {
    actingAsRole('financeiro');
    $debit = CardFee::query()->where('is_debit', true)->sole();

    Livewire::test('pages::finance.card-fees')
        ->set("rates.{$debit->id}", $rate)
        ->call('save')
        ->assertHasErrors(["rates.{$debit->id}"])
        ->assertSee($message);

    expect(cardFeeRate(true, 1))->toBe('0.00');
})->with([
    'above 100' => ['100,01', 'A taxa deve ser de no máximo 100%.'],
    '3 decimals' => ['1,999', 'Use até 2 casas decimais, ex.: 2,99.'],
]);

test('the atendente gets 403 on the page', function () {
    actingAsRole('atendente');

    Livewire::test('pages::finance.card-fees')->assertForbidden();
    $this->get(route('finance.card-fees'))->assertForbidden();
});
