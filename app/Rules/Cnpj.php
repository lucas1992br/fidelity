<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Brazilian CNPJ: accepts the value with or without the mask, checks the
 * length, rejects repeated sequences and validates both check digits.
 */
class Cnpj implements ValidationRule
{
    public const string MESSAGE = 'O CNPJ informado é inválido.';

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
     * Keep only the digits ("12.345.678/0001-95" → "12345678000195").
     */
    public static function normalize(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }

    public static function isValid(string $value): bool
    {
        $cnpj = self::normalize($value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $position) {
            $sum = 0;
            $weight = $position - 7;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cnpj[$index] * $weight--;

                if ($weight < 2) {
                    $weight = 9;
                }
            }

            $remainder = $sum % 11;

            if ((int) $cnpj[$position] !== ($remainder < 2 ? 0 : 11 - $remainder)) {
                return false;
            }
        }

        return true;
    }
}
