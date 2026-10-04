<?php

use App\Support\Format;
use Carbon\CarbonImmutable;

test('money formats thousands and cents in Brazilian Real', function (float|int|string $value, string $expected) {
    expect(Format::money($value))->toBe($expected);
})->with([
    'decimal string' => ['1234.56', 'R$ 1.234,56'],
    'millions' => ['1234567.89', 'R$ 1.234.567,89'],
    'below one' => ['0.05', 'R$ 0,05'],
    'integer' => [89, 'R$ 89,00'],
    'float' => [1234.5, 'R$ 1.234,50'],
    'negative' => ['-45.90', 'R$ -45,90'],
]);

test('money keeps decimal strings exact beyond float precision', function () {
    expect(Format::money('90071992547409.93'))->toBe('R$ 90.071.992.547.409,93');
});

test('maskCpf hides the first and last digits from digits or a formatted CPF', function (string $cpf) {
    expect(Format::maskCpf($cpf))->toBe('***.456.789-**');
})->with(['12345678909', '123.456.789-09']);

test('cpf formats eleven digits', function () {
    expect(Format::cpf('12345678909'))->toBe('123.456.789-09');
});

test('date formats as d/m/Y and shows a dash when empty', function () {
    expect(Format::date(CarbonImmutable::create(2026, 10, 4)))->toBe('04/10/2026')
        ->and(Format::date(null))->toBe('—');
});

test('phone formats mobile and landline numbers', function () {
    expect(Format::phone('11987654321'))->toBe('(11) 98765-4321')
        ->and(Format::phone('1134567890'))->toBe('(11) 3456-7890');
});

test('whatsappUrl links to wa.me with the country code and digits only', function () {
    expect(Format::whatsappUrl('(11) 98765-4321'))->toBe('https://wa.me/5511987654321');
});

test('zip formats a CEP', function () {
    expect(Format::zip('01234567'))->toBe('01234-567');
});

test('initials skip short particles', function () {
    expect(Format::initials('Maria da Silva'))->toBe('MS');
});

test('moneyInWords spells out reais and centavos', function () {
    expect(Format::moneyInWords('89.90'))->toBe('oitenta e nove reais e noventa centavos')
        ->and(Format::moneyInWords('1.00'))->toBe('um real')
        ->and(Format::moneyInWords('0.01'))->toBe('um centavo')
        ->and(Format::moneyInWords('1289.05'))->toBe('mil duzentos e oitenta e nove reais e cinco centavos');
});
