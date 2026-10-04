<?php

use App\Actions\Cards\BuildCardBatchPreview;
use App\Models\Card;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function preview(array $input): array
{
    return app(BuildCardBatchPreview::class)->handle($input);
}

test('range 000001–000005 generates 5 numbers with leading zeros', function () {
    $result = preview(['mode' => 'range', 'start' => 1, 'end' => 5, 'digits' => 6]);

    expect($result['valid'])->toBe(['000001', '000002', '000003', '000004', '000005'])
        ->and($result['ignored'])->toBe([])
        ->and($result['first_number'])->toBe('000001')
        ->and($result['last_number'])->toBe('000005');
});

test('the prefix is applied to every number of the range', function () {
    $result = preview(['mode' => 'range', 'prefix' => 'A-', 'start' => 9, 'end' => 11, 'digits' => 3]);

    expect($result['valid'])->toBe(['A-009', 'A-010', 'A-011']);
});

test('a start greater than the end is rejected', function () {
    expect(fn () => preview(['mode' => 'range', 'start' => 10, 'end' => 5, 'digits' => 6]))
        ->toThrow(ValidationException::class, 'O número inicial deve ser menor ou igual ao final.');
});

test('digits smaller than the end number length are rejected', function () {
    expect(fn () => preview(['mode' => 'range', 'start' => 1, 'end' => 1000, 'digits' => 3]))
        ->toThrow(ValidationException::class, 'A quantidade de dígitos deve comportar o número final');
});

test('a number already in stock is ignored as duplicated', function () {
    Card::factory()->create(['number' => '000003']);

    $result = preview(['mode' => 'range', 'start' => 1, 'end' => 4, 'digits' => 6]);

    expect($result['valid'])->toBe(['000001', '000002', '000004'])
        ->and($result['ignored'])->toBe([['number' => '000003', 'reason' => 'Já cadastrado no estoque']]);
});

test('a number repeated in the list itself is ignored', function () {
    $result = preview(['mode' => 'list', 'numbers' => "000010\n000011\n000010\n"]);

    expect($result['valid'])->toBe(['000010', '000011'])
        ->and($result['ignored'])->toBe([['number' => '000010', 'reason' => 'Repetido na lista']]);
});

test('with the pattern ^\d{6}$, 12345 is ignored', function () {
    settings(['card_number_pattern' => '^\d{6}$']);

    $result = preview(['mode' => 'list', 'numbers' => "123456\n12345"]);

    expect($result['valid'])->toBe(['123456'])
        ->and($result['ignored'])->toBe([['number' => '12345', 'reason' => 'Fora do padrão configurado']]);
});

test('a delimited pattern from the settings screen is applied as is', function () {
    settings(['card_number_pattern' => '/^\d{6}$/']);

    expect(preview(['mode' => 'list', 'numbers' => "123456\nAB1234"])['ignored'])
        ->toBe([['number' => 'AB1234', 'reason' => 'Fora do padrão configurado']]);
});

test('numbers are trimmed at the ends only and blank lines are skipped silently', function () {
    $result = preview(['mode' => 'list', 'numbers' => "  000123  \r\n\n   \n\t0 01-A \n"]);

    expect($result['valid'])->toBe(['000123', '0 01-A'])
        ->and($result['ignored'])->toBe([]);
});

test('csv mode takes the first column and skips the header', function () {
    $result = preview(['mode' => 'csv', 'numbers' => "\u{FEFF}numero;lote\n000201;set\n 000202 ;set\n\n000203"]);

    expect($result['valid'])->toBe(['000201', '000202', '000203']);
});

test('an empty list is rejected', function () {
    expect(fn () => preview(['mode' => 'list', 'numbers' => "\n  \n"]))
        ->toThrow(ValidationException::class, 'Informe ao menos um número de cartão.');
});
