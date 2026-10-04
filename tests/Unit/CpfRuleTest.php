<?php

use App\Rules\Cpf;

/**
 * Run the rule and return the failure message, or null when it passes.
 */
function cpfRuleFailure(mixed $value): ?string
{
    $message = null;

    (new Cpf)->validate('cpf', $value, function (string $failure) use (&$message): void {
        $message = $failure;
    });

    return $message;
}

test('a valid CPF passes with and without the mask', function (string $cpf) {
    expect(cpfRuleFailure($cpf))->toBeNull();
})->with([
    'digits only' => ['52998224725'],
    'masked' => ['529.982.247-25'],
]);

test('an invalid CPF fails with the spec message', function (string $cpf) {
    expect(cpfRuleFailure($cpf))->toBe('O CPF informado é inválido.');
})->with([
    'wrong check digit' => ['529.982.247-26'],
    'all zeros' => ['000.000.000-00'],
    'repeated ones' => ['111.111.111-11'],
    'ten digits' => ['5299822472'],
]);

test('normalize keeps only the digits', function () {
    expect(Cpf::normalize('529.982.247-25'))->toBe('52998224725');
});
