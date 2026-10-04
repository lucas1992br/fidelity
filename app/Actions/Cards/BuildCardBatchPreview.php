<?php

namespace App\Actions\Cards;

use App\Models\Card;
use App\Settings\ClinicSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Split the numbers of a new card batch into valid and ignored (with the
 * reason), without writing anything. Numbers come from a range (prefix +
 * start..end padded to N digits) or from a pasted list / CSV, where each
 * number is kept exactly as printed apart from trimming the ends.
 *
 * @phpstan-type IgnoredNumber array{number: string, reason: string}
 * @phpstan-type Preview array{mode: string, valid: list<string>, ignored: list<IgnoredNumber>, first_number: string|null, last_number: string|null}
 */
class BuildCardBatchPreview
{
    public const string MODE_RANGE = 'range';

    public const string MODE_LIST = 'list';

    public const string MODE_CSV = 'csv';

    public const string REASON_DUPLICATED = 'Já cadastrado no estoque';

    public const string REASON_REPEATED = 'Repetido na lista';

    public const string REASON_PATTERN = 'Fora do padrão configurado';

    /**
     * Largest number of cards accepted in a single batch.
     */
    public const int MAX_CARDS = 10000;

    public function __construct(protected ClinicSettings $settings) {}

    /**
     * @param  array{mode?: string, prefix?: string|null, start?: int|string|null, end?: int|string|null, digits?: int|string|null, numbers?: string|null}  $input
     * @return Preview
     *
     * @throws ValidationException
     */
    public function handle(array $input): array
    {
        $mode = $input['mode'] ?? self::MODE_RANGE;

        $numbers = match ($mode) {
            self::MODE_RANGE => $this->rangeNumbers($input),
            self::MODE_LIST => $this->listNumbers((string) ($input['numbers'] ?? '')),
            self::MODE_CSV => $this->csvNumbers((string) ($input['numbers'] ?? '')),
            default => throw ValidationException::withMessages(['mode' => 'Modo de cadastro inválido.']),
        };

        if ($numbers === []) {
            throw ValidationException::withMessages(['numbers' => 'Informe ao menos um número de cartão.']);
        }

        if (count($numbers) > self::MAX_CARDS) {
            throw ValidationException::withMessages(['numbers' => 'Um lote pode ter no máximo '.number_format(self::MAX_CARDS, 0, ',', '.').' cartões.']);
        }

        return [
            'mode' => $mode,
            ...$this->classify($numbers),
            'first_number' => $numbers[0],
            'last_number' => $numbers[array_key_last($numbers)],
        ];
    }

    /**
     * `prefix . str_pad(n, digits, '0', STR_PAD_LEFT)` for every n in start..end.
     *
     * @param  array{prefix?: string|null, start?: int|string|null, end?: int|string|null, digits?: int|string|null}  $input
     * @return list<string>
     *
     * @throws ValidationException
     */
    protected function rangeNumbers(array $input): array
    {
        $validated = Validator::make($input, [
            'prefix' => ['nullable', 'string', 'max:50'],
            'start' => ['required', 'integer', 'min:0'],
            'end' => ['required', 'integer', 'min:0'],
            'digits' => ['required', 'integer', 'min:1', 'max:30'],
        ], [], [
            'prefix' => 'prefixo',
            'start' => 'número inicial',
            'end' => 'número final',
            'digits' => 'quantidade de dígitos',
        ])->validate();

        $start = (int) $validated['start'];
        $end = (int) $validated['end'];
        $digits = (int) $validated['digits'];
        $prefix = trim((string) ($validated['prefix'] ?? ''));

        if ($start > $end) {
            throw ValidationException::withMessages(['start' => 'O número inicial deve ser menor ou igual ao final.']);
        }

        if ($digits < strlen((string) $end)) {
            throw ValidationException::withMessages(['digits' => 'A quantidade de dígitos deve comportar o número final ('.strlen((string) $end).' dígitos ou mais).']);
        }

        if ($end - $start + 1 > self::MAX_CARDS) {
            throw ValidationException::withMessages(['end' => 'Uma faixa pode ter no máximo '.number_format(self::MAX_CARDS, 0, ',', '.').' cartões.']);
        }

        return array_map(
            fn (int $number): string => $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT),
            range($start, $end),
        );
    }

    /**
     * One number per line; ends trimmed, blank lines skipped.
     *
     * @return list<string>
     */
    protected function listNumbers(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', self::withoutBom($text)) ?: []),
            fn (string $number): bool => $number !== '',
        ));
    }

    /**
     * First column of each CSV row (`,` or `;`); a leading header row is skipped.
     *
     * @return list<string>
     */
    protected function csvNumbers(string $text): array
    {
        $numbers = [];

        foreach (preg_split('/\R/u', self::withoutBom($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
            $number = trim((string) (str_getcsv($line, $delimiter, '"', '')[0] ?? ''));

            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        if ($numbers !== [] && preg_match('/^(n[uú]mero|numero|number|cart[aã]o)/iu', $numbers[0])) {
            array_shift($numbers);
        }

        return $numbers;
    }

    /**
     * @param  list<string>  $numbers
     * @return array{valid: list<string>, ignored: list<IgnoredNumber>}
     */
    protected function classify(array $numbers): array
    {
        $existing = [];

        foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
            $existing += array_fill_keys(Card::query()->whereIn('number', $chunk)->pluck('number')->all(), true);
        }

        $pattern = $this->pattern();
        $seen = [];
        $valid = [];
        $ignored = [];

        foreach ($numbers as $number) {
            $reason = match (true) {
                isset($seen[$number]) => self::REASON_REPEATED,
                mb_strlen($number) > 255, $pattern !== null && preg_match($pattern, $number) !== 1 => self::REASON_PATTERN,
                isset($existing[$number]) => self::REASON_DUPLICATED,
                default => null,
            };

            $seen[$number] = true;

            if ($reason === null) {
                $valid[] = $number;
            } else {
                $ignored[] = ['number' => $number, 'reason' => $reason];
            }
        }

        return ['valid' => $valid, 'ignored' => $ignored];
    }

    /**
     * The configured `card_number_pattern` as a PCRE; a bare expression such as
     * `^\d{6}$` gets delimiters. An empty or unusable pattern disables the check.
     */
    protected function pattern(): ?string
    {
        $pattern = trim((string) $this->settings->card_number_pattern);

        if ($pattern === '') {
            return null;
        }

        if (@preg_match($pattern, '') !== false) {
            return $pattern;
        }

        $wrapped = '~'.str_replace('~', '\~', $pattern).'~u';

        return @preg_match($wrapped, '') !== false ? $wrapped : null;
    }

    protected static function withoutBom(string $text): string
    {
        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }
}
