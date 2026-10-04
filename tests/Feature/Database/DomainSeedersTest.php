<?php

use Database\Seeders\FinancialCategorySeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

function seedDomain(): void
{
    test()->seed([LookupSeeder::class, PlanSeeder::class, FinancialCategorySeeder::class]);
}

test('plan seeder creates exactly the two active plans from the spec', function () {
    seedDomain();

    expect(DB::table('plans')->orderBy('id')->get(['name', 'price', 'max_dependents', 'is_active'])->map(fn (object $plan): array => (array) $plan)->all())->toBe([
        ['name' => 'Individual', 'price' => '49.90', 'max_dependents' => 0, 'is_active' => true],
        ['name' => 'Familiar', 'price' => '89.90', 'max_dependents' => 4, 'is_active' => true],
    ]);
});

test('mensalidades is the only system category and it is an income', function () {
    seedDomain();

    $systemCategories = DB::table('financial_categories')
        ->join('entry_types', 'entry_types.id', '=', 'financial_categories.entry_type_id')
        ->where('is_system', true)
        ->get(['financial_categories.name', 'entry_types.slug']);

    expect($systemCategories->map(fn (object $category): array => (array) $category)->all())
        ->toBe([['name' => 'Mensalidades', 'slug' => 'income']]);
});

test('financial categories are seeded with their entry types', function () {
    seedDomain();

    $categories = DB::table('financial_categories')
        ->join('entry_types', 'entry_types.id', '=', 'financial_categories.entry_type_id')
        ->orderBy('financial_categories.id')
        ->pluck('entry_types.slug', 'financial_categories.name')
        ->all();

    expect($categories)->toBe([
        'Mensalidades' => 'income',
        'Outras receitas' => 'income',
        'Aluguel' => 'expense',
        'Salários' => 'expense',
        'Materiais' => 'expense',
        'Marketing' => 'expense',
        'Impostos' => 'expense',
        'Outras despesas' => 'expense',
    ]);
});

test('running the domain seeders again does not duplicate rows', function () {
    seedDomain();
    seedDomain();

    expect(DB::table('plans')->count())->toBe(2)
        ->and(DB::table('financial_categories')->count())->toBe(8);
});
