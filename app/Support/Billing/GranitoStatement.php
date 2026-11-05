<?php

namespace App\Support\Billing;

use App\Actions\Plans\SavePlan;
use App\Models\PaymentCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reader of the Granito sales statement exported by the bank: a `;`
 * separated text (UTF-8 with BOM or Windows-1252, CRLF or LF) whose header
 * names the columns, e.g.
 * `ID Granito;Data e Hora;Pdv;Tipo;Bandeira;Cartão;...;Valor;Status`.
 * Columns are found by name, in any order. Amounts come in reais ("145",
 * "89,90" or "1.234,56"), the card as first 6 and last 4 digits
 * ("498407-9593") and the installments in the type ("Crédito 5X").
 * Unreadable lines are reported by line number and skipped.
 */
class GranitoStatement
{
    /**
     * Column keys and the header names (accents and case ignored) that hold them.
     *
     * @var array<string, list<string>>
     */
    protected const array COLUMNS = [
        'external_id' => ['id granito', 'id'],
        'occurred_at' => ['data e hora', 'data'],
        'type' => ['tipo'],
        'brand' => ['bandeira'],
        'card' => ['cartao'],
        'authorization_code' => ['cod. autorizacao', 'codigo de autorizacao'],
        'transaction_code' => ['cod. transacao', 'codigo da transacao'],
        'amount' => ['valor'],
        'status' => ['status'],
    ];

    /**
     * Columns without which a line cannot be reconciled.
     *
     * @var list<string>
     */
    protected const array REQUIRED = ['external_id', 'occurred_at', 'type', 'card', 'amount', 'status'];

    /**
     * Transactions read from the file.
     *
     * @var list<array{external_id: string, occurred_at: CarbonImmutable, type: string, installments: int, brand: string|null, card_bin: string, card_last4: string, authorization_code: string|null, transaction_code: string|null, amount: string, status: string, is_approved: bool}>
     */
    public array $transactions = [];

    /**
     * Lines that could not be read, e.g. "Linha 4: valor inválido.".
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @throws ValidationException When the header lacks a required column.
     */
    public static function parse(string $contents): self
    {
        $statement = new self;
        $lines = preg_split('/\r\n|\r|\n/', self::toUtf8($contents)) ?: [];
        $columns = self::columns(str_getcsv((string) array_shift($lines), ';', '"', ''));

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $lineNumber = $index + 2;
            $values = str_getcsv($line, ';', '"', '');
            $row = array_map(fn (int $position): string => trim((string) ($values[$position] ?? '')), $columns);

            try {
                $statement->transactions[] = self::transaction($row);
            } catch (\InvalidArgumentException $exception) {
                $statement->errors[] = "Linha {$lineNumber}: {$exception->getMessage()}";
            }
        }

        return $statement;
    }

    /**
     * Whether the acquirer approved the transaction ("Aprovada").
     */
    public static function isApproved(string $status): bool
    {
        return str_starts_with(Str::lower(Str::ascii(trim($status))), 'aprovad');
    }

    /**
     * Installments of the sale type: "Crédito 5X" → 5, "Crédito à Vista" or "Débito" → 1.
     */
    public static function installments(string $type): int
    {
        return preg_match('/(\d+)\s*x\b/i', $type, $match) === 1 ? max(1, (int) $match[1]) : 1;
    }

    protected static function toUtf8(string $contents): string
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
    }

    /**
     * Position of each known column in the header.
     *
     * @param  list<string|null>  $header
     * @return array<string, int>
     *
     * @throws ValidationException
     */
    protected static function columns(array $header): array
    {
        $normalized = array_map(fn (?string $name): string => Str::lower(Str::ascii(trim((string) $name))), $header);
        $columns = [];

        foreach (self::COLUMNS as $key => $names) {
            foreach ($names as $name) {
                $position = array_search($name, $normalized, true);

                if ($position !== false) {
                    $columns[$key] = $position;

                    break;
                }
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo não é um extrato da Granito: faltam as colunas ID Granito, Data e Hora, Tipo, Cartão, Valor e Status no cabeçalho.',
            ]);
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{external_id: string, occurred_at: CarbonImmutable, type: string, installments: int, brand: string|null, card_bin: string, card_last4: string, authorization_code: string|null, transaction_code: string|null, amount: string, status: string, is_approved: bool}
     *
     * @throws \InvalidArgumentException
     */
    protected static function transaction(array $row): array
    {
        if ($row['external_id'] === '') {
            throw new \InvalidArgumentException('sem o ID Granito.');
        }

        $card = PaymentCard::parseDigits($row['card'])
            ?? throw new \InvalidArgumentException("cartão \"{$row['card']}\" sem os 6 primeiros e 4 últimos dígitos.");

        $amount = SavePlan::normalizePrice($row['amount']);

        if ($amount === null || preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException("valor \"{$row['amount']}\" inválido.");
        }

        return [
            'external_id' => $row['external_id'],
            'occurred_at' => self::date($row['occurred_at']),
            'type' => $row['type'],
            'installments' => self::installments($row['type']),
            'brand' => ($row['brand'] ?? '') !== '' ? $row['brand'] : null,
            'card_bin' => $card[0],
            'card_last4' => $card[1],
            'authorization_code' => ($row['authorization_code'] ?? '') !== '' ? $row['authorization_code'] : null,
            'transaction_code' => ($row['transaction_code'] ?? '') !== '' ? $row['transaction_code'] : null,
            'amount' => bcadd($amount, '0', 2),
            'status' => $row['status'],
            'is_approved' => self::isApproved($row['status']),
        ];
    }

    /**
     * "07/10/2026 - 15:21:18" (or without the dash or the seconds) in the app timezone.
     *
     * @throws \InvalidArgumentException
     */
    protected static function date(string $value): CarbonImmutable
    {
        $value = (string) preg_replace('/\s*-\s*/', ' ', trim($value));

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            if (CarbonImmutable::canBeCreatedFromFormat($value, $format)) {
                $date = CarbonImmutable::createFromFormat("!{$format}", $value);

                if ($date->format($format) === $value) {
                    return $date;
                }
            }
        }

        throw new \InvalidArgumentException("data \"{$value}\" inválida.");
    }
}
