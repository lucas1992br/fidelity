<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class Format
{
    /**
     * Format a monetary value as Brazilian Real, e.g. "R$ 1.234,56".
     *
     * Decimal strings (Eloquent `decimal:2` casts) are formatted digit by digit,
     * so the amount never goes through a float.
     */
    public static function money(float|int|string $value): string
    {
        $decimal = is_float($value) ? number_format($value, 2, '.', '') : trim((string) $value);
        $negative = str_starts_with($decimal, '-');

        [$integer, $cents] = array_pad(explode('.', ltrim($decimal, '+-'), 2), 2, '');

        $integer = ltrim(preg_replace('/\D/', '', $integer), '0') ?: '0';
        $cents = substr(str_pad(preg_replace('/\D/', '', $cents), 2, '0'), 0, 2);
        $thousands = strrev(implode('.', str_split(strrev($integer), 3)));

        return 'R$ '.($negative ? '-' : '').$thousands.','.$cents;
    }

    /**
     * Spell out a monetary value in Portuguese for receipts, e.g. "oitenta e
     * nove reais e noventa centavos". Like money(), it never uses a float.
     */
    public static function moneyInWords(float|int|string $value): string
    {
        $decimal = is_float($value) ? number_format($value, 2, '.', '') : ltrim(trim((string) $value), '+');

        [$integer, $cents] = array_pad(explode('.', $decimal, 2), 2, '');

        $reais = (int) (ltrim(preg_replace('/\D/', '', $integer), '0') ?: '0');
        $centavos = (int) substr(str_pad(preg_replace('/\D/', '', $cents), 2, '0'), 0, 2);

        $parts = [];

        if ($reais > 0 || $centavos === 0) {
            $connector = $reais >= 1_000_000 && $reais % 1_000_000 === 0 ? ' de ' : ' ';
            $parts[] = Number::spell($reais, 'pt_BR').$connector.($reais === 1 ? 'real' : 'reais');
        }

        if ($centavos > 0) {
            $parts[] = Number::spell($centavos, 'pt_BR').' '.($centavos === 1 ? 'centavo' : 'centavos');
        }

        return implode(' e ', $parts);
    }

    /**
     * Format a date as "d/m/Y", or an em dash when there is none.
     */
    public static function date(?CarbonInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '—';
    }

    /**
     * Format a Brazilian phone: "(11) 98765-4321" (mobile) or "(11) 3456-7890" (landline).
     */
    public static function phone(string $digits): string
    {
        $digits = self::digits($digits);

        return match (strlen($digits)) {
            11 => '('.substr($digits, 0, 2).') '.substr($digits, 2, 5).'-'.substr($digits, 7),
            10 => '('.substr($digits, 0, 2).') '.substr($digits, 2, 4).'-'.substr($digits, 6),
            default => $digits,
        };
    }

    /**
     * WhatsApp link for a Brazilian phone, e.g. "https://wa.me/5511987654321".
     */
    public static function whatsappUrl(string $phone): string
    {
        return 'https://wa.me/55'.self::digits($phone);
    }

    /**
     * Format a CEP as "01234-567".
     */
    public static function zip(string $zip): string
    {
        $digits = self::digits($zip);

        return strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : $digits;
    }

    /**
     * Mask a CPF for listings, e.g. "***.456.789-**".
     */
    public static function maskCpf(string $cpf): string
    {
        $digits = self::digits($cpf);

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }

    /**
     * Format an 11-digit CPF as "123.456.789-09".
     */
    public static function cpf(string $cpf): string
    {
        $digits = self::digits($cpf);

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    /**
     * Format a 14-digit CNPJ as "12.345.678/0001-95".
     */
    public static function cnpj(string $cnpj): string
    {
        $digits = self::digits($cnpj);

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
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

    /**
     * Keep only the digits of a value.
     */
    private static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
