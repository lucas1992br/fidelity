<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Brazilian CPF (spec §7): accepts the value with or without the mask, checks
 * the length, rejects repeated sequences and validates both check digits.
 */
class Cpf implements ValidationRule
{
    public const string MESSAGE = 'O CPF informado é inválido.';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * Keep only the digits ("123.456.789-09" → "12345678909").
     */
    public static function normalize(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }

    public static function isValid(string $value): bool
    {
        $cpf = self::normalize($value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cpf[$index] * (($position + 1) - $index);
            }

            if ((int) $cpf[$position] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }
}
