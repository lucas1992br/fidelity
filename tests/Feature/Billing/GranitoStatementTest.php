<?php

use App\Support\Billing\GranitoStatement;
use Illuminate\Validation\ValidationException;

/**
 * The statement header followed by the given lines, `;` separated.
 *
 * @param  list<string>  $lines
 */
function granitoStatement(array $lines): string
{
    return implode("\r\n", ['ID Granito;Data e Hora;Pdv;Tipo;Bandeira;Cartão;Meio de Captura;Documento Vinculado;Cod. Autorização;Cod. Transação;ID Pedido E-Commerce;Valor;Status', ...$lines]);
}

test('the bank model file is read with the card digits, installments, amount and date', function () {
    $statement = GranitoStatement::parse(file_get_contents(base_path('tests/Fixtures/granito-statement.txt')));

    $first = $statement->transactions[0];
    $installments = $statement->transactions[3];

    expect($statement->errors)->toBe([])
        ->and($statement->transactions)->toHaveCount(5)
        ->and($first['external_id'])->toBe('1604141679')
        ->and($first['occurred_at']->format('Y-m-d H:i:s'))->toBe('2026-10-07 15:21:18')
        ->and($first['card_bin'])->toBe('498407')
        ->and($first['card_last4'])->toBe('9593')
        ->and($first['brand'])->toBe('Visa')
        ->and($first['amount'])->toBe('145.00')
        ->and($first['installments'])->toBe(1)
        ->and($first['authorization_code'])->toBe('269415')
        ->and($first['is_approved'])->toBeTrue()
        ->and($installments['type'])->toBe('Crédito 5X')
        ->and($installments['installments'])->toBe(5)
        ->and($installments['amount'])->toBe('2350.00');
});

test('amounts in reais are read with or without cents', function (string $amount, string $expected) {
    $statement = GranitoStatement::parse(granitoStatement(["1;07/10/2026 - 10:00:00;0003;Crédito à Vista;Visa;498407-9593;POS;;1;1;;{$amount};Aprovada"]));

    expect($statement->transactions[0]['amount'])->toBe($expected);
})->with([
    'whole reais' => ['145', '145.00'],
    'cents' => ['89,90', '89.90'],
    'thousands' => ['1.234,56', '1234.56'],
]);

test('a Windows-1252 file is read', function () {
    $statement = GranitoStatement::parse(mb_convert_encoding(granitoStatement(['1;07/10/2026 - 10:00:00;0003;Crédito 2X;Visa;498407-9593;POS;;1;1;;89,90;Aprovada']), 'Windows-1252', 'UTF-8'));

    expect($statement->transactions[0]['type'])->toBe('Crédito 2X')
        ->and($statement->transactions[0]['installments'])->toBe(2);
});

test('unreadable lines are reported by line number and skipped', function () {
    $statement = GranitoStatement::parse(granitoStatement([
        '1;07/10/2026 - 10:00:00;0003;Crédito à Vista;Visa;498407-9593;POS;;1;1;;89,90;Aprovada',
        '2;07/10/2026 - 10:00:00;0003;Crédito à Vista;Visa;4984079593X;POS;;1;1;;89,90;Aprovada',
        '3;07/10/2026 - 10:00:00;0003;Crédito à Vista;Visa;498407-9593;POS;;1;1;;abc;Aprovada',
        '4;32/10/2026;0003;Crédito à Vista;Visa;498407-9593;POS;;1;1;;89,90;Aprovada',
    ]));

    expect($statement->transactions)->toHaveCount(1)
        ->and($statement->errors)->toBe([
            'Linha 3: cartão "4984079593X" sem os 6 primeiros e 4 últimos dígitos.',
            'Linha 4: valor "abc" inválido.',
            'Linha 5: data "32/10/2026" inválida.',
        ]);
});

test('only approved transactions are flagged as approved', function (string $status, bool $approved) {
    expect(GranitoStatement::isApproved($status))->toBe($approved);
})->with([
    'Aprovada' => ['Aprovada', true],
    'Negada' => ['Negada', false],
    'Cancelada' => ['Cancelada', false],
    'Estornada' => ['Estornada', false],
]);

test('a file without the statement columns is rejected', function () {
    expect(fn () => GranitoStatement::parse("Nome;Valor\r\nMaria;10"))
        ->toThrow(ValidationException::class, 'O arquivo não é um extrato da Granito');
});
