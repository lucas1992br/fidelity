<?php

use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<string>  $columns
 * @return array<string, array<string, mixed>>
 */
function lookupRows(string $table, array $columns): array
{
    return DB::table($table)->orderBy('id')->get(['slug', ...$columns])
        ->mapWithKeys(fn (object $row): array => [$row->slug => collect((array) $row)->except('slug')->all()])
        ->all();
}

test('card statuses follow the schema seeds', function () {
    $this->seed(LookupSeeder::class);

    expect(lookupRows('card_statuses', ['name', 'color', 'is_current', 'is_reusable']))->toBe([
        'in_stock' => ['name' => 'Em estoque', 'color' => 'blue', 'is_current' => false, 'is_reusable' => true],
        'active' => ['name' => 'Ativo', 'color' => 'green', 'is_current' => true, 'is_reusable' => false],
        'suspended' => ['name' => 'Suspenso', 'color' => 'amber', 'is_current' => true, 'is_reusable' => false],
        'cancelled' => ['name' => 'Cancelado', 'color' => 'red', 'is_current' => false, 'is_reusable' => false],
        'lost' => ['name' => 'Extraviado', 'color' => 'gray', 'is_current' => false, 'is_reusable' => false],
        'discarded' => ['name' => 'Inutilizado', 'color' => 'gray', 'is_current' => false, 'is_reusable' => false],
    ]);
});

test('invoice statuses are payable only when open or overdue', function () {
    $this->seed(LookupSeeder::class);

    expect(lookupRows('invoice_statuses', ['name', 'color', 'is_payable']))->toBe([
        'open' => ['name' => 'Em aberto', 'color' => 'blue', 'is_payable' => true],
        'overdue' => ['name' => 'Em atraso', 'color' => 'red', 'is_payable' => true],
        'paid' => ['name' => 'Pago', 'color' => 'green', 'is_payable' => false],
        'cancelled' => ['name' => 'Cancelada', 'color' => 'gray', 'is_payable' => false],
    ]);
});

test('simple lookups follow the schema seeds', function () {
    $this->seed(LookupSeeder::class);

    expect(DB::table('payment_methods')->orderBy('id')->pluck('name', 'slug')->all())
        ->toBe(['pix' => 'PIX', 'cash' => 'Dinheiro', 'card' => 'Cartão', 'boleto' => 'Boleto'])
        ->and(DB::table('kinships')->orderBy('id')->pluck('name', 'slug')->all())
        ->toBe(['spouse' => 'Cônjuge', 'child' => 'Filho(a)', 'father' => 'Pai', 'mother' => 'Mãe', 'sibling' => 'Irmão(ã)', 'other' => 'Outro'])
        ->and(DB::table('entry_types')->orderBy('id')->pluck('name', 'slug')->all())
        ->toBe(['income' => 'Receita', 'expense' => 'Despesa'])
        ->and(lookupRows('entry_statuses', ['name', 'color']))->toBe([
            'pending' => ['name' => 'Pendente', 'color' => 'blue'],
            'paid' => ['name' => 'Pago/Recebido', 'color' => 'green'],
            'cancelled' => ['name' => 'Cancelado', 'color' => 'gray'],
        ]);
});

test('running the seeder twice keeps counts and ids', function () {
    $tables = ['card_statuses', 'invoice_statuses', 'payment_methods', 'kinships', 'entry_types', 'entry_statuses'];

    $this->seed(LookupSeeder::class);
    $idsBefore = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->pluck('id', 'slug')->sort()->all()]);

    $this->seed(LookupSeeder::class);
    $idsAfter = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->pluck('id', 'slug')->sort()->all()]);

    expect($idsAfter->all())->toBe($idsBefore->all())
        ->and($idsAfter->map(fn (array $ids): int => count($ids))->all())->toBe([
            'card_statuses' => 6,
            'invoice_statuses' => 4,
            'payment_methods' => 4,
            'kinships' => 6,
            'entry_types' => 2,
            'entry_statuses' => 3,
        ]);
});
