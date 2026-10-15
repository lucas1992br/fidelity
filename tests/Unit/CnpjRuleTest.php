<?php

use App\Rules\Cnpj;

/**
 * Run the rule and return the failure message, or null when it passes.
 */
function cnpjRuleFailure(mixed $value): ?string
{
    $message = null;

    (new Cnpj)->validate('cnpj', $value, function (string $failure) use (&$message): void {
        $message = $failure;
    });

    return $message;
}

test('a valid CNPJ passes with and without the mask', function (string $cnpj) {
    expect(cnpjRuleFailure($cnpj))->toBeNull();
})->with([
    'digits only' => ['11222333000181'],
    'masked' => ['11.222.333/0001-81'],
]);

test('an invalid CNPJ fails with its message', function (string $cnpj) {
    expect(cnpjRuleFailure($cnpj))->toBe('O CNPJ informado é inválido.');
})->with([
    'wrong first check digit' => ['11.222.333/0001-91'],
    'wrong second check digit' => ['11.222.333/0001-82'],
    'repeated digits' => ['11.111.111/1111-11'],
    'thirteen digits' => ['1122233300018'],
]);
