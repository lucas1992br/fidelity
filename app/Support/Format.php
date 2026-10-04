<?php

namespace App\Support;

use Illuminate\Support\Str;

class Format
{
    /**
     * Format a monetary value as Brazilian Real, e.g. "R$ 1.234,56".
     */
    public static function money(float|int|string $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    /**
     * Mask a CPF for listings, e.g. "***.456.789-**".
     */
    public static function maskCpf(string $cpf): string
    {
        $digits = preg_replace('/\D/', '', $cpf);

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }

    /**
     * Format an 11-digit CPF as "123.456.789-09".
     */
    public static function cpf(string $cpf): string
    {
        $digits = preg_replace('/\D/', '', $cpf);

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    /**
     * Two-letter initials from a full name, skipping short particles ("da", "dos").
     */
    public static function initials(string $name): string
    {
        $initials = Str::of($name)
            ->explode(' ')
            ->filter(fn (string $word): bool => mb_strlen($word) > 2)
            ->take(2)
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');

        return mb_strtoupper($initials);
    }
}
